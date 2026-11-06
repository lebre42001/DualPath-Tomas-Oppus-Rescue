<?php

namespace Tests\Feature;

use App\Events\IncidentFeedUpdated;
use App\Models\IncidentReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class CivilianReportLibraryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Event::fake([IncidentFeedUpdated::class]);
    }

    private function civilian(): User
    {
        return User::factory()->create(['role' => 'civilian', 'account_verified_at' => now()]);
    }

    private function report(User $owner, string $code, array $data = []): IncidentReport
    {
        return IncidentReport::query()->create([
            'reported_by' => $owner->id, 'reporter_name' => $owner->name, 'report_code' => $code, 'incident_type' => 'Road Accident',
            'severity' => 'Minor', 'status' => 'received', 'channel' => 'Internet', 'transmission_type' => 'online',
            'location_text' => 'Market entrance', 'description' => 'Vehicle collision near market.', ...$data,
        ]);
    }

    public function test_collection_is_owner_scoped_stable_paginated_and_never_exposes_internal_fields(): void
    {
        $owner = $this->civilian();
        $this->report($this->civilian(), 'SECRET-OTHER');
        $ids = [];
        for ($i = 1; $i <= 23; $i++) {
            $ids[] = $this->report($owner, 'OWN-'.$i, ['ai_error_message' => 'PRIVATE-AI-ERROR'])->id;
        }
        $first = $this->actingAs($owner)->getJson(route('reports.civilian-collection'))->assertOk()
            ->assertJsonCount(10, 'reports')->assertJsonPath('pagination.total', 23)
            ->assertJsonPath('pagination.lastPage', 3)->assertJsonPath('reports.0.id', $ids[22])
            ->assertDontSee('SECRET-OTHER')->assertDontSee('PRIVATE-AI-ERROR');
        $this->assertStringContainsString('no-store', $first->headers->get('Cache-Control'));
        $this->getJson(route('reports.civilian-collection', ['page' => 2]))->assertOk()
            ->assertJsonPath('reports.0.id', $ids[12])->assertJsonPath('pagination.from', 11);
        $this->getJson(route('reports.civilian-collection', ['page' => 3]))->assertOk()
            ->assertJsonCount(3, 'reports')->assertJsonPath('pagination.to', 23);
        $this->getJson(route('reports.civilian-collection', ['page' => 99]))->assertOk()->assertJsonCount(0, 'reports');
        $this->getJson(route('reports.civilian-collection', ['sort' => 'oldest']))->assertOk()->assertJsonPath('reports.0.id', $ids[0]);
    }

    public function test_search_status_and_inclusive_philippine_submission_dates_combine(): void
    {
        $owner = $this->civilian();
        foreach (['2026-11-06 15:59:59', '2026-11-06 16:00:00', '2026-11-07 15:59:59', '2026-11-07 16:00:00'] as $i => $date) {
            $report = $this->report($owner, 'BOUNDARY-'.$i, ['location_text' => 'Road_2 50% market', 'status' => 'resolved']);
            $report->forceFill(['created_at' => $date])->saveQuietly();
        }
        $this->actingAs($owner)->getJson(route('reports.civilian-collection', ['from' => '2026-11-07', 'to' => '2026-11-07', 'search' => '50% Road_2', 'status' => 'resolved']))
            ->assertOk()->assertJsonPath('pagination.total', 2)->assertJsonPath('reports.0.code', 'BOUNDARY-2');
        $this->getJson(route('reports.civilian-collection', ['search' => 'missing%']))->assertOk()->assertJsonPath('pagination.total', 0);
        $this->getJson(route('reports.civilian-collection', ['status' => 'responding']))->assertOk()->assertJsonPath('pagination.total', 0);
        foreach ([['from' => '2026-02-31'], ['from' => '2026-11-08', 'to' => '2026-11-07'], ['page' => 0], ['status' => 'forged'], ['search' => str_repeat('a', 181)]] as $invalid) {
            $this->getJson(route('reports.civilian-collection', $invalid))->assertUnprocessable();
        }
    }

    public function test_status_view_orders_by_latest_recorded_status_and_details_require_ownership(): void
    {
        $owner = $this->civilian();
        $first = $this->report($owner, 'FIRST', ['status' => 'acknowledged', 'status_updated_at' => now()->addMinute(), 'response_notes' => 'Team is reviewing.', 'coordination_log' => [['secret' => 'INTERNAL-ONLY']]]);
        $this->report($owner, 'SECOND');
        $other = $this->report($this->civilian(), 'OTHER');
        $this->actingAs($owner)->getJson(route('reports.civilian-collection', ['view' => 'status']))->assertOk()->assertJsonPath('reports.0.id', $first->id);
        $this->getJson(route('reports.civilian-details', $first))->assertOk()
            ->assertJsonPath('report.response', 'Team is reviewing.')->assertJsonPath('report.statusLabel', 'Acknowledged')
            ->assertJsonPath('report.showUrl', route('reports.show', $first))->assertDontSee('INTERNAL-ONLY');
        $this->getJson(route('reports.civilian-details', $other))->assertNotFound();
        $this->getJson(route('reports.civilian-details', 999999))->assertNotFound();
    }

    public function test_private_routes_require_civilian_and_shared_buttons_and_modals_render(): void
    {
        $owner = $this->civilian();
        $report = $this->report($owner, 'OWN');
        foreach ([route('reports.civilian-collection'), route('reports.civilian-details', $report)] as $url) {
            $this->getJson($url)->assertUnauthorized();
            $this->actingAs(User::factory()->create(['role' => 'responder', 'is_admin' => true]))->getJson($url)->assertForbidden();
            $this->app['auth']->forgetGuards();
        }
        $this->actingAs($owner)->get(route('dashboard'))->assertOk()->assertSee('data-civilian-library-open="status"', false);
        $this->get(route('reports.index'))->assertOk()->assertSee('data-civilian-library-open="history"', false)
            ->assertSee('data-library-filters', false)->assertSee('data-library-pages', false)->assertSee('data-library-detail', false)
            ->assertDontSee('Report feed pagination');
        $this->get(route('reports.drafts'))->assertOk()->assertSee('data-civilian-library-open="drafts"', false);
    }
}
