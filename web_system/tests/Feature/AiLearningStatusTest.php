<?php

namespace Tests\Feature;

use App\Models\AiTrainingFeedback;
use App\Models\IncidentReport;
use App\Models\User;
use App\Services\AiLearningStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiLearningStatusTest extends TestCase
{
    use RefreshDatabase;

    private string $statusDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        config(['ai_learning.enabled' => true, 'ai_learning.min_new' => 50, 'ai_learning.target_accuracy' => 0.8]);
        $this->statusDirectory = storage_path('framework/testing/ai-learning-status-'.Str::uuid());
        $path = $this->statusDirectory.'/latest_status.json';
        $this->app->instance(AiLearningStatus::class, new class($path) extends AiLearningStatus
        {
            public function __construct(private string $testPath) {}

            protected function statusFile(): string
            {
                return $this->testPath;
            }
        });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->statusDirectory);
        parent::tearDown();
    }

    public function test_missing_status_shows_a_target_and_waiting_state_without_contacting_inference(): void
    {
        $this->actingAs($this->admin())->get(route('admin.text-ai-test'))->assertOk()
            ->assertSee('Automatic learning status')->assertSee('Waiting for reviewed examples')
            ->assertSee('80% (target, not measured accuracy)')->assertSee('Activation is manual.')
            ->assertSee('Not yet checked')->assertDontSee('latest_status.json');
        Http::assertNothingSent();
    }

    public function test_waiting_state_exposes_counts_only_and_the_same_panel_is_available_after_predict(): void
    {
        $this->writeStatus(['schema_version' => 1, 'status' => 'waiting_for_reviewed_examples',
            'conversion' => ['eligible' => 3], 'new_examples' => 2, 'description' => 'PRIVATE REPORT TEXT',
            'reviewer' => 'PRIVATE REVIEWER', 'candidate' => 'C:/private/candidate/path']);
        $admin = $this->admin();
        $report = IncidentReport::query()->create(['report_code' => 'STATUS-'.Str::uuid(), 'reporter_name' => 'Private Name',
            'incident_type' => 'Road Accident', 'description' => 'PRIVATE REPORT TEXT', 'location_text' => 'Private address',
            'status' => 'received', 'channel' => 'Internet', 'transmission_type' => 'online']);
        foreach ([false, true] as $approved) {
            AiTrainingFeedback::query()->create(['incident_report_id' => $report->id, 'reviewer_id' => $admin->id,
                'fingerprint' => hash('sha256', (string) $approved), 'description_sha256' => hash('sha256', $report->description),
                'description_snapshot' => $report->description, 'incident_type' => 'road_accident', 'reviewed_label' => 'minor',
                'review_scope' => 'description_only', 'review_method' => 'human_assessment', 'review_reason' => 'PRIVATE REASON',
                'approved_for_training' => $approved, 'language' => 'en', 'reviewed_at' => now()]);
        }
        $status = app(AiLearningStatus::class)->summary();
        $this->assertSame(2, $status['captured']);
        $this->assertSame(1, $status['approved']);
        $this->assertSame(3, $status['eligible']);
        $this->assertSame(2, $status['new_examples']);
        $this->actingAs($admin)->get(route('admin.text-ai-test'))->assertOk()
            ->assertDontSee('PRIVATE REPORT TEXT')->assertDontSee('PRIVATE REASON')
            ->assertDontSee('PRIVATE REVIEWER')->assertDontSee('C:/private/candidate/path');
        Http::assertNothingSent();

        Http::fake(['*' => Http::response([], 503)]);
        $this->post(route('admin.text-ai-test.predict'), ['description' => 'A fictional collision happened.'])
            ->assertOk()->assertSee('Automatic learning status')->assertSee('Waiting for reviewed examples');
    }

    public function test_ready_candidate_never_claims_active_model_accuracy_or_automatic_release(): void
    {
        $this->writeStatus(['schema_version' => 1, 'status' => 'candidate_ready_for_release_review',
            'conversion' => ['eligible' => 80], 'new_examples' => 55,
            'acceptance_gate' => ['meets_configured_targets' => true, 'target_accuracy_and_macro_f1' => 0.8],
            'activated' => true, 'reason' => '<script>PRIVATE DATA</script>']);
        $this->actingAs($this->admin())->get(route('admin.text-ai-test'))->assertOk()
            ->assertSee('Candidate requires release review')->assertSee('Activation is manual.')
            ->assertSee('target, not measured accuracy')->assertDontSee('PRIVATE DATA')
            ->assertDontSee('Active model accuracy')->assertDontSee('Model activated');
    }

    public function test_malformed_oversized_and_unverified_ready_status_are_sanitized(): void
    {
        $this->actingAs($this->admin());
        foreach (['{malformed', str_repeat('x', 65537), json_encode(['schema_version' => 1,
            'status' => 'PRIVATE STATUS', 'new_examples' => -2, 'conversion' => 'PRIVATE CONVERSION'])] as $body) {
            File::ensureDirectoryExists($this->statusDirectory);
            File::put($this->statusDirectory.'/latest_status.json', $body);
            $this->get(route('admin.text-ai-test'))->assertOk()->assertSee('Learning status unavailable')
                ->assertDontSee('PRIVATE STATUS')->assertDontSee('PRIVATE CONVERSION');
        }
        $this->writeStatus(['schema_version' => 1, 'status' => 'candidate_ready_for_release_review']);
        $this->get(route('admin.text-ai-test'))->assertOk()->assertSee('Candidate requires review')
            ->assertDontSee('Candidate requires release review');
    }

    public function test_missing_feedback_migration_does_not_block_the_testing_form_and_civilians_cannot_view_status(): void
    {
        Schema::drop('ai_training_feedback');
        $this->actingAs($this->admin())->get(route('admin.text-ai-test'))->assertOk()
            ->assertSee('Try an emergency description')->assertSee('Unavailable');
        $civilian = User::factory()->create(['is_admin' => false, 'role' => 'civilian']);
        $this->actingAs($civilian)->get(route('admin.text-ai-test'))->assertRedirect(route('dashboard'));
        Http::assertNothingSent();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    }

    private function writeStatus(array $status): void
    {
        File::ensureDirectoryExists($this->statusDirectory);
        File::put($this->statusDirectory.'/latest_status.json', json_encode($status, JSON_THROW_ON_ERROR));
    }
}
