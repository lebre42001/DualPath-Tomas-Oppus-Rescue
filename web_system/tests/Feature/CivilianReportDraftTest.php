<?php

namespace Tests\Feature;

use App\Events\IncidentFeedUpdated;
use App\Models\IncidentReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class CivilianReportDraftTest extends TestCase
{
    use RefreshDatabase;

    private function civilian(): User
    {
        return User::factory()->create(['role' => 'civilian', 'account_verified_at' => now()]);
    }

    private function payload(User $user, string $id): array
    {
        return [
            ...$this->verifiedSelfie(UploadedFile::fake()->image('face.jpg', 480, 640), $user),
            'submission_id' => $id, 'description' => 'Vehicle collision near the market.',
            'location_text' => 'Market entrance', 'latitude' => 10.35, 'longitude' => 124.97,
            'incident_type' => 'Road Accident', 'evidence' => UploadedFile::fake()->image('road-scene.jpg', 640, 480),
        ];
    }

    public function test_interrupted_response_can_be_reconciled_and_retried_without_a_second_report(): void
    {
        Storage::fake();
        Event::fake([IncidentFeedUpdated::class]);
        $user = $this->civilian();
        $id = (string) Str::uuid();
        $payload = $this->payload($user, $id);
        $first = $this->actingAs($user)->postJson(route('reports.store'), $payload)
            ->assertCreated()->assertJsonPath('received', true)->assertJsonPath('duplicate', false);
        $report = IncidentReport::query()->sole();

        $this->getJson(route('reports.submission', $id))->assertOk()
            ->assertJsonPath('received', true)
            ->assertJsonPath('redirect_url', route('reports.success', $report))
            ->assertHeader('Cache-Control', 'no-store, private');

        // The original proof has already been consumed. A retry of the same
        // submission returns its receipt instead of consuming a second proof.
        $this->postJson(route('reports.store'), $payload)->assertOk()
            ->assertJsonPath('duplicate', true)->assertJsonPath('redirect_url', $first->json('redirect_url'));
        $this->assertDatabaseCount('incident_reports', 1);
        $this->assertCount(1, Storage::allFiles('incident-evidence'));
        $this->assertCount(1, Storage::allFiles('incident-selfies'));
        Event::assertDispatchedTimes(IncidentFeedUpdated::class, 1);
    }

    public function test_receipt_lookup_is_authenticated_scoped_to_reporter_and_never_creates_reports(): void
    {
        Storage::fake();
        Event::fake([IncidentFeedUpdated::class]);
        $owner = $this->civilian();
        $id = (string) Str::uuid();
        $this->getJson(route('reports.submission', $id))->assertUnauthorized();
        $this->actingAs($owner)->postJson(route('reports.store'), $this->payload($owner, $id))->assertCreated();
        $other = $this->civilian();
        $this->actingAs($other)->getJson(route('reports.submission', $id))->assertOk()->assertExactJson([
            'received' => false, 'redirect_url' => null, 'report_code' => null,
        ]);
        $this->getJson(route('reports.submission', (string) Str::uuid()))->assertOk()->assertJsonPath('received', false);
        $this->getJson('/reports/submissions/not-a-uuid')->assertNotFound();
        $this->assertDatabaseCount('incident_reports', 1);
    }

    public function test_resumed_drafts_still_require_gps_photo_and_new_live_verification(): void
    {
        $user = $this->civilian();
        $this->actingAs($user)->postJson(route('reports.store'), [
            'submission_id' => (string) Str::uuid(), 'description' => 'Saved report description.',
            'location_text' => 'Saved landmark',
        ])->assertUnprocessable()->assertInvalid(['latitude', 'longitude', 'evidence', 'selfie', 'liveness_proof']);
        $this->assertDatabaseCount('incident_reports', 0);
    }

    public function test_civilian_drafts_are_discoverable_in_home_reports_and_report_form(): void
    {
        $user = $this->civilian();
        foreach (['dashboard', 'reports.index', 'reports.create'] as $name) {
            $this->actingAs($user)->get(route($name))->assertOk()
                ->assertSee('data-civilian-drafts', false)
                ->assertSee('data-owner="'.$user->id.'"', false)
                ->assertSee('Report drafts');
        }
        $this->get(route('reports.create'))->assertSee('data-civilian-draft-save', false);
        $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
        $this->actingAs($admin)->get(route('reports.create'))->assertOk()->assertDontSee('data-civilian-drafts', false);
    }
}
