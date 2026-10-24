<?php

namespace Tests\Feature;

use App\Events\IncidentFeedUpdated;
use App\Models\IncidentReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class DescriptionSeverityReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['services.ai_text.mode' => 'MANUAL_ONLY', 'services.routing.enabled' => false]);
        Event::fake([IncidentFeedUpdated::class]);
        Http::preventStrayRequests();
    }

    public function test_admin_can_review_unknown_source_without_changing_coordination_or_creating_an_ai_score(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'role' => 'admin']);
        $report = $this->report(['ai_source' => null, 'severity' => 'Serious', 'assigned_responder_id' => $admin->id,
            'status' => 'responding', 'response_notes' => 'Assistance is on the way.', 'status_updated_at' => now()->subHour()]);
        $previousStatusTime = $report->status_updated_at->toIso8601String();

        $this->actingAs($admin)->post(route('reports.severity-review', $report), [
            'severity_review_label' => 'critical',
            'severity_review_notes' => 'Reviewed the urgency described by the witness.',
            'actor_id' => 999999,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $report->refresh();
        $this->assertSame('Critical', $report->severity);
        $this->assertSame('human_responder', $report->ai_source);
        $this->assertFalse($report->ai_review_required);
        $this->assertNull($report->ai_confidence);
        $this->assertSame('responding', $report->status);
        $this->assertSame($admin->id, $report->assigned_responder_id);
        $this->assertSame($previousStatusTime, $report->status_updated_at->toIso8601String());
        $this->assertSame('Assistance is on the way.', $report->response_notes);
        $review = $report->description_severity_review;
        $this->assertSame($admin->id, $review['actor_id']);
        $this->assertSame('critical', $review['reviewed_label']);
        $this->assertSame('description_only', $review['review_scope']);
        $this->assertSame('human_assessment', $review['review_method']);
        $this->assertSame(hash('sha256', $report->description), $review['description_sha256']);
        Event::assertDispatched(IncidentFeedUpdated::class);
        Http::assertNothingSent();
    }

    public function test_internal_review_reason_is_not_exposed_in_the_civilian_report_view(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'role' => 'admin']);
        $civilian = User::factory()->create(['is_admin' => false, 'role' => 'civilian']);
        $report = $this->report(['reported_by' => $civilian->id]);
        $this->actingAs($admin)->post(route('reports.severity-review', $report), [
            'severity_review_label' => 'serious', 'severity_review_notes' => 'INTERNAL-REVIEW-REASON-ONLY',
        ])->assertSessionHasNoErrors();
        $this->get(route('reports.show', $report))->assertOk()->assertSee('INTERNAL-REVIEW-REASON-ONLY');
        $this->actingAs($civilian)->get(route('reports.show', $report))->assertOk()
            ->assertDontSee('INTERNAL-REVIEW-REASON-ONLY');
    }

    public function test_admin_confirmation_and_correction_retain_the_original_ai_suggestion(): void
    {
        config(['services.ai_text.mode' => 'MODEL_ADVISORY']);
        $admin = User::factory()->create(['is_admin' => true, 'role' => 'admin']);
        $report = $this->report(['ai_source' => 'text_model', 'severity' => 'Critical', 'ai_status' => 'complete',
            'ai_confidence' => 89, 'ai_model_name' => 'fixture-text-model', 'ai_model_version' => 'test-1',
            'ai_summary' => 'Fixture AI suggestion.', 'ai_processed_at' => now()]);

        $this->assertNull($report->operational_severity);
        $this->assertFalse(IncidentReport::query()->operationallyAssessed()->whereKey($report->id)->exists());
        $this->assertTrue(IncidentReport::query()->needsResponderReview()->whereKey($report->id)->exists());
        $broadcast = (new IncidentFeedUpdated($report))->broadcastWith();
        $this->assertNull($broadcast['report']['severity']);
        $this->assertSame('Critical', $broadcast['report']['ai_suggested_severity']);
        $this->assertSame('Needs responder review', $broadcast['report']['dispatch_recommendation']['priority']);

        $this->actingAs($admin)->get(route('reports.show', $report))->assertOk()
            ->assertSee('AI suggestion')->assertSee('Confirm severity')->assertDontSee('fixture-text-model');
        $this->post(route('reports.severity-review', $report), [
            'severity_review_label' => 'serious', 'severity_review_notes' => 'Correction after reviewing the submitted text.',
        ])->assertSessionHasNoErrors();
        $report->refresh();
        $this->assertSame('Serious', $report->severity);
        $this->assertSame('Serious', $report->operational_severity);
        $this->assertTrue(IncidentReport::query()->operationallyAssessed()->whereKey($report->id)->exists());
        $this->assertFalse(IncidentReport::query()->needsResponderReview()->whereKey($report->id)->exists());
        $this->assertSame('corrected_ai', $report->description_severity_review['review_method']);
        $this->assertSame('critical', $report->description_severity_review['ai_suggestion']['label']);
        $this->assertSame(89, $report->description_severity_review['ai_suggestion']['model_score']);
        $this->get(route('reports.show', $report))->assertOk()->assertSee('Original AI suggestion')->assertDontSee('fixture-text-model');

        $this->post(route('reports.severity-review', $report), [
            'severity_review_label' => 'critical', 'severity_review_notes' => 'Confirmed the original suggestion after follow-up review.',
        ])->assertSessionHasNoErrors();
        $report->refresh();
        $this->assertSame('confirmed_ai', $report->description_severity_review['review_method']);
        $this->assertSame('fixture-text-model', $report->description_severity_review['ai_suggestion']['model_name']);
        $this->assertCount(2, $report->coordination_log);
    }

    public function test_needs_review_is_an_explicit_human_label_without_an_invented_severity(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'role' => 'admin']);
        $report = $this->report();
        $this->actingAs($admin)->post(route('reports.severity-review', $report), [
            'severity_review_label' => 'needs_review', 'severity_review_notes' => 'The description does not state enough information.',
        ])->assertSessionHasNoErrors();
        $report->refresh();
        $this->assertNull($report->severity);
        $this->assertSame('Needs review', $report->severity_display);
        $this->assertTrue($report->ai_review_required);
        $this->assertSame('needs_review', $report->description_severity_review['reviewed_label']);
        $this->assertTrue(IncidentReport::query()->needsResponderReview()->whereKey($report->id)->exists());
        $this->assertFalse(IncidentReport::query()->operationallyAssessed()->whereKey($report->id)->exists());
    }

    public function test_invalid_or_unexplained_reviews_do_not_mutate_the_report(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'role' => 'admin']);
        $report = $this->report();
        $this->actingAs($admin)->post(route('reports.severity-review', $report), [
            'severity_review_label' => 'fatal', 'severity_review_notes' => ' ',
        ])->assertSessionHasErrors(['severity_review_label', 'severity_review_notes']);
        $this->assertNull($report->fresh()->severity);
        $this->assertNull($report->fresh()->coordination_log);
        Event::assertNotDispatched(IncidentFeedUpdated::class);
    }

    public function test_civilians_and_non_admin_responders_cannot_confirm_severity(): void
    {
        $report = $this->report();
        foreach (['civilian', 'responder'] as $role) {
            $user = User::factory()->create(['is_admin' => false, 'role' => $role]);
            $this->actingAs($user)->postJson(route('reports.severity-review', $report), [
                'severity_review_label' => 'minor', 'severity_review_notes' => 'Unauthorized review.',
            ])->assertForbidden();
        }
        $this->assertNull($report->fresh()->severity);
        Event::assertNotDispatched(IncidentFeedUpdated::class);
    }

    public function test_controls_are_available_on_monitoring_reports_and_details_and_hidden_from_civilians(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'role' => 'admin']);
        $civilian = User::factory()->create(['is_admin' => false, 'role' => 'civilian']);
        $report = $this->report(['reported_by' => $civilian->id]);
        foreach ([route('monitoring'), route('reports.index'), route('reports.show', $report)] as $url) {
            $this->actingAs($admin)->get($url)->assertOk()->assertSee('Confirm or correct severity')
                ->assertSee('name="severity_review_label"', false)->assertSee('value="critical"', false)
                ->assertSee('value="needs_review"', false)->assertSee('AI suggestion unavailable');
        }
        $this->actingAs($civilian)->get(route('reports.show', $report))->assertOk()->assertDontSee('data-severity-review', false);
    }

    public function test_export_contains_review_provenance_and_excludes_stale_labels(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'role' => 'admin']);
        $report = $this->report();
        $this->actingAs($admin)->post(route('reports.severity-review', $report), [
            'severity_review_label' => 'minor', 'severity_review_notes' => 'Reviewed text supports this label.',
        ])->assertSessionHasNoErrors();
        $params = ['period' => 'monthly', 'format' => 'csv'];
        $csv = $this->get(route('reports.export', $params))->assertOk()->streamedContent();
        $rows = array_map('str_getcsv', explode("\n", trim($csv)));
        $record = array_combine($rows[0], $rows[1]);
        $this->assertSame('minor', $record['Text review label']);
        $this->assertSame((string) $admin->id, $record['Text reviewer ID']);
        $this->assertSame('human_assessment', $record['Text review method']);
        $this->assertSame(hash('sha256', $report->description), $record['Text description SHA256']);

        $report->refresh()->update(['description' => 'Different description requiring a fresh review.']);
        $this->assertNull($report->fresh()->description_severity_review);
        $csv = $this->get(route('reports.export', $params))->assertOk()->streamedContent();
        $rows = array_map('str_getcsv', explode("\n", trim($csv)));
        $record = array_combine($rows[0], $rows[1]);
        $this->assertSame('', $record['Text review label']);
        $this->assertSame('', $record['Text reviewer ID']);
    }

    private function report(array $overrides = []): IncidentReport
    {
        return IncidentReport::query()->create(array_merge([
            'report_code' => 'INC-REVIEW-'.Str::uuid(),
            'reporter_name' => 'Test witness',
            'incident_type' => 'Road Accident',
            'description' => 'A vehicle collision was reported by a witness.',
            'location_text' => 'Test road',
            'severity' => null,
            'status' => 'received',
            'channel' => 'Internet',
            'transmission_type' => 'online',
            'ai_source' => 'manual_only',
            'ai_status' => 'needs_review',
            'ai_review_required' => true,
        ], $overrides));
    }
}
