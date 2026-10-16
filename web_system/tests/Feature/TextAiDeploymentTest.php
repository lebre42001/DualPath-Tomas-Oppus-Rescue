<?php

namespace Tests\Feature;

use App\Events\IncidentFeedUpdated;
use App\Models\IncidentReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TextAiDeploymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['services.ai_text.mode' => 'MODEL_ADVISORY', 'services.ai_text.enabled' => true,
            'services.ai_text.allow_experimental' => true, 'services.ai_text.url' => 'http://text-ai.test']);
        Event::fake([IncidentFeedUpdated::class]);
        Http::preventStrayRequests();
    }

    public function test_health_checks_the_connected_model_and_exposes_only_readiness(): void
    {
        Http::fake(['text-ai.test/ready/text' => Http::response(['status' => 'ready',
            'deployment_status' => 'experimental', 'model_name' => 'text-test', 'model_version' => 'v1', 'private_path' => 'hidden'])]);
        $this->getJson('/system/ai-status')->assertOk()->assertJsonPath('status', 'ready')
            ->assertJsonPath('admin_confirmation_required', true)->assertJsonMissingPath('private_path');
        config(['services.ai_text.allow_experimental' => false]);
        $this->getJson('/system/ai-status')->assertStatus(503)->assertJsonPath('status', 'unavailable');
        $this->getJson('/system/ai-status')->assertStatus(503)->assertJsonPath('connection_status', 'experimental_not_allowed');
    }

    public function test_disabled_and_unavailable_health_never_report_a_ready_model(): void
    {
        config(['services.ai_text.mode' => 'MANUAL_ONLY']);
        $this->getJson('/system/ai-status')->assertOk()->assertJsonPath('status', 'disabled');
        Http::assertNothingSent();
        config(['services.ai_text.mode' => 'MODEL_ADVISORY']);
        Http::fake(['text-ai.test/ready/text' => Http::response(['private_error' => 'secret'], 503)]);
        $this->getJson('/system/ai-status')->assertStatus(503)->assertDontSee('secret')
            ->assertJsonPath('connection_status', 'service_not_ready')->assertDontSee('text-ai.test');
    }

    public function test_existing_pending_report_can_receive_a_suggestion_then_admin_confirms_it(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'role' => 'admin']);
        $report = $this->report();
        Http::fake(['text-ai.test/predict/text' => Http::response($this->prediction())]);
        $this->actingAs($admin)->get(route('reports.show', $report))->assertOk()->assertSee('Analyze description with AI');
        $this->post(route('reports.ai-suggestion', $report))->assertRedirect();
        $report->refresh();
        $this->assertSame('Critical', $report->ai_suggested_severity);
        $this->assertNull($report->operational_severity);
        $this->assertSame('pending', $report->status);
        $this->assertSame('Original message.', $report->response_notes);
        Http::assertSent(fn ($request) => $request->data() === ['description' => $report->description]);
        $this->post(route('reports.severity-review', $report), ['severity_review_label' => 'critical',
            'severity_review_notes' => 'The description reports immediate danger.'])->assertRedirect();
        $report->refresh();
        $this->assertSame('Critical', $report->operational_severity);
        $this->assertSame('confirmed_ai', $report->description_severity_review['review_method']);
    }

    public function test_failed_suggestion_preserves_the_existing_report(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'role' => 'admin']);
        $report = $this->report();
        Http::fake(['text-ai.test/predict/text' => Http::response([], 503)]);
        $this->actingAs($admin)->post(route('reports.ai-suggestion', $report))->assertRedirect();
        $this->assertSame('manual_only', $report->fresh()->ai_source);
        $this->assertNull($report->fresh()->severity);
    }

    public function test_model_abstention_records_analysis_without_inventing_a_severity(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'role' => 'admin']);
        $report = $this->report();
        $payload = $this->prediction();
        $payload['severity'] = null;
        $payload['predicted_label'] = 'needs_review';
        $payload['status'] = 'needs_review';
        $payload['probabilities'] = ['minor' => 0.02, 'serious' => 0.02, 'critical' => 0.02, 'needs_review' => 0.94];
        Http::fake(['text-ai.test/predict/text' => Http::response($payload)]);
        $this->actingAs($admin)->post(route('reports.ai-suggestion', $report))->assertRedirect();
        $report->refresh();
        $this->assertSame('text_review', $report->ai_source);
        $this->assertNull($report->severity);
        $this->assertNull($report->operational_severity);
        $this->assertTrue($report->ai_review_required);
        $this->assertSame('fixture-text-model', $report->ai_model_name);
        $this->assertSame($payload['probabilities'], $report->ai_probabilities);
        $this->assertSame('AI analyzed text; no severity suggested', $report->ai_score_display);
        $this->get(route('reports.show', $report))->assertOk()
            ->assertSee('Highest model category:')->assertSee('Needs review (94.0%)')
            ->assertSee('Analyze description again')->assertDontSee('while text AI is unavailable');
        $this->post(route('reports.severity-review', $report), ['severity_review_label' => 'needs_review',
            'severity_review_notes' => 'The reported condition is unclear.'])->assertRedirect();
        $report->refresh();
        $this->assertSame('needs_review', $report->description_severity_review['ai_suggestion']['label']);
        $this->assertSame($payload['probabilities'], $report->description_severity_review['ai_suggestion']['model_probabilities']);
        $this->get(route('reports.show', $report))->assertOk()->assertSee('Original trained-model scores')->assertSee('94.0%');
    }

    public function test_suggestions_cannot_overwrite_human_decisions_or_run_for_non_admins(): void
    {
        $report = $this->report();
        foreach (['civilian', 'responder'] as $role) {
            $user = User::factory()->create(['is_admin' => false, 'role' => $role, 'account_verified_at' => now()]);
            $this->actingAs($user)->postJson(route('reports.ai-suggestion', $report))->assertForbidden();
        }
        $admin = User::factory()->create(['is_admin' => true, 'role' => 'admin']);
        $report->update(['ai_source' => 'human_responder', 'severity' => 'Serious']);
        $this->actingAs($admin)->post(route('reports.ai-suggestion', $report))->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_a_concurrent_human_review_wins_over_a_slow_model_request(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'role' => 'admin']);
        $report = $this->report();
        Http::fake(function () use ($report) {
            $report->update(['ai_source' => 'human_responder', 'severity' => 'Serious']);
            return Http::response($this->prediction());
        });
        $this->actingAs($admin)->post(route('reports.ai-suggestion', $report))->assertStatus(409);
        $this->assertSame('Serious', $report->fresh()->severity);
    }

    public function test_word_phrase_suggestions_have_no_fake_score_and_require_admin_confirmation(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'role' => 'admin']);
        $report = $this->report();
        $payload = $this->prediction();
        $payload['assessment_method'] = 'text_rules';
        $payload['matched_cues'] = ['unresponsive'];
        $payload['confidence'] = null;
        $payload['probabilities'] = null;
        $payload['model_name'] = 'DualPath Trilingual Severity Rules';
        $payload['summary'] = 'Experimental word/phrase rules; no model score. Reported phrase: unresponsive.';
        $payload['model_assessment'] = [...$this->prediction(), 'assessment_method' => 'trained_model'];
        Http::fake(['text-ai.test/predict/text' => Http::response($payload)]);
        $this->actingAs($admin)->post(route('reports.ai-suggestion', $report))->assertRedirect();
        $report->refresh();
        $this->assertSame('text_rule_advisory', $report->ai_source);
        $this->assertSame('Critical', $report->ai_suggested_severity);
        $this->assertNull($report->operational_severity);
        $this->assertNull($report->ai_confidence);
        $this->assertSame($payload['model_assessment']['probabilities'], $report->ai_probabilities);
        $this->assertFalse(IncidentReport::query()->operationallyAssessed()->whereKey($report->id)->exists());
        $this->assertTrue(IncidentReport::query()->needsResponderReview()->whereKey($report->id)->exists());
        $this->get(route('reports.show', $report))->assertOk()
            ->assertSee('Word/phrase suggestion; no rule confidence score')
            ->assertSee('Suggestion method: multilingual word/phrase rules.')->assertSee('94.0%');
        $this->post(route('reports.severity-review', $report), ['severity_review_label' => 'critical',
            'severity_review_notes' => 'Admin reviewed the reported condition.'])->assertRedirect();
        $report->refresh();
        $this->assertSame('Critical', $report->operational_severity);
        $this->assertSame('text_rule_advisory', $report->description_severity_review['ai_suggestion']['source']);
        $this->assertNull($report->description_severity_review['ai_suggestion']['model_score']);
        $this->assertSame('confirmed_ai', $report->description_severity_review['review_method']);
        $this->assertSame($payload['model_assessment']['probabilities'], $report->description_severity_review['ai_suggestion']['model_probabilities']);
        $this->get(route('reports.show', $report))->assertOk()->assertSee('Original AI suggestion')
            ->assertSee('Original trained-model scores')->assertSee('Reviewed by admin')->assertSee('94.0%');
        $this->post(route('reports.severity-review', $report), ['severity_review_label' => 'serious',
            'severity_review_notes' => 'Updated human assessment.'])->assertRedirect();
        $report->refresh();
        $this->assertSame('Serious', $report->operational_severity);
        $this->assertSame('critical', $report->description_severity_review['ai_suggestion']['label']);
        $this->assertSame(0.94, $report->description_severity_review['ai_suggestion']['model_probabilities']['critical']);
    }

    public function test_rule_payloads_cannot_claim_model_confidence_or_validated_status(): void
    {
        $payload = $this->prediction();
        $payload['assessment_method'] = 'text_rules';
        $payload['matched_cues'] = ['unresponsive'];
        $client = app(\App\Services\AiTextSeverityClient::class);
        Http::fake(['text-ai.test/predict/text' => Http::response($payload)]);
        $this->assertSame('text_review', $client->analyze('A person is unresponsive.')['source']);
        $payload['confidence'] = null;
        $payload['probabilities'] = null;
        $payload['deployment_status'] = 'validated';
        Http::fake(['text-ai.test/predict/text' => Http::response($payload)]);
        $this->assertSame('text_review', $client->analyze('A person is unresponsive.')['source']);
    }

    public function test_admin_lab_accepts_unscored_rule_results_without_creating_reports(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'role' => 'admin']);
        $payload = $this->prediction();
        $payload['assessment_method'] = 'text_rules';
        $payload['matched_cues'] = ['unresponsive'];
        $payload['confidence'] = null;
        $payload['probabilities'] = null;
        config(['services.ai_text.test_url' => 'http://text-ai.test']);
        Http::fake(['text-ai.test/predict/text' => Http::response($payload)]);
        $this->actingAs($admin)->post(route('admin.text-ai-test.predict'), ['description' => 'A person is unresponsive.'])
            ->assertOk()->assertSee('Word/phrase suggestion')->assertDontSee('Model scores (uncalibrated)');
        $this->assertSame(0, IncidentReport::count());
    }

    private function report(): IncidentReport
    {
        return IncidentReport::create(['report_code' => 'TEXT-'.uniqid(), 'reporter_name' => 'Test',
            'incident_type' => 'Road Accident', 'location_text' => 'Test location',
            'description' => 'A driver is unresponsive after a collision.', 'status' => 'pending',
            'response_notes' => 'Original message.', 'ai_source' => 'manual_only', 'severity' => null,
            'transmission_type' => 'Online', 'submitted_at' => now()]);
    }

    public function test_ajax_analysis_shows_the_actual_top_category_for_every_report_without_finalizing_it(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'role' => 'admin']);
        $cases = [
            'Minor' => ['minor' => 0.46, 'serious' => 0.29, 'critical' => 0.15, 'needs_review' => 0.10],
            'Serious' => ['minor' => 0.15, 'serious' => 0.50, 'critical' => 0.20, 'needs_review' => 0.15],
            'Critical' => ['minor' => 0.062539, 'serious' => 0.037538, 'critical' => 0.632553, 'needs_review' => 0.267371],
            'Needs review' => ['minor' => 0.20, 'serious' => 0.16, 'critical' => 0.10, 'needs_review' => 0.54],
        ];
        $payload = [];
        Http::fake(function () use (&$payload) { return Http::response($payload); });
        foreach ($cases as $label => $probabilities) {
            $report = $this->report();
            $payload = [...$this->prediction(), 'severity' => null, 'predicted_label' => array_search(max($probabilities), $probabilities),
                'status' => 'needs_review', 'confidence' => max($probabilities), 'probabilities' => $probabilities];
            $response = $this->actingAs($admin)->postJson(route('reports.ai-suggestion', $report))
                ->assertOk()->assertJsonPath('report_id', $report->id)->assertJsonPath('top_category.label', $label)
                ->assertJsonPath('top_category.score', fn ($score) => (float) $score === round(max($probabilities) * 100, 1));
            $this->assertStringContainsString('AI top category', $response->json('analysis_html'));
            $this->assertStringContainsString(number_format(max($probabilities) * 100, 1).'%', $response->json('analysis_html'));
            $this->assertStringContainsString('data-ai-analyze-form', $response->json('review_html'));
            $this->assertNull($report->fresh()->operational_severity);
            $this->assertSame($probabilities, $report->fresh()->ai_probabilities);
            $this->get(route('reports.index'))->assertOk()->assertSee('AI top category')->assertSee($label);
            $this->get(route('monitoring'))->assertOk()->assertSee('AI top category');
            $this->get(route('reports.show', $report))->assertOk()->assertSee('AI top category');
        }
    }

    public function test_ajax_unavailable_service_keeps_existing_scores_and_returns_an_inline_error(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'role' => 'admin']);
        $report = $this->report();
        $report->update(['ai_source' => 'text_model_experimental', 'severity' => 'Critical',
            'ai_probabilities' => $this->prediction()['probabilities']]);
        Http::fake(['text-ai.test/predict/text' => Http::response([], 503)]);
        $this->actingAs($admin)->postJson(route('reports.ai-suggestion', $report))->assertStatus(422)
            ->assertJsonStructure(['message']);
        $this->assertSame($this->prediction()['probabilities'], $report->fresh()->ai_probabilities);
    }

    public function test_normal_form_submission_reopens_the_same_report_after_redirect(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'role' => 'admin']);
        $report = $this->report();
        Http::fake(['text-ai.test/predict/text' => Http::response($this->prediction())]);
        $this->actingAs($admin)->from(route('reports.index'))->post(route('reports.ai-suggestion', $report))
            ->assertRedirect(route('reports.index'))->assertSessionHas('ai_review_report_id', $report->id);
        $this->get(route('reports.index'))->assertOk()->assertSee('data-report-open-on-load', false)
            ->assertSee('AI top category')->assertSee('94.0%');
    }

    public function test_top_category_remains_original_after_admin_review_and_is_hidden_in_manual_mode(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'role' => 'admin']);
        $report = $this->report();
        $scores = ['minor' => 0.10, 'serious' => 0.20, 'critical' => 0.60, 'needs_review' => 0.10];
        $report->update(['ai_source' => 'text_review', 'ai_status' => 'needs_review',
            'ai_model_name' => 'fixture-model', 'ai_probabilities' => $scores]);
        $this->actingAs($admin)->post(route('reports.severity-review', $report), [
            'severity_review_label' => 'serious', 'severity_review_notes' => 'Reviewed the condition.'])->assertRedirect();
        $report->refresh();
        $this->assertSame('Serious', $report->operational_severity);
        $this->assertSame('Critical', $report->ai_top_category['label']);
        $this->assertTrue($report->ai_top_category['original']);
        $this->get(route('reports.show', $report))->assertOk()->assertSee('Original AI top category')->assertSee('60.0%');
        config(['services.ai_text.mode' => 'MANUAL_ONLY']);
        $this->assertNull($report->ai_top_category);
    }

    public function test_top_category_does_not_invent_scores_for_missing_invalid_or_tied_results(): void
    {
        $report = $this->report();
        $this->assertNull($report->ai_top_category);
        $report->ai_source = 'text_review';
        $report->ai_probabilities = ['minor' => 0.25, 'serious' => 0.25, 'critical' => 0.25, 'needs_review' => 0.25];
        $this->assertTrue($report->ai_top_category['tied']);
        $this->assertSame('needs_review', $report->ai_top_category['key']);
        $report->ai_probabilities = ['minor' => 99, 'serious' => 1, 'critical' => 0, 'needs_review' => 0];
        $this->assertNull($report->ai_top_category);
    }

    private function prediction(): array
    {
        return ['severity' => 'critical', 'predicted_label' => 'critical', 'confidence' => 0.94,
            'probabilities' => ['minor' => 0.02, 'serious' => 0.02, 'critical' => 0.94, 'needs_review' => 0.02],
            'responder_review_required' => true, 'model_name' => 'fixture-text-model', 'model_version' => 'v1',
            'status' => 'complete', 'summary' => 'Experimental suggestion.', 'deployment_status' => 'experimental'];
    }
}
