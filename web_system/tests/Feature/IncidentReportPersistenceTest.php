<?php

namespace Tests\Feature;

use App\Events\IncidentFeedUpdated;
use App\Models\IncidentReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

class IncidentReportPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_submission_id_keeps_repeated_admin_posts_in_one_database_record(): void
    {
        Event::fake([IncidentFeedUpdated::class]);

        $admin = User::factory()->create([
            'role' => 'admin',
            'is_admin' => true,
        ]);
        $submissionId = (string) Str::uuid();
        $payload = [
            'submission_id' => $submissionId,
            'incident_type' => 'Vehicular Collision',
            'severity' => 'Serious',
            'transmission_type' => 'online',
            'location_text' => 'Bontoc Public Market',
            'latitude' => '10.354270',
            'longitude' => '124.970400',
            'description' => 'Two vehicles collided near the market entrance.',
        ];

        $firstResponse = $this->actingAs($admin)->post(route('reports.store'), $payload);
        $report = IncidentReport::query()->sole();

        $firstResponse->assertRedirect(route('reports.success', $report));

        $this->actingAs($admin)
            ->post(route('reports.store'), $payload)
            ->assertRedirect(route('reports.success', $report));

        $this->assertDatabaseCount('incident_reports', 1);
        $this->assertNotNull($report->ingest_key);
        Event::assertDispatchedTimes(IncidentFeedUpdated::class, 1);
    }

    public function test_api_idempotency_key_keeps_retries_in_one_database_record(): void
    {
        Event::fake([IncidentFeedUpdated::class]);

        $admin = User::factory()->create([
            'role' => 'admin',
            'is_admin' => true,
            'api_token' => hash('sha256', 'admin-ingest-token'),
        ]);
        $headers = [
            'Authorization' => 'Bearer admin-ingest-token',
            'Idempotency-Key' => 'dispatch-console-incident-1001',
        ];
        $payload = [
            'incident_type' => 'Flooding',
            'severity' => 'Serious',
            'transmission_type' => 'online',
            'location_text' => 'Barangay Poblacion',
            'latitude' => 10.354270,
            'longitude' => 124.970400,
            'description' => 'Flood water is entering homes near the highway.',
        ];

        $this->withHeaders($headers)
            ->postJson('/api/v1/reports', $payload)
            ->assertCreated()
            ->assertJsonPath('duplicate', false);

        $report = IncidentReport::query()->sole();

        $this->withHeaders($headers)
            ->postJson('/api/v1/reports', $payload)
            ->assertOk()
            ->assertJsonPath('duplicate', true)
            ->assertJsonPath('data.id', $report->id);

        $this->assertDatabaseCount('incident_reports', 1);
        $this->assertNotNull($report->ingest_key);
        Event::assertDispatchedTimes(IncidentFeedUpdated::class, 1);
    }
}
