<?php

namespace Tests\Feature;

use App\Events\IncidentFeedUpdated;
use App\Models\AiTrainingFeedback;
use App\Models\IncidentReport;
use App\Models\User;
use App\Services\AiTextSeverityClient;
use App\Services\AiTrainingFeedbackExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiFeedbackLearningTest extends TestCase
{
    use RefreshDatabase;

    private string $exportDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['services.ai_text.mode' => 'MODEL_ADVISORY', 'services.routing.enabled' => false,
            'ai_learning.enabled' => false]);
        Event::fake([IncidentFeedUpdated::class]);
        Http::preventStrayRequests();
        $this->exportDirectory = storage_path('framework/testing/ai-feedback-'.Str::uuid());
        $directory = $this->exportDirectory;
        $this->app->instance(AiTrainingFeedbackExporter::class, new class($directory) extends AiTrainingFeedbackExporter
        {
            public function __construct(private string $testDirectory) {}

            public function directory(): string
            {
                return $this->testDirectory;
            }
        });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->exportDirectory);
        parent::tearDown();
    }

    public function test_routine_review_records_a_durable_original_snapshot_but_does_not_approve_training(): void
    {
        $admin = $this->admin();
        $report = $this->report(['ai_source' => 'text_model', 'severity' => 'Critical', 'ai_status' => 'complete',
            'ai_model_name' => 'fixture-model', 'ai_model_version' => 'v1', 'ai_confidence' => 90,
            'ai_probabilities' => ['minor' => 0.02, 'serious' => 0.03, 'critical' => 0.9, 'needs_review' => 0.05]]);
        $this->review($admin, $report);
        $feedback = AiTrainingFeedback::query()->sole();
        $this->assertSame($report->description, $feedback->description_snapshot);
        $this->assertSame('road_accident', $feedback->incident_type);
        $this->assertSame('critical', $feedback->original_ai_snapshot['label']);
        $this->assertSame('v1', $feedback->original_ai_snapshot['model_version']);
        $this->assertSame('minor', $feedback->reviewed_label);
        $this->assertSame($admin->id, $feedback->reviewer_id);
        $this->assertFalse($feedback->approved_for_training);
        $this->assertSame(0, $this->export()['counts']['eligible']);
    }

    public function test_training_approval_requires_a_reviewed_language_and_non_admin_cannot_capture_feedback(): void
    {
        $report = $this->report();
        $this->actingAs($this->admin())->post(route('reports.severity-review', $report), [
            ...$this->input(), 'approve_ai_training' => '1',
        ])->assertSessionHasErrors('training_language');
        $this->assertSame(0, AiTrainingFeedback::count());
        $civilian = User::factory()->create(['role' => 'civilian', 'is_admin' => false]);
        $this->actingAs($civilian)->postJson(route('reports.severity-review', $report), [
            ...$this->input(), 'approve_ai_training' => true, 'training_language' => 'en',
        ])->assertForbidden();
        $this->assertSame(0, AiTrainingFeedback::count());
    }

    public function test_approved_feedback_exports_automatically_with_pseudonyms_and_without_internal_notes_or_contact_fields(): void
    {
        $admin = $this->admin();
        $report = $this->report(['incident_type' => 'Fire Incident']);
        $this->review($admin, $report, ['approve_ai_training' => true, 'training_language' => 'en',
            'severity_review_notes' => 'Internal note with reporter contact private@example.com.']);
        $this->artisan('ai-learning:run')->assertSuccessful();
        $rows = $this->exportedRows();
        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame('fire_incident', $row['incident_type']);
        $this->assertSame('en', $row['language']);
        $this->assertTrue($row['approved_for_training']);
        $this->assertSame(hash('sha256', $report->description), $row['description_sha256']);
        $this->assertStringStartsWith('report_', $row['group_id']);
        $this->assertStringStartsWith('reviewer_', $row['reviewer']);
        $json = File::get($this->exportDirectory.'/feedback.jsonl');
        foreach (['private@example.com', $admin->name, $report->report_code, $report->reporter_name, 'reporter_contact', 'reporter_name'] as $private) {
            $this->assertStringNotContainsString($private, $json);
        }
        $manifest = json_decode(File::get($this->exportDirectory.'/manifest.json'), true);
        $this->assertFalse($manifest['contains_independent_holdout']);
        $this->assertFalse($manifest['active_model_changed']);
    }

    public function test_retries_are_idempotent_and_latest_review_can_revoke_or_correct_training_approval(): void
    {
        $admin = $this->admin();
        $report = $this->report();
        $approved = ['approve_ai_training' => true, 'training_language' => 'en'];
        $this->review($admin, $report, $approved);
        $this->review($admin, $report, $approved);
        $this->assertSame(1, AiTrainingFeedback::count());
        $this->assertSame(1, $this->export()['counts']['eligible']);
        $this->review($admin, $report, ['severity_review_label' => 'serious']);
        $this->assertSame(2, AiTrainingFeedback::count());
        $this->assertSame(1, AiTrainingFeedback::whereNotNull('superseded_at')->count());
        $this->assertSame(0, $this->export()['counts']['eligible']);
        $this->assertSame([], $this->exportedRows());
    }

    public function test_edited_description_category_and_deleted_reports_invalidate_approved_snapshots(): void
    {
        $admin = $this->admin();
        foreach (['description', 'incident_type', 'delete'] as $change) {
            $report = $this->report();
            $this->review($admin, $report, ['approve_ai_training' => true, 'training_language' => 'en']);
            if ($change === 'delete') {
                $report->delete();
            } else {
                $report->update([$change => $change === 'description' ? 'Changed report text requires a new review.' : 'Medical Emergency']);
            }
        }
        $this->assertSame(3, AiTrainingFeedback::whereNotNull('superseded_at')->count());
        $this->assertSame(0, $this->export()['counts']['eligible']);
    }

    public function test_unique_counts_preserve_duplicate_source_aliases_and_conflicting_reviews_for_the_worker(): void
    {
        $admin = $this->admin();
        foreach (['minor', 'minor'] as $label) {
            $this->review($admin, $this->report(), ['severity_review_label' => $label,
                'approve_ai_training' => true, 'training_language' => 'en']);
        }
        $manifest = $this->export();
        $this->assertSame(1, $manifest['counts']['eligible']);
        $this->assertSame(2, $manifest['counts']['exported_rows']);
        $this->assertSame(1, $manifest['counts']['duplicates']);
        $rows = $this->exportedRows();
        $this->assertCount(2, $rows);
        $this->assertCount(2, array_unique(array_column($rows, 'group_id')));
        $this->assertCount(2, array_unique(array_column($rows, 'feedback_id')));
        $this->assertCount(1, array_unique(array_column($rows, 'description')));
        $this->review($admin, $this->report(), ['severity_review_label' => 'critical',
            'approve_ai_training' => true, 'training_language' => 'en']);
        $manifest = $this->export();
        $this->assertSame(0, $manifest['counts']['eligible']);
        $this->assertSame(3, $manifest['counts']['conflicts']);
        $this->assertSame(3, $manifest['counts']['exported_rows']);
        $rows = $this->exportedRows();
        $this->assertCount(3, $rows);
        $this->assertCount(3, array_unique(array_column($rows, 'group_id')));
        $this->assertEqualsCanonicalizing(['minor', 'minor', 'critical'], array_column($rows, 'label'));
    }

    public function test_same_text_across_categories_reaches_worker_without_losing_either_source(): void
    {
        $admin = $this->admin();
        foreach (['Road Accident', 'Fire Incident'] as $category) {
            $this->review($admin, $this->report(['incident_type' => $category]), [
                'approve_ai_training' => true, 'training_language' => 'en',
            ]);
        }
        $manifest = $this->export();
        $rows = $this->exportedRows();
        $this->assertSame(2, $manifest['counts']['eligible']); // Preliminary per-category count, not worker eligibility.
        $this->assertSame(2, $manifest['counts']['exported_rows']);
        $this->assertCount(2, $rows);
        $this->assertCount(2, array_unique(array_column($rows, 'group_id')));
        $this->assertEqualsCanonicalizing(['road_accident', 'fire_incident'], array_column($rows, 'incident_type'));
        $this->assertStringContainsString('source-family validation is deferred to the worker', $manifest['eligible_count_scope']);
    }

    public function test_training_approval_cannot_label_text_changed_since_the_admin_opened_the_form(): void
    {
        $report = $this->report();
        $originalHash = hash('sha256', $report->description);
        $report->update(['description' => 'Different report text that requires fresh review.']);
        $this->actingAs($this->admin())->post(route('reports.severity-review', $report), [
            ...$this->input(), 'approve_ai_training' => true, 'training_language' => 'en',
            'training_description_sha256' => $originalHash, 'training_incident_type' => $report->incident_type,
        ])->assertStatus(409);
        $this->assertSame(0, AiTrainingFeedback::count());
        $this->assertNull($report->fresh()->severity);
    }

    public function test_contact_demo_and_unknown_category_examples_are_excluded_even_if_checked(): void
    {
        $admin = $this->admin();
        foreach ([
            ['description' => 'Small knee wound; contact 09171234567 for details.'],
            ['description' => 'Small knee wound; email person@example.com.'],
            ['description' => 'DEMO ONLY: A person has a small knee wound.'],
            ['incident_type' => 'Unsupported incident category'],
        ] as $attributes) {
            $this->review($admin, $this->report($attributes), ['approve_ai_training' => true, 'training_language' => 'en']);
        }
        $manifest = $this->export();
        $this->assertSame(0, $manifest['counts']['eligible']);
        $this->assertSame(4, $manifest['counts']['excluded']);
    }

    public function test_ai_client_passes_selected_category_and_never_guesses_an_unknown_category_or_failure_severity(): void
    {
        config(['services.ai_text.enabled' => true, 'services.ai_text.url' => 'http://ai.test']);
        Http::fake(['ai.test/predict/text' => Http::response([], 503)]);
        $client = app(AiTextSeverityClient::class);
        $result = $client->analyze('A person has a small knee wound.', 'Flood / Landslide');
        $this->assertNull($result['severity']);
        $this->assertSame('needs_review', $result['status']);
        Http::assertSent(fn ($request) => $request['incident_type'] === 'flood_landslide');
        $client->analyze('A person has a small knee wound.', 'Unknown category');
        Http::assertSentCount(1);
        $client->analyze('A person has a small knee wound.');
        Http::assertSent(fn ($request) => $request['incident_type'] === 'road_accident');
    }

    public function test_legacy_provisional_serious_fallback_is_not_an_operational_priority_two(): void
    {
        $report = $this->report(['severity' => 'Serious', 'ai_source' => 'text_review']);
        $this->assertNull($report->operational_severity);
        $this->assertSame('Needs review', $report->severity_display);
        $this->actingAs($this->admin())->get(route('reports.show', $report))->assertOk()
            ->assertSee('Needs responder review')->assertDontSee('Priority 2');
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true, 'role' => 'admin']);
    }

    private function report(array $attributes = []): IncidentReport
    {
        return IncidentReport::query()->create(array_merge([
            'report_code' => 'INC-FEEDBACK-'.Str::uuid(), 'reporter_name' => 'Private Reporter',
            'incident_type' => 'Road Accident', 'description' => 'A rider has a small knee wound and is alert.',
            'location_text' => 'Private recording site', 'severity' => null, 'status' => 'received',
            'channel' => 'Internet', 'transmission_type' => 'online', 'ai_source' => 'manual_only',
            'ai_status' => 'needs_review', 'ai_review_required' => true,
        ], $attributes));
    }

    private function input(): array
    {
        return ['severity_review_label' => 'minor', 'severity_review_notes' => 'Small knee wound and alert condition support this label.'];
    }

    private function review(User $admin, IncidentReport $report, array $attributes = []): void
    {
        $this->actingAs($admin)->post(route('reports.severity-review', $report), array_merge($this->input(), [
            'training_description_sha256' => hash('sha256', $report->description),
            'training_incident_type' => $report->incident_type,
        ], $attributes))
            ->assertSessionHasNoErrors()->assertRedirect();
        $report->refresh();
    }

    private function export(): array
    {
        return app(AiTrainingFeedbackExporter::class)->export();
    }

    private function exportedRows(): array
    {
        return array_map(fn ($line) => json_decode($line, true), array_filter(explode("\n", File::get($this->exportDirectory.'/feedback.jsonl'))));
    }
}
