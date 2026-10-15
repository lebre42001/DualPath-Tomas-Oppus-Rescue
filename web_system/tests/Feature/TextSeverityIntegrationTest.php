<?php

namespace Tests\Feature;

use App\Events\IncidentFeedUpdated;
use App\Jobs\AnalyzeIncidentEvidence;
use App\Models\IncidentReport;
use App\Models\User;
use App\Services\AiSeverityClient;
use App\Services\AiTextSeverityClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TextSeverityIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        config([
            'services.ai_text.mode' => 'MODEL_ADVISORY',
            'services.ai_text.enabled' => true,
            'services.ai_text.allow_experimental' => false,
            'services.ai_text.url' => 'http://text-ai.test',
            'services.ai_severity.enabled' => true,
            'services.ai_severity.url' => 'http://image-ai.test',
            'services.routing.enabled' => false,
        ]);
        Http::preventStrayRequests();
        Event::fake([IncidentFeedUpdated::class]);
        Storage::fake();
    }

    public function test_manual_only_web_intake_ignores_even_a_successful_critical_ai_response_and_is_idempotent(): void
    {
        config(['services.ai_text.mode' => 'MANUAL_ONLY']);
        Http::fake(['text-ai.test/predict/text' => Http::response($this->prediction())]);
        $civilian = User::factory()->create(['role' => 'civilian', 'account_verified_at' => now()]);
        $input = $this->reportInput('A driver is unresponsive after a collision.') + [
            'submission_id' => '1e0fe3ba-37b9-4b02-9d8d-8c4f61d82aab',
            'evidence' => UploadedFile::fake()->image('road-scene.jpg', 640, 480),
            'selfie' => UploadedFile::fake()->image('verification-selfie.jpg', 640, 480),
        ];

        $this->actingAs($civilian)->post(route('reports.store'), $input)->assertSessionHasNoErrors()->assertRedirect();
        $report = IncidentReport::query()->sole();
        $this->actingAs($civilian)->post(route('reports.store'), $input)->assertSessionHasNoErrors()->assertRedirect();

        $report->refresh();
        $this->assertNull($report->severity);
        $this->assertSame('Awaiting responder', $report->severity_display);
        $this->assertSame('manual_only', $report->ai_source);
        $this->assertSame('needs_review', $report->ai_status);
        $this->assertTrue($report->ai_review_required);
        $this->assertNull($report->ai_confidence);
        $this->assertNull($report->ai_probabilities);
        $this->assertNull($report->ai_processed_at);
        $this->assertCount(1, IncidentReport::query()->get());
        Http::assertNothingSent();
        $admin = $this->apiAdmin();
        $this->actingAs($admin)->get(route('reports.severity', $report))->assertOk()
            ->assertSee('Severity is awaiting responder assessment')
            ->assertSee('No severity model used')
            ->assertDontSee('Critical severity assessment');
    }

    public function test_manual_only_api_intake_ignores_ai_and_broadcasts_review_not_dispatch_priority(): void
    {
        config(['services.ai_text.mode' => 'MANUAL_ONLY']);
        Http::fake(['text-ai.test/predict/text' => Http::response($this->prediction())]);
        $this->apiAdmin();

        $this->postJson('/api/v1/reports', $this->reportInput('A driver is unresponsive after a collision.'))
            ->assertCreated()
            ->assertJsonPath('data.severity', null)
            ->assertJsonPath('data.severity_display', 'Awaiting responder')
            ->assertJsonPath('data.ai_source', 'manual_only')
            ->assertJsonPath('data.ai_review_required', true)
            ->assertJsonPath('data.ai_confidence', null);

        $report = IncidentReport::query()->sole();
        $broadcast = (new IncidentFeedUpdated($report))->broadcastWith();
        $this->assertSame('Needs responder review', $broadcast['report']['dispatch_recommendation']['priority']);
        $this->assertSame('Unassessed description; verify urgency', $broadcast['report']['dispatch_recommendation']['response_type']);
        $this->assertStringNotContainsString('Priority 1', json_encode($broadcast));
        Http::assertNothingSent();
    }

    public function test_patay_keyword_raises_a_possible_fatality_priority_without_confirming_death_or_overwriting_severity(): void
    {
        config(['services.ai_text.mode' => 'MANUAL_ONLY']);
        $this->apiAdmin();

        $this->postJson('/api/v1/reports', $this->reportInput('Naa nay banggaan, patay na ang usa.'))
            ->assertCreated()
            ->assertJsonPath('data.reported_fatality_flag', true)
            ->assertJsonPath('data.severity', null)
            ->assertJsonPath('data.ai_source', 'manual_only');

        $report = IncidentReport::query()->sole();
        $this->assertTrue($report->reported_fatality_flag);
        $this->assertNull($report->severity, 'The keyword is a priority flag, not a confirmed or assigned severity.');

        $broadcast = (new IncidentFeedUpdated($report))->broadcastWith();
        $this->assertTrue($broadcast['report']['reported_fatality_flag']);
        $this->assertSame('Critical priority — possible fatality reported', $broadcast['report']['dispatch_recommendation']['priority']);
        $this->assertStringContainsString('unconfirmed', strtolower($broadcast['report']['dispatch_recommendation']['response_type']));

        $this->actingAs(User::query()->where('is_admin', true)->firstOrFail())
            ->get(route('reports.show', $report))
            ->assertOk()
            ->assertSee('Critical priority — possible fatality reported')
            ->assertSee('does not confirm a death');
    }

    public function test_responder_can_assign_manual_severity_with_actor_and_previous_state_audit(): void
    {
        config(['services.ai_text.mode' => 'MANUAL_ONLY']);
        Http::fake(['text-ai.test/predict/text' => Http::response($this->prediction())]);
        $admin = $this->apiAdmin();
        $this->actingAs($admin)->post(route('reports.store'), $this->reportInput('A driver is unresponsive after a collision.'))
            ->assertSessionHasNoErrors()->assertRedirect();
        $report = IncidentReport::query()->sole();

        $this->actingAs($admin)->post(route('reports.coordination', $report), [
            'status' => 'acknowledged',
            'reviewed_severity' => 'Serious',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $report->refresh();
        $this->assertSame('Serious', $report->severity);
        $this->assertSame('human_responder', $report->ai_source);
        $this->assertFalse($report->ai_review_required);
        $entry = collect($report->coordination_log)->last();
        $this->assertSame($admin->id, $entry['actor_id']);
        $this->assertSame('Awaiting responder', $entry['previous_severity']);
        $this->assertSame('Serious', $entry['reviewed_severity']);
        Http::assertNothingSent();
    }

    public function test_manual_only_human_review_moves_legacy_prediction_to_responder_audit_only(): void
    {
        config(['services.ai_text.mode' => 'MANUAL_ONLY']);
        $admin = $this->apiAdmin();
        $report = IncidentReport::query()->create($this->reportInput('A person was badly injured in a collision.') + [
            'report_code' => 'INC-LEGACY-TEXT-AUDIT',
            'reporter_name' => 'Historical reporter',
            'channel' => 'Internet',
            'severity' => 'Fatal',
            'status' => 'received',
            'ai_source' => 'text_model',
            'ai_status' => 'complete',
            'ai_review_required' => true,
            'ai_confidence' => 93,
            'ai_summary' => 'Legacy model suggested Critical from the description.',
            'ai_model_name' => 'legacy-text-model',
            'ai_model_version' => '0.1',
        ]);

        $this->actingAs($admin)->post(route('reports.coordination', $report), [
            'status' => 'acknowledged',
            'reviewed_severity' => 'Serious',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $report->refresh();
        $this->assertSame('Serious', $report->severity);
        $this->assertSame('human_responder', $report->ai_source);
        $this->assertNull($report->ai_confidence);
        $this->assertNull($report->ai_model_name);
        $this->assertSame('Responder assigned severity: Serious. No automated severity inference was used.', $report->ai_summary);
        $entry = collect($report->coordination_log)->last();
        $this->assertSame('legacy-text-model', $entry['previous_ai_model_name']);
        $this->assertSame(93, $entry['previous_ai_confidence']);
        $this->assertSame('Legacy AI suggestion; audit only; not used for responder severity.', $entry['historical_ai_data_label']);

        $this->actingAs($admin)->get(route('reports.severity', $report))->assertOk()
            ->assertSee('Historical AI suggestion — non-operational')
            ->assertSee('Legacy model suggested Critical from the description.')
            ->assertSee('not used for responder severity');
        Http::assertNothingSent();
    }

    public function test_manual_only_preserves_responder_entered_critical_label(): void
    {
        config(['services.ai_text.mode' => 'MANUAL_ONLY']);
        $analysis = app(AiTextSeverityClient::class)->analyzeForReport('Critical collision.', 'Critical');

        $this->assertSame('Critical', $analysis['severity']);
        $this->assertSame('human_responder', $analysis['source']);
        $this->assertStringContainsString('Responder-assigned severity: Critical.', $analysis['summary']);
    }

    public function test_web_civilian_text_assessment_is_not_overridden_by_photo_ai(): void
    {
        Http::fake(['text-ai.test/predict/text' => Http::response($this->prediction())]);
        $civilian = User::factory()->create(['role' => 'civilian', 'account_verified_at' => now()]);
        $description = 'Nabangga ang duha ka motor; ang usa ka tawo wala motubag.';

        $this->actingAs($civilian)->post(route('reports.store'), $this->reportInput($description) + [
            'evidence' => UploadedFile::fake()->image('road-scene.jpg', 640, 480),
            'selfie' => UploadedFile::fake()->image('verification-selfie.jpg', 640, 480),
        ])->assertSessionHasNoErrors()->assertRedirect();

        $report = IncidentReport::query()->sole();
        $this->assertSame('Critical', $report->severity);
        $this->assertSame('Critical', $report->severity_display);
        $this->assertSame('text_model', $report->ai_source);
        $this->assertTrue($report->ai_review_required);
        $this->assertSame('photo', $report->evidence_type);
        $broadcast = (new IncidentFeedUpdated($report))->broadcastWith();
        $this->assertSame('Critical', $broadcast['report']['severity_display']);
        $this->assertStringNotContainsString('Fatal', $broadcast['message']);
        Storage::assertExists($report->evidence_path);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'http://text-ai.test/predict/text'
            && $request->data() === ['description' => $description]);

        // A stale image job remains ineligible even after the feature is disabled.
        config(['services.ai_text.enabled' => false]);
        (new AnalyzeIncidentEvidence($report->id))->handle(app(AiSeverityClient::class));
        $this->assertSame('text_model', $report->fresh()->ai_source);
        Http::assertSentCount(1);
    }

    public function test_api_uses_the_same_text_only_assessment_and_exposes_display_semantics(): void
    {
        Http::fake(['text-ai.test/predict/text' => Http::response($this->prediction())]);
        $this->apiAdmin();

        $this->postJson('/api/v1/reports', $this->reportInput('A driver is unresponsive after a collision.'))
            ->assertCreated()
            ->assertJsonPath('data.severity', 'Critical')
            ->assertJsonPath('data.severity_display', 'Critical')
            ->assertJsonPath('data.severity_is_provisional', false)
            ->assertJsonPath('data.ai_source', 'text_model')
            ->assertJsonPath('data.ai_review_required', true)
            ->assertJsonPath('data.ai_score_label', 'Model score 90% (uncalibrated)');

        Http::assertSentCount(1);
    }

    public function test_unavailable_text_service_still_accepts_report_without_fabricated_scores(): void
    {
        Http::fake(['text-ai.test/predict/text' => Http::response(['detail' => 'private-path-secret'], 503)]);
        $this->apiAdmin();

        $this->postJson('/api/v1/reports', $this->reportInput('May naaksidente, kailangan ng tulong.'))
            ->assertCreated()
            ->assertJsonPath('data.severity', 'Serious')
            ->assertJsonPath('data.severity_display', 'Needs review')
            ->assertJsonPath('data.severity_is_provisional', true)
            ->assertJsonPath('data.ai_status', 'needs_review')
            ->assertJsonPath('data.ai_confidence', null)
            ->assertJsonPath('data.ai_probabilities', null)
            ->assertJsonPath('data.ai_error_message', null)
            ->assertDontSee('private-path-secret');

        $this->assertStringContainsString('unassessed; responder review required', IncidentReport::query()->sole()->ai_summary);
        $broadcast = (new IncidentFeedUpdated(IncidentReport::query()->sole()))->broadcastWith();
        $this->assertSame('Needs review', $broadcast['report']['severity_display']);
        $this->assertSame('Needs responder review', $broadcast['report']['dispatch_recommendation']['priority']);
    }

    public function test_uncertain_prediction_is_unassessed_instead_of_automatic_minor(): void
    {
        $prediction = $this->prediction();
        $prediction['severity'] = null;
        $prediction['predicted_label'] = 'needs_review';
        $prediction['status'] = 'needs_review';
        $prediction['confidence'] = 0.4;
        $prediction['probabilities'] = ['minor' => 0.2, 'serious' => 0.2, 'critical' => 0.2, 'needs_review' => 0.4];
        Http::fake(['text-ai.test/predict/text' => Http::response($prediction)]);

        $result = app(AiTextSeverityClient::class)->analyze('Naay aksidente.');

        $this->assertSame('text_review', $result['source']);
        $this->assertSame('Serious', $result['severity']);
        $this->assertNull($result['confidence']);
        $this->assertNull($result['probabilities']);
    }

    public function test_overlength_abstention_accepts_empty_probabilities_without_inventing_a_score(): void
    {
        $prediction = $this->prediction();
        $prediction['severity'] = null;
        $prediction['predicted_label'] = 'needs_review';
        $prediction['status'] = 'needs_review';
        $prediction['confidence'] = null;
        $prediction['probabilities'] = [];
        Http::fake(['text-ai.test/predict/text' => Http::response($prediction)]);

        $result = app(AiTextSeverityClient::class)->analyze('A description exceeding the model token budget.');

        $this->assertSame('text_review', $result['source']);
        $this->assertSame($prediction['model_name'], $result['model_name']);
        $this->assertNull($result['confidence']);
    }

    #[DataProvider('invalidPayloads')]
    public function test_invalid_service_payloads_are_rejected(string $field, mixed $value, bool $remove = false): void
    {
        $prediction = $this->prediction();
        if ($remove) {
            unset($prediction[$field]);
        } else {
            $prediction[$field] = $value;
        }
        Http::fake(['text-ai.test/predict/text' => Http::response($prediction)]);

        $result = app(AiTextSeverityClient::class)->analyze('Road collision report.');

        $this->assertSame('text_review', $result['source']);
        $this->assertNull($result['confidence']);
        $this->assertNull($result['probabilities']);
    }

    public static function invalidPayloads(): array
    {
        return [
            'missing severity' => ['severity', null, true],
            'unknown severity' => ['severity', 'fatal'],
            'missing review flag' => ['responder_review_required', null, true],
            'string review flag' => ['responder_review_required', 'false'],
            'missing model name' => ['model_name', null, true],
            'blank model version' => ['model_version', ''],
            'unknown deployment status' => ['deployment_status', 'ready'],
            'missing deployment status' => ['deployment_status', null, true],
            'missing summary' => ['summary', null, true],
            'numeric string score' => ['confidence', '0.90'],
            'score over one' => ['confidence', 90],
            'null completed score' => ['confidence', null],
            'label mismatch' => ['predicted_label', 'minor'],
            'incorrect score sum' => ['probabilities', ['minor' => 0.9, 'serious' => 0.9, 'critical' => 0.9, 'needs_review' => 0.9]],
            'missing review probability' => ['probabilities', ['minor' => 0.05, 'serious' => 0.05, 'critical' => 0.9]],
            'null completed probabilities' => ['probabilities', null],
            'inconsistent review status' => ['status', 'needs_review'],
        ];
    }

    public function test_experimental_predictions_require_separate_opt_in_and_remain_labeled(): void
    {
        $prediction = $this->prediction();
        $prediction['deployment_status'] = 'experimental';
        Http::fake(['text-ai.test/predict/text' => Http::response($prediction)]);

        $client = app(AiTextSeverityClient::class);
        $this->assertSame('text_review', $client->analyze('Road collision.')['source']);

        config(['services.ai_text.allow_experimental' => true]);
        $result = $client->analyze('Road collision.');
        $this->assertSame('text_model_experimental', $result['source']);
        $this->assertTrue($result['review_required']);
        $this->assertStringContainsString('Experimental text AI', $result['summary']);
    }

    public function test_responder_assessment_is_preserved_when_text_model_disagrees(): void
    {
        Http::fake(['text-ai.test/predict/text' => Http::response($this->prediction())]);
        $admin = $this->apiAdmin();

        $this->actingAs($admin)->post(route('reports.store'), $this->reportInput('Road collision reported.') + ['severity' => 'Serious'])
            ->assertSessionHasNoErrors()->assertRedirect();

        $report = IncidentReport::query()->sole();
        $this->assertSame('Serious', $report->severity);
        $this->assertSame('Serious', $report->severity_display);
        $this->assertSame('text_human_override', $report->ai_source);
        $this->assertSame('complete', $report->ai_status);
        $this->assertFalse($report->ai_review_required);
        $this->assertStringContainsString('Responder-assigned severity: Serious.', $report->ai_summary);
        $this->assertStringContainsString('suggests Critical', $report->ai_summary);
    }

    public function test_disabled_text_model_keeps_photos_and_requires_review_without_image_fallback(): void
    {
        config(['services.ai_text.enabled' => false]);
        Http::fake(['image-ai.test/predict' => Http::response(['severity' => 'Fatal', 'confidence' => 0.95, 'accepted' => true])]);
        $civilian = User::factory()->create(['role' => 'civilian', 'account_verified_at' => now()]);

        $this->actingAs($civilian)->post(route('reports.store'), $this->reportInput('Small dent on a parked car.') + [
            'evidence' => UploadedFile::fake()->image('road-scene.jpg', 640, 480),
            'selfie' => UploadedFile::fake()->image('verification-selfie.jpg', 640, 480),
        ])->assertSessionHasNoErrors()->assertRedirect();

        $report = IncidentReport::query()->sole();
        $this->assertSame('Serious', $report->severity);
        $this->assertSame('text_review', $report->ai_source);
        $this->assertSame('Needs review', $report->severity_display);
        $this->assertSame('Model score unavailable', $report->ai_score_display);
        $this->assertTrue($report->ai_review_required);
        $this->assertNull($report->ai_confidence);
        $this->assertNull($report->ai_probabilities);
        $this->assertNull($report->photo_relevance_label);
        Storage::assertExists($report->evidence_path);
        Storage::assertExists($report->reporter_selfie_path);
        Http::assertNothingSent();
    }

    public function test_stale_image_job_preserves_historical_record_and_photo_without_inference(): void
    {
        config(['services.ai_text.enabled' => false, 'services.ai_severity.enabled' => true]);
        Http::fake();
        Storage::put('incident-evidence/historical.jpg', 'historical photo bytes');
        $report = IncidentReport::query()->create($this->reportInput('Historical collision report.') + [
            'report_code' => 'INC-HISTORICAL-IMAGE',
            'reporter_name' => 'Historical reporter',
            'channel' => 'Internet',
            'severity' => 'Fatal',
            'status' => 'received',
            'evidence_type' => 'photo',
            'evidence_path' => 'incident-evidence/historical.jpg',
            'ai_source' => 'python_model',
            'ai_status' => 'pending',
            'ai_confidence' => 91,
            'ai_summary' => 'Stored historical photo result.',
        ]);
        $before = $report->fresh()->getAttributes();

        (new AnalyzeIncidentEvidence($report->id))->handle(app(AiSeverityClient::class));

        $this->assertSame($before, $report->fresh()->getAttributes());
        $this->assertFalse(app(AiSeverityClient::class)->enabled());
        $this->assertFalse(app(AiSeverityClient::class)->shouldAnalyze($report));
        $this->assertSame('historical photo bytes', Storage::get($report->evidence_path));
        Http::assertNothingSent();
        Event::assertNotDispatched(IncidentFeedUpdated::class);
    }

    public function test_different_photos_do_not_change_description_only_request_or_assessment(): void
    {
        Http::fake(['text-ai.test/predict/text' => Http::response($this->prediction())]);
        $civilian = User::factory()->create(['role' => 'civilian', 'account_verified_at' => now()]);
        $description = 'A driver is unresponsive after a collision.';

        foreach (['road-scene-a.jpg', 'road-scene-b.png'] as $index => $filename) {
            $this->actingAs($civilian)->post(route('reports.store'), $this->reportInput($description) + [
                'evidence' => UploadedFile::fake()->image($filename, 640 + $index * 160, 480),
                'selfie' => UploadedFile::fake()->image('verification-selfie.jpg', 640, 480),
            ])->assertSessionHasNoErrors()->assertRedirect();
        }

        $reports = IncidentReport::query()->get();
        $this->assertCount(2, $reports);
        $this->assertSame(['text_model', 'text_model'], $reports->pluck('ai_source')->all());
        $this->assertSame(['Critical', 'Critical'], $reports->pluck('severity_display')->all());
        $this->assertNotSame($reports[0]->evidence_path, $reports[1]->evidence_path);
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request) => $request->url() !== 'http://text-ai.test/predict/text'
            || $request->data() !== ['description' => $description]);
    }

    public function test_report_view_uses_review_label_and_does_not_claim_zero_confidence(): void
    {
        Http::fake(['text-ai.test/predict/text' => Http::response([], 503)]);
        $admin = $this->apiAdmin();
        $this->actingAs($admin)->post(route('reports.store'), $this->reportInput('Naay naaksidente.'))->assertRedirect();
        $report = IncidentReport::query()->sole();

        $this->get(route('reports.show', $report))->assertOk()
            ->assertSee('Needs review')
            ->assertSee('Model score unavailable')
            ->assertDontSee('Confidence 0%');

        $this->get(route('reports.severity', $report))->assertOk()
            ->assertSee('Needs review')
            ->assertSee('Model score unavailable')
            ->assertDontSee('84%')
            ->assertDontSee('description_rules');
    }

    public function test_status_only_change_keeps_pending_review_and_explicit_human_review_clears_it(): void
    {
        Http::fake(['text-ai.test/predict/text' => Http::response([], 503)]);
        $admin = $this->apiAdmin();
        $this->actingAs($admin)->post(route('reports.store'), $this->reportInput('Naay naaksidente.') + [
            'evidence' => UploadedFile::fake()->image('road-scene.jpg', 640, 480),
        ])->assertRedirect();
        $report = IncidentReport::query()->sole();

        $this->post(route('reports.coordination', $report), ['status' => 'acknowledged'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('Needs review', $report->fresh()->severity_display);
        $this->assertTrue($report->fresh()->ai_review_required);

        $this->post(route('reports.coordination', $report), [
            'status' => 'acknowledged',
            'reviewed_severity' => 'Fatal',
            'response_notes' => 'Reviewed with the responding team.',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $report->refresh();
        $this->assertSame('Fatal', $report->severity_display);
        $this->assertSame('text_human_override', $report->ai_source);
        $this->assertSame('complete', $report->ai_status);
        $this->assertFalse($report->ai_review_required);
        $entry = collect($report->coordination_log)->last();
        $this->assertSame('Needs review', $entry['previous_severity']);
        $this->assertSame('Fatal', $entry['reviewed_severity']);
        $this->assertSame($admin->id, $entry['actor_id']);

        // Even a stale pending image job cannot replace reviewed text records.
        config(['services.ai_text.enabled' => false]);
        $report->update(['ai_status' => 'pending', 'ai_processed_at' => null]);
        (new AnalyzeIncidentEvidence($report->id))->handle(app(AiSeverityClient::class));
        $this->assertSame('text_human_override', $report->fresh()->ai_source);
        Http::assertSentCount(1);
    }

    public function test_civilian_cannot_submit_responder_severity_review(): void
    {
        Http::fake(['text-ai.test/predict/text' => Http::response([], 503)]);
        $admin = $this->apiAdmin();
        $this->actingAs($admin)->post(route('reports.store'), $this->reportInput('Naay naaksidente.'))->assertRedirect();
        $report = IncidentReport::query()->sole();
        $civilian = User::factory()->create(['role' => 'civilian']);

        $this->actingAs($civilian)->post(route('reports.coordination', $report), [
            'status' => 'acknowledged',
            'reviewed_severity' => 'Minor',
        ])->assertRedirect(route('dashboard'));

        $this->assertSame('text_review', $report->fresh()->ai_source);
    }

    private function prediction(): array
    {
        return [
            'severity' => 'critical',
            'predicted_label' => 'critical',
            'confidence' => 0.9,
            'probabilities' => ['minor' => 0.02, 'serious' => 0.05, 'critical' => 0.9, 'needs_review' => 0.03],
            'responder_review_required' => true,
            'model_name' => 'reviewed-road-text-test-model',
            'model_version' => 'test-1',
            'status' => 'complete',
            'summary' => 'Suggested text severity requires responder verification.',
            'deployment_status' => 'validated',
        ];
    }

    private function reportInput(string $description): array
    {
        return [
            'incident_type' => 'Road Accident',
            'location_text' => 'Bontoc highway',
            'latitude' => 10.354270,
            'longitude' => 124.970400,
            'description' => $description,
            'transmission_type' => 'online',
        ];
    }

    private function apiAdmin(): User
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'is_admin' => true,
            'api_token' => hash('sha256', 'text-integration-test-token'),
        ]);
        $this->withHeaders(['Authorization' => 'Bearer text-integration-test-token']);

        return $admin;
    }
}
