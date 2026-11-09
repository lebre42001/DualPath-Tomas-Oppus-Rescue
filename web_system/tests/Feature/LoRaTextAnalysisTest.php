<?php

namespace Tests\Feature;

use App\Events\IncidentFeedUpdated;
use App\Jobs\AnalyzeLoraDescription;
use App\Models\IncidentReport;
use App\Models\User;
use App\Services\AiTextSeverityClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LoRaTextAnalysisTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config([
            'services.lora_ingest.token' => 'test-lora-token',
            'services.ai_text.mode' => 'MODEL_ADVISORY',
            'services.ai_text.enabled' => true,
            'services.ai_text.allow_experimental' => true,
            'services.ai_text.url' => 'http://text-ai.test',
        ]);
        Event::fake([IncidentFeedUpdated::class]);
        Http::preventStrayRequests();
    }

    public function test_gateway_receipt_is_saved_and_analysis_is_deferred_until_after_response(): void
    {
        Bus::fake([AnalyzeLoraDescription::class]);
        $this->ingest()->assertCreated()->assertJsonPath('data.severity', null);
        $report = IncidentReport::query()->sole();
        $this->assertSame('pending', $report->ai_status);
        $this->assertTrue($report->ai_review_required);
        $this->assertNull($report->operational_severity);
        Bus::assertDispatchedAfterResponse(AnalyzeLoraDescription::class,
            fn ($job) => $job->reportId === $report->id);
        Http::assertNothingSent();
    }

    public function test_lora_description_uses_the_online_text_client_and_requires_admin_confirmation(): void
    {
        Http::fake(['text-ai.test/predict/text' => Http::response($this->prediction())]);
        $this->ingest()->assertCreated()->assertJsonPath('data.severity', null);
        $report = IncidentReport::query()->sole();
        $expected = app(AiTextSeverityClient::class)->analyzeForReport($this->payload()['description']);
        $this->assertSame($expected['severity'], $report->severity);
        $this->assertSame($expected['source'], $report->ai_source);
        $this->assertSame($expected['probabilities'], $report->ai_probabilities);
        $this->assertSame('complete', $report->ai_status);
        $this->assertSame('fixture-text-model', $report->ai_model_name);
        $this->assertSame('v2', $report->ai_model_version);
        $this->assertSame(91, $report->ai_confidence);
        $this->assertTrue($report->ai_review_required);
        $this->assertNull($report->operational_severity);
        $this->assertNotNull($report->ai_processed_at);
        Http::assertSent(fn ($request) => $request->url() === 'http://text-ai.test/predict/text'
            && $request->data() === ['description' => $this->payload()['description'], 'incident_type' => 'road_accident']);
        Event::assertDispatched(IncidentFeedUpdated::class, fn ($event) => $event->action === 'ai_suggestion');
        $analysisLog = collect($report->coordination_log)->firstWhere('trigger', 'lora_description_received');
        $this->assertSame('System', $analysisLog['actor_role']);
        $this->assertSame('Critical', $analysisLog['suggested_severity']);
        // Automatic inference is report history, not an admin action.
        $this->assertDatabaseCount('incident_report_audits', 0);

        $admin = $this->admin();
        $url = route('lora-reports.index');
        $this->actingAs($admin)->get($url)->assertOk()
            ->assertSee('Description analysis and severity review')->assertSee('data-lora-description-analysis', false)
            ->assertSee('AI suggestion:')->assertSee('91.0%')
            ->assertSee(route('reports.severity-review', $report), false);
        $this->from($url)->post(route('reports.severity-review', $report), [
            'severity_review_report_id' => $report->id,
            'severity_review_label' => 'serious',
            'severity_review_notes' => 'Admin clarified that the injured person is conscious.',
        ])->assertRedirect($url)->assertSessionHasNoErrors();
        $report->refresh();
        $this->assertSame('Serious', $report->operational_severity);
        $this->assertSame('corrected_ai', $report->description_severity_review['review_method']);
        $this->assertSame('critical', $report->description_severity_review['ai_suggestion']['label']);
        $this->assertSame($this->prediction()['probabilities'], $report->description_severity_review['ai_suggestion']['model_probabilities']);
        $this->assertSame('received', $report->status);
        $this->assertNull($report->lora_ack_queued_at);
        $this->assertSame('unverified', $report->lora_verification);
        $this->get($url)->assertOk()->assertSee('data-report-open-on-load', false)
            ->assertSee('Reviewed by admin:')->assertSee('Original trained-model scores');
    }

    public function test_duplicate_sos_does_not_reanalyze_or_overwrite_review_or_repeat_the_arrival(): void
    {
        Http::fake(['text-ai.test/predict/text' => Http::response($this->prediction())]);
        $this->ingest()->assertCreated();
        $report = IncidentReport::query()->sole();
        $this->actingAs($this->admin())->post(route('reports.severity-review', $report), [
            'severity_review_label' => 'minor', 'severity_review_notes' => 'Verified minor injuries.',
        ])->assertRedirect();
        $this->ingest()->assertOk()->assertJsonPath('duplicate', true);
        $this->assertDatabaseCount('incident_reports', 1);
        $this->assertSame('Minor', $report->fresh()->operational_severity);
        Http::assertSentCount(1);
        $this->assertCount(1, Event::dispatched(IncidentFeedUpdated::class,
            fn ($event) => $event->action === 'created'));
    }

    public function test_ai_outage_still_accepts_lora_and_allows_admin_review_and_retry(): void
    {
        Http::fake(['text-ai.test/predict/text' => Http::failedConnection()]);
        $this->ingest()->assertCreated();
        $report = IncidentReport::query()->sole();
        $this->assertSame('needs_review', $report->ai_status);
        $this->assertSame('text_review', $report->ai_source);
        $this->assertTrue($report->ai_review_required);
        $this->assertNull($report->ai_confidence);
        $this->assertNull($report->ai_model_name);
        $this->actingAs($this->admin())->get(route('lora-reports.index'))
            ->assertOk()->assertSee('Analyze description with AI')->assertSee('Save admin assessment');
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['text-ai.test/predict/text' => Http::response($this->prediction())]);
        $this->postJson(route('reports.ai-suggestion', $report))->assertOk()
            ->assertJsonPath('top_category.label', 'Critical');
        $this->assertSame('Critical', $report->fresh()->ai_suggested_severity);
    }

    public function test_manual_only_mode_and_disabled_ai_make_no_model_requests(): void
    {
        config(['services.ai_text.mode' => 'MANUAL_ONLY']);
        $this->ingest()->assertCreated();
        $report = IncidentReport::query()->sole();
        $this->assertNull($report->severity);
        $this->assertSame('manual_only', $report->ai_source);
        $this->assertTrue($report->ai_review_required);
        config(['services.ai_text.mode' => 'MODEL_ADVISORY', 'services.ai_text.enabled' => false]);
        $this->ingest(['sequence' => 'NODE_9-2'])->assertCreated();
        $this->assertSame('needs_review', IncidentReport::query()->latest('id')->first()->ai_status);
        Http::assertNothingSent();
    }

    public function test_legacy_alert_without_description_does_not_analyze_generated_transport_text(): void
    {
        $this->ingest(['description' => null])->assertCreated();
        $report = IncidentReport::query()->sole();
        $this->assertSame('needs_review', $report->ai_status);
        $this->assertTrue($report->ai_review_required);
        $this->assertStringContainsString('No optional description.', $report->description);
        Http::assertNothingSent();
    }

    public function test_model_abstention_and_phrase_rules_remain_reviewable_like_online(): void
    {
        $prediction = $this->prediction();
        $abstention = [...$prediction, 'severity' => null, 'predicted_label' => 'needs_review',
            'status' => 'needs_review', 'probabilities' => ['minor' => .02, 'serious' => .05, 'critical' => .02, 'needs_review' => .91]];
        $rules = [...$prediction, 'assessment_method' => 'text_rules', 'matched_cues' => ['unresponsive'],
            'confidence' => null, 'probabilities' => null, 'model_assessment' => $prediction];
        Http::fakeSequence('text-ai.test/predict/text')->push($abstention)->push($rules);
        $this->ingest()->assertCreated();
        $report = IncidentReport::query()->sole();
        $this->assertSame('needs_review', $report->ai_status);
        $this->assertTrue($report->ai_review_required);
        $this->assertSame('Needs review', $report->ai_top_category['label']);

        $this->ingest(['sequence' => 'NODE_9-2'])->assertCreated();
        $report = IncidentReport::query()->latest('id')->firstOrFail();
        $this->assertSame('text_rule_advisory', $report->ai_source);
        $this->assertNull($report->ai_confidence);
        $this->assertTrue($report->ai_review_required);
        $this->assertSame($prediction['probabilities'], $report->ai_probabilities);
    }

    public function test_slow_analysis_cannot_overwrite_a_concurrent_admin_review(): void
    {
        Http::fake(function () {
            IncidentReport::query()->sole()->update([
                'severity' => 'Minor', 'ai_source' => 'human_responder', 'ai_status' => 'complete',
                'ai_review_required' => false, 'response_notes' => 'Admin response already saved.',
                'lora_ack_queued_at' => now(),
            ]);

            return Http::response($this->prediction());
        });
        $this->ingest()->assertCreated();
        $report = IncidentReport::query()->sole();
        $this->assertSame('Minor', $report->operational_severity);
        $this->assertSame('Admin response already saved.', $report->response_notes);
        $this->assertNotNull($report->lora_ack_queued_at);
        Event::assertNotDispatched(IncidentFeedUpdated::class, fn ($event) => $event->action === 'ai_suggestion');
    }

    public function test_older_lora_reports_can_be_analyzed_manually_and_only_admins_can_review(): void
    {
        config(['services.ai_text.enabled' => false]);
        $this->ingest()->assertCreated();
        $report = IncidentReport::query()->sole();
        $report->update(['ai_source' => null, 'ai_status' => 'not_applicable']);
        config(['services.ai_text.enabled' => true]);
        Http::fake(['text-ai.test/predict/text' => Http::response($this->prediction())]);
        $this->actingAs($this->admin())->get(route('lora-reports.index'))->assertOk()
            ->assertSee('Analyze description with AI');
        $this->postJson(route('reports.ai-suggestion', $report))->assertOk();
        $this->assertSame('Critical', $report->fresh()->ai_suggested_severity);
        foreach (['civilian', 'responder'] as $role) {
            $user = User::factory()->create(['role' => $role, 'is_admin' => false, 'account_verified_at' => now()]);
            $this->actingAs($user)->postJson(route('reports.severity-review', $report), [
                'severity_review_label' => 'minor', 'severity_review_notes' => 'Unauthorized change.',
            ])->assertForbidden();
        }
        $this->assertSame('Critical', $report->fresh()->severity);
    }

    public function test_authenticated_v2_report_keeps_landmark_and_fatality_flag_separate_from_ai_input(): void
    {
        $description = 'Naay patay human sa bangga sa motor.';
        Http::fake(['text-ai.test/predict/text' => Http::response($this->prediction())]);
        $this->ingest(['description' => $description, 'radio_auth' => 'hmac-sha256-v2',
            'created_at' => '1793880000'])->assertCreated();
        $report = IncidentReport::query()->sole();
        $this->assertTrue($report->reported_fatality_flag);
        $this->assertSame('Near school', $report->lora_incident_landmark);
        $this->assertSame('hmac-sha256-v2', $report->lora_radio_auth);
        $this->assertNull($report->lora_ack_queued_at);
        Http::assertSent(fn ($request) => $request->data() === ['description' => $description, 'incident_type' => 'road_accident']);
    }

    private function ingest(array $overrides = [])
    {
        return $this->postJson('/api/v1/lora/alerts', array_replace($this->payload(), $overrides), [
            'X-STITCH-LORA-TOKEN' => 'test-lora-token',
        ]);
    }

    private function payload(): array
    {
        return ['sender_id' => 'NODE_9', 'sequence' => 'NODE_9-1', 'gateway_id' => 'NODE_1',
            'incident_type' => 'Road Accident', 'severity' => 'Minor',
            'description' => 'Motorcycle crash, one person is unresponsive.', 'incident_landmark' => 'Near school'];
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    }

    private function prediction(): array
    {
        return ['assessment_method' => 'trained_model', 'severity' => 'critical', 'predicted_label' => 'critical', 'confidence' => .91,
            'probabilities' => ['minor' => .02, 'serious' => .05, 'critical' => .91, 'needs_review' => .02],
            'responder_review_required' => true, 'status' => 'complete', 'summary' => 'Description-only test assessment.',
            'model_name' => 'fixture-text-model', 'model_version' => 'v2', 'deployment_status' => 'experimental'];
    }
}
