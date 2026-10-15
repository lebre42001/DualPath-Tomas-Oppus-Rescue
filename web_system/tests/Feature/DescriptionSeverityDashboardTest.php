<?php

namespace Tests\Feature;

use App\Models\IncidentReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DescriptionSeverityDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['services.routing.enabled' => false]);
        Http::preventStrayRequests();
    }

    public function test_admin_and_monitoring_counts_separate_unassessed_reports_from_historical_severity(): void
    {
        $reports = $this->mixedReports();
        $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
        $expected = ['Minor' => 0, 'Serious' => 1, 'Critical / Fatal' => 1, 'needs_review' => 2];

        foreach (['admin.dashboard' => 'fatal_alerts', 'monitoring' => 'fatal'] as $route => $fatalKey) {
            $this->actingAs($admin)->get(route($route))->assertOk()
                ->assertViewHas('severityBreakdown', $expected)
                ->assertViewHas('stats', fn (array $stats): bool => $stats['needs_review'] === 2 && $stats[$fatalKey] === 1)
                ->assertViewHas('fatalAlerts', fn ($alerts): bool => $alerts->pluck('id')->sort()->values()->all()
                    === collect([$reports['photo']->id])->all());
        }

        // Reading either dashboard must retain the original assessment provenance.
        $this->assertDatabaseHas('incident_reports', [
            'id' => $reports['review']->id,
            'severity' => 'Serious',
            'ai_source' => 'text_review',
            'ai_confidence' => null,
        ]);
        $this->assertDatabaseHas('incident_reports', [
            'id' => $reports['historical']->id,
            'severity' => 'Serious',
            'ai_source' => null,
        ]);
        $this->assertDatabaseHas('incident_reports', [
            'id' => $reports['photo']->id,
            'severity' => 'Fatal',
            'ai_source' => 'python_model',
            'ai_confidence' => 91,
            'ai_model_version' => 'legacy-1',
        ]);
    }

    public function test_summary_api_exposes_display_provisional_and_score_semantics_without_rewriting_history(): void
    {
        $reports = $this->mixedReports();
        User::factory()->create([
            'role' => 'admin',
            'is_admin' => true,
            'api_token' => hash('sha256', 'dashboard-semantics-test-token'),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer dashboard-semantics-test-token')
            ->getJson('/api/v1/summary')->assertOk()
            ->assertJsonCount(4, 'recent_reports')
            ->assertJsonCount(4, 'map_points')
            ->assertJsonPath('summary.needs_review', 2)
            ->assertJsonPath('summary.fatal_reports', 1);

        $expected = [
            'review' => [null, 'Awaiting responder', true, 'No usable AI suggestion; responder review required', 'manual_only', null],
            'historical' => ['Serious', 'Serious', false, 'Confidence Pending', null, null],
            'text' => [null, 'Awaiting responder', true, 'Historical AI suggestion hidden in MANUAL_ONLY mode', 'manual_only', null],
            'photo' => ['Fatal', 'Fatal', false, 'Confidence 91%', 'python_model', 91],
        ];
        $recent = collect($response->json('recent_reports'))->keyBy('id');
        $points = collect($response->json('map_points'))->keyBy('id');

        foreach ($expected as $name => [$raw, $display, $provisional, $score, $source, $confidence]) {
            $id = $reports[$name]->id;
            foreach ([$recent[$id], $points[$id]] as $payload) {
                $this->assertSame($raw, $payload['severity']);
                $this->assertSame($display, $payload['severity_display']);
                $this->assertSame($provisional, $payload['severity_is_provisional']);
                $this->assertSame($score, $payload['ai_score_label']);
            }
            $this->assertSame($source, $recent[$id]['ai_source']);
            $this->assertSame($confidence, $recent[$id]['ai_confidence']);
        }

        $notifications = $response->json('notifications');
        $reviewLocation = $reports['review']->location_text;
        $this->assertContains('Road Accident - Awaiting responder - '.$reviewLocation, $notifications);
        $this->assertNotContains('Road Accident - Serious - '.$reviewLocation, $notifications);
        $this->assertContains('Road Accident - Awaiting responder - '.$reports['text']->location_text, $notifications);
        $this->assertContains('Road Accident - Fatal - '.$reports['photo']->location_text, $notifications);

        $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
        $this->actingAs($admin)->get(route('reports.severity', $reports['text']))->assertOk()
            ->assertSee('Historical AI suggestion — not operational')
            ->assertSee('Saved legacy value: Fatal (non-operational)')
            ->assertSee('Restricted audit history');
    }

    public function test_monitoring_recommendation_does_not_treat_provisional_storage_severity_as_assessed(): void
    {
        $civilian = User::factory()->create(['role' => 'civilian']);
        $report = $this->report($civilian, 'INC-UNASSESSED', [
            'ai_source' => 'text_review',
            'ai_status' => 'needs_review',
            'ai_review_required' => true,
        ]);
        $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);

        $this->actingAs($admin)->get(route('monitoring'))->assertOk()
            ->assertViewHas('priorityIncident', fn (IncidentReport $priority): bool => $priority->id === $report->id)
            ->assertViewHas('dispatchRecommendation', function (array $recommendation): bool {
                return $recommendation['priority'] === 'Needs responder review'
                    && $recommendation['response_type'] === 'Unassessed description; verify urgency';
            });
    }

    public function test_civilian_web_and_api_dashboards_keep_other_reports_and_operational_assessments_private(): void
    {
        $otherReports = $this->mixedReports();
        $civilian = User::factory()->create([
            'role' => 'civilian',
            'api_token' => hash('sha256', 'civilian-dashboard-test-token'),
        ]);
        $own = $this->report($civilian, 'INC-CIVILIAN-OWN', [
            'ai_source' => 'text_review',
            'ai_status' => 'needs_review',
            'ai_review_required' => true,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer civilian-dashboard-test-token')
            ->getJson('/api/v1/summary')->assertOk()
            ->assertJsonCount(1, 'recent_reports')
            ->assertJsonPath('recent_reports.0.id', $own->id)
            ->assertJsonPath('summary.active_alerts', 1)
            ->assertJsonPath('summary.my_reports', 1)
            ->assertJsonCount(0, 'map_points')
            ->assertJsonPath('notifications', [$own->report_code.' - Received'])
            ->assertJsonMissingPath('summary.fatal_reports')
            ->assertJsonMissingPath('summary.needs_review');

        foreach (['severity', 'severity_display', 'severity_is_provisional', 'ai_score_label', 'ai_source', 'ai_confidence', 'ai_summary'] as $field) {
            $response->assertJsonMissingPath('recent_reports.0.'.$field);
        }

        $page = $this->actingAs($civilian)->get(route('dashboard'))->assertOk()
            ->assertViewHas('dashboardMode', 'civilian')
            ->assertViewHas('reports', fn ($reports): bool => $reports->pluck('id')->all() === [$own->id])
            ->assertViewHas('fatalAlerts', fn ($alerts): bool => $alerts->isEmpty());

        foreach ($otherReports as $report) {
            $response->assertDontSee($report->report_code);
            $page->assertDontSee($report->report_code);
        }
    }

    /** @return array<string, IncidentReport> */
    private function mixedReports(): array
    {
        $civilian = User::factory()->create(['role' => 'civilian']);

        return [
            'review' => $this->report($civilian, 'INC-TEXT-REVIEW', [
                'ai_source' => 'text_review',
                'ai_status' => 'needs_review',
                'ai_review_required' => true,
                'created_at' => now()->subMinute(),
            ]),
            'historical' => $this->report($civilian, 'INC-HISTORICAL-SERIOUS', [
                'created_at' => now()->subMinutes(4),
            ]),
            'text' => $this->report($civilian, 'INC-TEXT-CRITICAL', [
                'severity' => 'Fatal',
                'ai_source' => 'text_model',
                'ai_confidence' => 90,
                'ai_review_required' => true,
                'created_at' => now()->subMinutes(2),
            ]),
            'photo' => $this->report($civilian, 'INC-LEGACY-PHOTO', [
                'severity' => 'Fatal',
                'ai_source' => 'python_model',
                'ai_confidence' => 91,
                'ai_model_name' => 'historical-image-model',
                'ai_model_version' => 'legacy-1',
                'created_at' => now()->subMinutes(3),
            ]),
        ];
    }

    private function report(User $reporter, string $code, array $attributes = []): IncidentReport
    {
        return IncidentReport::query()->forceCreate(array_merge([
            'report_code' => $code,
            'reported_by' => $reporter->id,
            'reporter_name' => $reporter->name,
            'incident_type' => 'Road Accident',
            'severity' => 'Serious',
            'status' => 'received',
            'channel' => 'Internet',
            'transmission_type' => 'online',
            'location_text' => 'Location '.$code,
            'latitude' => 10.354270,
            'longitude' => 124.970400,
            'description' => 'Road incident awaiting responder verification.',
            'ai_source' => null,
            'ai_status' => 'complete',
            'ai_confidence' => null,
            'ai_review_required' => false,
            'evidence_type' => 'none',
            'transmitted_at' => now(),
            'status_updated_at' => now(),
        ], $attributes));
    }
}
