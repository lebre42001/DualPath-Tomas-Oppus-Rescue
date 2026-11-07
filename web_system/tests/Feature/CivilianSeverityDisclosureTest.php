<?php

namespace Tests\Feature;

use App\Events\IncidentFeedUpdated;
use App\Models\IncidentReport;
use App\Models\User;
use App\Support\CivilianSeverityAssessment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class CivilianSeverityDisclosureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['services.ai_text.mode' => 'MODEL_ADVISORY', 'services.ai_text.enabled' => false,
            'services.routing.enabled' => false]);
        Event::fake([IncidentFeedUpdated::class]);
        Http::preventStrayRequests();
    }

    public function test_acknowledgment_and_response_do_not_release_an_unreviewed_ai_suggestion_on_any_civilian_surface(): void
    {
        $owner = $this->civilian();
        $report = $this->report($owner);
        $admin = $this->admin();

        foreach (['received', 'acknowledged', 'responding'] as $status) {
            if ($status !== 'received') {
                $this->actingAs($admin)->post(route('reports.coordination', $report), [
                    'status' => $status, 'response_notes' => 'We have received your report.',
                ])->assertSessionHasNoErrors();
                $report->refresh();
            }

            $this->actingAs($owner);
            foreach ($this->civilianPages($report) as $url) {
                $this->get($url)->assertOk()->assertSee('data-assessment-state="pending"', false)
                    ->assertSee('Severity under review')->assertDontSee('Original AI suggestion')
                    ->assertDontSee('PRIVATE-MODEL-NAME')->assertDontSee('PRIVATE-AI-DIAGNOSTIC')
                    ->assertDontSee('PRIVATE-REVIEW-REASON')->assertDontSee('89%');
            }

            $details = $this->getJson(route('reports.civilian-details', $report))->assertOk()
                ->assertDontSee('PRIVATE-MODEL-NAME')->assertDontSee('PRIVATE-AI-DIAGNOSTIC');
            $html = $details->json('report.assessmentHtml');
            $this->assertStringContainsString('data-assessment-state="pending"', $html);
            $this->assertStringNotContainsString('Critical', $html);
            $this->assertStringNotContainsString('Original AI suggestion', $html);
        }
    }

    public function test_explicit_review_is_visible_even_before_a_response_and_reveals_only_the_safe_original_suggestion(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 7)->setTime(2, 30));
        $owner = $this->civilian();
        $report = $this->report($owner);
        $this->review($report, 'serious');
        $report->refresh();

        $this->assertSame('received', $report->status);
        $assessment = CivilianSeverityAssessment::forReport($report);
        $this->assertSame('reviewed', $assessment['state']);
        $this->assertSame('Serious', $assessment['severity']);
        $this->assertSame('Critical', $assessment['originalAi']);
        $this->assertStringContainsString('correct', strtolower($assessment['outcome']));
        $this->assertNotEmpty($assessment['reviewedAt']);
        $this->assertNotEmpty($assessment['reviewedAtLabel']);
        $this->assertSame('Duty Admin', $assessment['reviewer']);
        $this->assertSafeAssessment($assessment);

        $this->actingAs($owner);
        foreach ($this->civilianPages($report) as $url) {
            $this->get($url)->assertOk()->assertSee('data-assessment-state="reviewed"', false)
                ->assertSee('Human-reviewed Severity')->assertSee('Serious')
                ->assertSee('Original AI suggestion')->assertSee('Critical')
                ->assertSee('Reviewed (Philippine time)')->assertSee('Duty Admin')
                ->assertDontSee('PRIVATE-REVIEW-REASON')->assertDontSee('PRIVATE-MODEL-NAME')
                ->assertDontSee('PRIVATE-AI-DIAGNOSTIC')->assertDontSee('89%');
        }
        $this->getJson(route('reports.civilian-details', $report))->assertOk()
            ->assertDontSee('PRIVATE-REVIEW-REASON')->assertDontSee('PRIVATE-MODEL-NAME');
    }

    public function test_confirmation_and_later_correction_keep_the_original_ai_label_and_latest_human_assessment(): void
    {
        $report = $this->report($this->civilian());
        $this->review($report, 'critical');
        $assessment = CivilianSeverityAssessment::forReport($report->fresh());
        $this->assertSame('Critical', $assessment['severity']);
        $this->assertSame('Critical', $assessment['originalAi']);
        $this->assertStringContainsString('confirm', strtolower($assessment['outcome']));

        $this->review($report->fresh(), 'minor');
        $assessment = CivilianSeverityAssessment::forReport($report->fresh());
        $this->assertSame('Minor', $assessment['severity']);
        $this->assertSame('Critical', $assessment['originalAi']);
        $this->assertStringContainsString('correct', strtolower($assessment['outcome']));
        $this->assertSafeAssessment($assessment);
    }

    public function test_needs_review_requests_more_information_without_disclosing_the_ai_label(): void
    {
        $owner = $this->civilian();
        $report = $this->report($owner);
        $this->review($report, 'needs_review');
        $assessment = CivilianSeverityAssessment::forReport($report->fresh());
        $this->assertSame('needs_information', $assessment['state']);
        $this->assertNull($assessment['severity']);
        $this->assertNull($assessment['originalAi']);
        $this->assertSafeAssessment($assessment);

        $this->actingAs($owner)->get(route('reports.show', $report))->assertOk()
            ->assertSee('data-assessment-state="needs_information"', false)
            ->assertDontSee('Original AI suggestion')->assertDontSee('PRIVATE-REVIEW-REASON');
        $html = $this->getJson(route('reports.civilian-details', $report))->assertOk()->json('report.assessmentHtml');
        $this->assertStringNotContainsString('Critical', $html);
    }

    public function test_manual_only_assessment_is_visible_without_inventing_or_reusing_an_ai_result(): void
    {
        config(['services.ai_text.mode' => 'MANUAL_ONLY']);
        $owner = $this->civilian();
        // Even retained old model fields are not a valid original AI result in manual-only mode.
        $report = $this->report($owner);
        $this->review($report, 'serious');
        $assessment = CivilianSeverityAssessment::forReport($report->fresh());
        $this->assertSame('reviewed', $assessment['state']);
        $this->assertSame('Serious', $assessment['severity']);
        $this->assertNull($assessment['originalAi']);
        $this->assertSafeAssessment($assessment);
        $this->actingAs($owner)->get(route('reports.show', $report))->assertOk()
            ->assertSee('AI result unavailable')->assertDontSee('PRIVATE-MODEL-NAME');
    }

    public function test_a_human_source_without_review_provenance_does_not_unlock_severity(): void
    {
        foreach (['human_responder', 'text_human_override', 'manual_override'] as $source) {
            $report = $this->report($this->civilian(), ['ai_source' => $source, 'ai_review_required' => false,
                'status' => 'resolved', 'coordination_log' => null]);
            $assessment = CivilianSeverityAssessment::forReport($report);
            $this->assertSame('pending', $assessment['state'], $source);
            $this->assertNull($assessment['severity']);
            $this->assertNull($assessment['originalAi']);
        }
    }

    public function test_edited_description_or_mismatched_current_label_invalidates_the_review(): void
    {
        foreach (['description' => 'A changed description needs a fresh review.', 'severity' => 'Minor'] as $field => $value) {
            $report = $this->report($this->civilian());
            $this->review($report, 'serious');
            $report->refresh()->update([$field => $value]);
            $assessment = CivilianSeverityAssessment::forReport($report->fresh());
            $this->assertSame('pending', $assessment['state'], $field);
            $this->assertNull($assessment['severity']);
            $this->assertNull($assessment['originalAi']);
        }
    }

    public function test_invalid_latest_review_provenance_cannot_fall_back_to_an_older_valid_review(): void
    {
        $report = $this->report($this->civilian());
        $this->review($report, 'serious');
        $report->refresh();
        $validReview = $report->coordination_log[0];
        foreach ([
            ['actor_role' => 'Civilian'], ['description_sha256' => null], ['review_scope' => 'unknown'],
            ['occurred_at' => 'not-a-date'], ['occurred_at' => now()->addHour()->toIso8601String()],
            ['reviewed_label' => 'minor'],
        ] as $invalid) {
            $report->update(['coordination_log' => [$validReview, array_replace($validReview, $invalid)]]);
            $assessment = CivilianSeverityAssessment::forReport($report->fresh());
            $this->assertSame('pending', $assessment['state'], json_encode($invalid));
            $this->assertNull($assessment['severity']);
            $this->assertNull($assessment['originalAi']);
        }
    }

    public function test_only_the_public_review_explanation_is_disclosed_and_html_is_escaped(): void
    {
        $owner = $this->civilian();
        $report = $this->report($owner);
        $note = '<img src=x onerror=alert(1)> Please provide a clearer location.';
        $this->review($report, 'serious', ['severity_public_note' => $note]);
        $report->refresh();
        $this->assertSame($note, $report->description_severity_review['public_note']);
        $this->assertSame($note, CivilianSeverityAssessment::forReport($report)['explanation']);

        $this->actingAs($owner);
        foreach ($this->civilianPages($report) as $url) {
            $this->get($url)->assertOk()->assertSee($note)->assertDontSee($note, false)
                ->assertDontSee('PRIVATE-REVIEW-REASON');
        }
        $html = $this->getJson(route('reports.civilian-details', $report))->assertOk()->json('report.assessmentHtml');
        $this->assertStringContainsString(e($note), $html);
        $this->assertStringNotContainsString($note, $html);
        $this->assertStringNotContainsString('PRIVATE-REVIEW-REASON', $html);
    }

    public function test_status_only_update_keeps_review_pending_but_explicit_operational_review_is_recognized(): void
    {
        $report = $this->report($this->civilian());
        $this->actingAs($this->admin())->post(route('reports.coordination', $report), [
            'status' => 'acknowledged',
        ])->assertSessionHasNoErrors();
        $this->assertSame('pending', CivilianSeverityAssessment::forReport($report->fresh())['state']);

        $this->post(route('reports.coordination', $report), [
            'status' => 'acknowledged', 'reviewed_severity' => 'Serious',
            'response_notes' => 'Your report is being reviewed.',
        ])->assertSessionHasNoErrors();
        $assessment = CivilianSeverityAssessment::forReport($report->fresh());
        $this->assertSame('reviewed', $assessment['state']);
        $this->assertSame('Serious', $assessment['severity']);
        $this->assertSame('Critical', $assessment['originalAi']);
        $this->assertSafeAssessment($assessment);
    }

    public function test_civilian_api_report_and_summary_use_the_same_gate_and_never_return_raw_ai_fields(): void
    {
        $owner = $this->civilian();
        $owner->forceFill(['api_token' => hash('sha256', 'civilian-assessment-token')])->save();
        $report = $this->report($owner, ['status' => 'responding']);
        $other = $this->report($this->civilian(), ['report_code' => 'OTHER-ACCOUNT-PRIVATE']);
        $headers = ['Authorization' => 'Bearer civilian-assessment-token'];

        foreach ([['/api/v1/reports', 'data.0'], ['/api/v1/reports/'.$report->id, 'data'], ['/api/v1/summary', 'recent_reports.0']] as [$url, $path]) {
            $this->getJson($url, $headers)->assertOk()->assertJsonPath($path.'.severity', null)
                ->assertJsonPath($path.'.severityAssessment.state', 'pending')
                ->assertJsonMissingPath($path.'.ai_source')->assertJsonMissingPath($path.'.ai_confidence')
                ->assertJsonMissingPath($path.'.coordination_log')->assertDontSee('PRIVATE-MODEL-NAME')
                ->assertDontSee('PRIVATE-AI-DIAGNOSTIC')->assertDontSee('OTHER-ACCOUNT-PRIVATE');
        }
        $this->getJson('/api/v1/reports/'.$other->id, $headers)->assertNotFound();

        $this->review($report, 'serious');
        foreach ([['/api/v1/reports', 'data.0'], ['/api/v1/reports/'.$report->id, 'data'], ['/api/v1/summary', 'recent_reports.0']] as [$url, $path]) {
            $this->getJson($url, $headers)->assertOk()->assertJsonPath($path.'.severity', 'Serious')
                ->assertJsonPath($path.'.severityAssessment.state', 'reviewed')
                ->assertJsonPath($path.'.severityAssessment.originalAi', 'Critical')
                ->assertDontSee('PRIVATE-REVIEW-REASON')->assertDontSee('PRIVATE-MODEL-NAME');
        }
    }

    public function test_api_submission_receipt_and_idempotent_retry_are_safe_before_review(): void
    {
        Storage::fake();
        config(['services.ai_text.mode' => 'MANUAL_ONLY']);
        $owner = $this->civilian();
        $owner->forceFill(['api_token' => hash('sha256', 'new-assessment-token')])->save();
        $payload = [
            'submission_id' => (string) Str::uuid(), 'incident_type' => 'Road Accident',
            'location_text' => 'Market entrance', 'latitude' => 10.35, 'longitude' => 124.97,
            'description' => 'Vehicle collision near the market.',
            'evidence' => UploadedFile::fake()->image('road-scene.jpg', 640, 480),
            ...$this->verifiedSelfie(UploadedFile::fake()->image('face.jpg', 480, 640), $owner),
        ];
        $headers = ['Authorization' => 'Bearer new-assessment-token'];
        $this->postJson('/api/v1/reports', $payload, $headers)->assertCreated()
            ->assertJsonPath('duplicate', false)->assertJsonPath('data.severity', null)
            ->assertJsonPath('data.severityAssessment.state', 'pending')->assertJsonMissingPath('data.ai_source');
        $this->postJson('/api/v1/reports', $payload, $headers)->assertOk()
            ->assertJsonPath('duplicate', true)->assertJsonPath('data.severity', null)
            ->assertJsonPath('data.severityAssessment.state', 'pending')->assertJsonMissingPath('data.ai_confidence');
        $this->assertDatabaseCount('incident_reports', 1);
    }

    public function test_other_civilians_cannot_access_reviewed_details_and_admin_keeps_assessment_tools(): void
    {
        $owner = $this->civilian();
        $report = $this->report($owner);
        $this->review($report, 'serious');
        $this->actingAs($this->civilian())->getJson(route('reports.civilian-details', $report))->assertNotFound();
        foreach ([route('reports.show', $report), route('reports.success', $report)] as $url) {
            $this->get($url)->assertNotFound();
        }

        $this->actingAs($this->admin())->get(route('reports.show', $report))->assertOk()
            ->assertSee('Confirm or correct severity')->assertSee('Original AI suggestion')
            ->assertSee('PRIVATE-REVIEW-REASON');
    }

    private function civilian(): User
    {
        return User::factory()->create(['role' => 'civilian', 'is_admin' => false, 'account_verified_at' => now()]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_admin' => true, 'name' => 'Duty Admin']);
    }

    private function report(User $owner, array $overrides = []): IncidentReport
    {
        return IncidentReport::query()->create([
            'report_code' => 'INC-DISCLOSE-'.Str::uuid(), 'reported_by' => $owner->id, 'reporter_name' => $owner->name,
            'incident_type' => 'Road Accident', 'description' => 'A witness reported a vehicle collision near the market.',
            'location_text' => 'Market entrance', 'severity' => 'Critical', 'status' => 'received',
            'channel' => 'Internet', 'transmission_type' => 'online', 'ai_source' => 'text_model',
            'ai_status' => 'complete', 'ai_review_required' => true, 'ai_confidence' => 89,
            'ai_model_name' => 'PRIVATE-MODEL-NAME', 'ai_model_version' => 'PRIVATE-MODEL-VERSION',
            'ai_summary' => 'PRIVATE-AI-DIAGNOSTIC', 'ai_processed_at' => now(),
            'ai_probabilities' => ['minor' => .02, 'serious' => .06, 'critical' => .89, 'needs_review' => .03],
            ...$overrides,
        ]);
    }

    private function review(IncidentReport $report, string $label, array $extra = []): void
    {
        $this->actingAs($this->admin())->post(route('reports.severity-review', $report), [
            'severity_review_label' => $label, 'severity_review_notes' => 'PRIVATE-REVIEW-REASON', ...$extra,
        ])->assertSessionHasNoErrors()->assertRedirect();
    }

    private function civilianPages(IncidentReport $report): array
    {
        return [route('dashboard'), route('reports.index'), route('reports.show', $report), route('reports.success', $report)];
    }

    private function assertSafeAssessment(array $assessment): void
    {
        $json = json_encode($assessment);
        foreach (['PRIVATE-MODEL-NAME', 'PRIVATE-MODEL-VERSION', 'PRIVATE-AI-DIAGNOSTIC', 'PRIVATE-REVIEW-REASON',
            'model_score', 'model_probabilities', 'ai_confidence', 'review_notes', 'description_sha256'] as $private) {
            $this->assertStringNotContainsString($private, $json);
        }
    }
}
