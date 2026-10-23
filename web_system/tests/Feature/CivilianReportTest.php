<?php

namespace Tests\Feature;

use App\Models\IncidentReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CivilianReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_report_list_is_scoped_to_the_account_and_paginated(): void
    {
        $account = $this->civilian();
        $ids = [];
        for ($i = 1; $i <= 17; $i++) $ids[] = $this->report($account, 'INC-OWN-'.$i)->id;
        $this->report($this->civilian(), 'INC-OTHER-CIVILIAN');
        $this->actingAs($this->admin());
        $first = $this->getJson('/civilian-accounts/'.$account->id.'/reports')
            ->assertOk()->assertJsonCount(15, 'reports')->assertJsonPath('pagination.total', 17)
            ->assertJsonPath('pagination.lastPage', 2)->assertJsonPath('account.name', $account->name)
            ->assertJsonMissingPath('reports.0.evidence_path')->assertJsonMissingPath('reports.0.reporter_selfie_path');
        $second = $this->getJson('/civilian-accounts/'.$account->id.'/reports?page=2')
            ->assertOk()->assertJsonCount(2, 'reports');
        $this->assertSame(array_reverse($ids), array_merge(array_column($first->json('reports'), 'id'), array_column($second->json('reports'), 'id')));
        $this->assertStringContainsString('no-store', $first->headers->get('Cache-Control'));
    }

    public function test_print_all_includes_every_page_and_only_the_selected_accounts_reports(): void
    {
        $account = $this->civilian();
        for ($i = 1; $i <= 17; $i++) $this->report($account, 'INC-PRINT-'.$i);
        $this->report($this->civilian(), 'INC-SECRET-OTHER');
        $response = $this->actingAs($this->admin())->get('/civilian-accounts/'.$account->id.'/reports/print?page=1')
            ->assertOk()->assertViewHas('reports', fn ($reports) => $reports->count() === 17)
            ->assertSee('INC-PRINT-1')->assertSee('INC-PRINT-17')->assertDontSee('INC-SECRET-OTHER')
            ->assertSee('Asia/Manila')->assertSee('Location')->assertSee('GPS: 10.300000, 124.900000')
            ->assertSee('Under review')->assertSee('Serious')->assertSee('Assigned responder')
            ->assertSee('Response notes')->assertDontSee('private-selfie.jpg');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertDatabaseCount('incident_reports', 18);
    }

    public function test_single_report_print_cannot_access_another_accounts_report(): void
    {
        $account = $this->civilian();
        $report = $this->report($account, 'INC-SINGLE-SELECTED');
        $this->report($account, 'INC-SINGLE-EXCLUDED');
        $other = $this->report($this->civilian(), 'INC-PRIVATE-OTHER');
        $this->actingAs($this->admin())->get('/civilian-accounts/'.$account->id.'/reports/print?report='.$report->id)
            ->assertOk()->assertViewHas('reports', fn ($reports) => $reports->count() === 1)
            ->assertSee('INC-SINGLE-SELECTED')->assertDontSee('INC-SINGLE-EXCLUDED')->assertDontSee('INC-PRIVATE-OTHER');
        $this->get('/civilian-accounts/'.$account->id.'/reports/print?report='.$other->id)->assertNotFound();
        $this->get('/civilian-accounts/'.$account->id.'/reports/print?report=999999')->assertNotFound();
    }

    public function test_reports_and_printing_require_admin_and_civilian_target(): void
    {
        $account = $this->civilian();
        foreach (['/reports', '/reports/print'] as $suffix) {
            $this->getJson('/civilian-accounts/'.$account->id.$suffix)->assertUnauthorized();
        }
        foreach (['civilian', 'responder'] as $role) {
            $user = User::factory()->create(['role' => $role, 'is_admin' => false]);
            foreach (['/reports', '/reports/print'] as $suffix) {
                $this->actingAs($user)->getJson('/civilian-accounts/'.$account->id.$suffix)->assertForbidden();
            }
        }
        $admin = $this->admin();
        foreach (['/reports', '/reports/print'] as $suffix) {
            $this->actingAs($admin)->getJson('/civilian-accounts/'.$admin->id.$suffix)->assertNotFound();
        }
    }

    public function test_empty_history_invalid_requests_and_html_are_handled(): void
    {
        $account = $this->civilian();
        $this->actingAs($this->admin())->getJson('/civilian-accounts/'.$account->id.'/reports')
            ->assertOk()->assertJsonPath('pagination.total', 0)->assertJsonCount(0, 'reports');
        $this->get('/civilian-accounts/'.$account->id.'/reports/print')->assertOk()->assertSee('has not submitted any reports');
        $this->getJson('/civilian-accounts/'.$account->id.'/reports?page=0')->assertUnprocessable();
        $this->getJson('/civilian-accounts/'.$account->id.'/reports/print?report=abc')->assertUnprocessable();
        $this->report($account, 'INC-ESCAPED', ['description' => '<img src=x onerror=alert(1)>']);
        $this->get('/civilian-accounts/'.$account->id.'/reports/print')->assertOk()
            ->assertSee('<img src=x onerror=alert(1)>')->assertDontSee('<img src=x onerror=alert(1)>', false);
        $this->get('/civilian-accounts')->assertOk()->assertSee('data-civilian-reports-url', false);
    }

    public function test_dates_and_unconfirmed_severity_are_presented_without_claiming_responder_confirmation(): void
    {
        $account = $this->civilian();
        $this->report($account, 'INC-REVIEW', ['severity' => null, 'ai_source' => 'manual_only', 'ai_review_required' => true]);
        $this->actingAs($this->admin())->getJson('/civilian-accounts/'.$account->id.'/reports')->assertOk()
            ->assertJsonPath('reports.0.submittedAt', 'Oct 23, 2026 05:00 PM')
            ->assertJsonPath('reports.0.severity', 'Unassessed')->assertJsonPath('reports.0.reviewRequired', true);
        $this->get('/civilian-accounts/'.$account->id.'/reports/print')->assertOk()->assertSee('responder review required');
    }

    private function report(User $account, string $code, array $overrides = []): IncidentReport
    {
        $report = IncidentReport::query()->create(array_merge([
            'reported_by' => $account->id, 'report_code' => $code, 'reporter_name' => $account->name,
            'incident_type' => 'Road Accident', 'severity' => 'Serious', 'status' => 'acknowledged',
            'channel' => 'Internet', 'transmission_type' => 'online', 'location_text' => 'Test road',
            'latitude' => '10.300000', 'longitude' => '124.900000', 'description' => 'Incident description',
            'ai_source' => 'human_responder', 'ai_review_required' => false, 'assigned_responder_id' => $account->id,
            'response_notes' => 'Responders contacted.', 'reporter_selfie_path' => 'private-selfie.jpg',
        ], $overrides));
        $report->forceFill(['created_at' => '2026-10-23 09:00:00', 'updated_at' => '2026-10-23 09:00:00'])->save();
        return $report;
    }

    private function civilian(): User
    {
        return User::factory()->create(['role' => 'civilian', 'is_admin' => false]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    }
}
