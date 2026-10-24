<?php

namespace Tests\Feature;

use App\Events\IncidentFeedUpdated;
use App\Models\IncidentReport;
use App\Models\IncidentReportAudit;
use App\Models\User;
use App\Services\IncidentReportAuditRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class IncidentReportAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['services.ai_text.mode' => 'MANUAL_ONLY', 'services.routing.enabled' => false]);
        Event::fake([IncidentFeedUpdated::class]);
    }

    public function test_online_and_lora_responses_and_severity_reviews_record_the_real_admin_and_previous_values(): void
    {
        $admin = $this->admin();
        foreach (['online', 'lora'] as $source) {
            $report = $this->report($source);
            $this->actingAs($admin)->post(route('reports.coordination', $report), [
                'status' => 'acknowledged', 'response_notes' => 'Review started.', 'actor_id' => 999999,
            ])->assertSessionHasNoErrors();
            $this->post(route('reports.severity-review', $report), [
                'severity_review_label' => 'critical', 'severity_review_notes' => 'Confirmed after reading the description.',
            ])->assertSessionHasNoErrors();
            $this->post(route('reports.coordination', $report), ['status' => 'resolved'])->assertSessionHasNoErrors();

            $audits = IncidentReportAudit::query()->where('incident_report_id', $report->id)->orderBy('id')->get();
            $this->assertCount(3, $audits);
            $this->assertSame([$admin->id], $audits->pluck('actor_id')->unique()->all());
            $this->assertSame($source, $audits[0]->transmission_type);
            $this->assertSame($source === 'lora' ? 'NODE_2' : null, $audits[0]->node_id);
            $this->assertSame('received', $audits[0]->status_before);
            $this->assertSame('acknowledged', $audits[0]->status_after);
            $this->assertSame('severity_review', $audits[1]->action);
            $this->assertSame('Critical', $audits[1]->severity_after);
            $this->assertSame('Confirmed after reading the description.', $audits[1]->notes);
            $this->assertFalse($audits[1]->imported);
            $this->assertSame('resolved', $audits[2]->status_after);
        }
        $this->get(route('admin.dashboard'))->assertOk()->assertSee('Admin report audit history')
            ->assertSee('NODE_2')->assertSee('Review started.')->assertSee('Confirmed after reading the description.');
    }

    public function test_deleted_online_and_lora_reports_keep_their_prior_audits_and_deletion_actor(): void
    {
        $admin = $this->admin();
        foreach (['online', 'lora'] as $source) {
            $report = $this->report($source);
            $this->actingAs($admin)->post(route('reports.coordination', $report), ['status' => 'acknowledged']);
            $this->delete(route('reports.destroy', $report))->assertRedirect();
            $audits = IncidentReportAudit::query()->where('report_code', $report->report_code)->orderBy('id')->get();
            $this->assertCount(2, $audits);
            $this->assertNull($audits[0]->incident_report_id);
            $this->assertSame('acknowledged', $audits[0]->status_after);
            $this->assertSame('deletion', $audits[1]->action);
            $this->assertSame($admin->id, $audits[1]->actor_id);
            $this->assertSame($source, $audits[1]->transmission_type);
            $this->assertDatabaseMissing('incident_reports', ['id' => $report->id]);
        }
        $this->get(route('admin.dashboard'))->assertOk()->assertSee('Deleted report')->assertSee('Report deleted');
    }

    public function test_legacy_admin_logs_are_imported_once_without_fabricating_missing_history(): void
    {
        $report = IncidentReport::withoutEvents(fn () => $this->report('lora', ['coordination_log' => [
            ['actor_role' => 'System', 'label' => 'Gateway heartbeat', 'occurred_at' => now()->toIso8601String()],
            ['actor_role' => 'Civilian', 'actor_name' => 'Witness', 'label' => 'Submitted report'],
            ['actor_role' => 'Admin', 'actor_name' => 'Historical admin', 'label' => 'Updated incident coordination', 'status' => 'acknowledged'],
        ]]));
        app(IncidentReportAuditRecorder::class)->sync($report, true);
        app(IncidentReportAuditRecorder::class)->sync($report, true);
        $audit = IncidentReportAudit::query()->sole();
        $this->assertTrue($audit->imported);
        $this->assertNull($audit->actor_id);
        $this->assertNull($audit->occurred_at);
        $this->assertNull($audit->severity_after);
        $this->assertNull($audit->status_before);
        $this->assertSame('Historical admin', $audit->actor_name);
    }

    public function test_source_search_and_action_filters_paginate_all_actions_including_older_reports(): void
    {
        $admin = $this->admin();
        $oldest = $this->report('lora', ['created_at' => now()->subYear(), 'report_code' => 'LORA-OLD-HISTORY']);
        $this->actingAs($admin)->post(route('reports.coordination', $oldest), ['status' => 'acknowledged']);
        for ($i = 0; $i < 20; $i++) {
            $report = $this->report('online');
            $this->post(route('reports.coordination', $report), ['status' => 'acknowledged']);
        }
        $page = $this->get(route('admin.dashboard'))->assertOk();
        $this->assertSame(21, $page->viewData('auditTrail')->total());
        $this->assertCount(6, $page->viewData('auditTrail'));
        $page->assertSee('data-audit-history-open', false)->assertDontSee('Admin report audit pagination');
        $filtered = $this->getJson(route('admin.report-audit', ['audit_source' => 'lora', 'audit_action' => 'coordination', 'audit_search' => 'LORA-OLD']))->assertOk()
            ->assertJsonPath('pagination.total', 1);
        $this->assertStringContainsString('LORA-OLD-HISTORY', $filtered->json('html'));
        $this->assertStringContainsString(route('lora-reports.index', ['review' => $oldest->id]), $filtered->json('html'));
        $this->get(route('admin.dashboard', ['audit_source' => 'online', 'audit_page' => 2]))->assertOk()
            ->assertViewHas('auditTrail', fn ($entries): bool => $entries->total() === 21 && $entries->currentPage() === 1 && $entries->count() === 6)
            ->assertSee('data-audit-history-auto-open', false);
        $this->getJson(route('admin.report-audit', ['audit_source' => 'online', 'audit_page' => 2]))->assertOk()
            ->assertJsonPath('pagination.total', 20)->assertJsonPath('pagination.page', 2);
        $this->getJson(route('admin.report-audit', ['audit_search' => '%']))->assertOk()->assertJsonPath('pagination.total', 0);
    }

    public function test_all_actions_use_ten_per_page_in_stable_order_and_preserve_deleted_history(): void
    {
        $this->actingAs($this->admin());
        $report = $this->report('online');
        for ($i = 0; $i < 22; $i++) {
            IncidentReportAudit::query()->create(['incident_report_id' => $i === 0 ? $report->id : null,
                'entry_key' => hash('sha256', 'list-'.$i), 'incident_type' => 'Road Accident',
                'report_code' => 'AUDIT-LIST-'.$i, 'transmission_type' => 'online', 'action' => 'coordination',
                'label' => 'Action '.$i, 'actor_name' => 'Admin <script>alert(1)</script>', 'actor_role' => 'Admin',
                'notes' => '<img src=x onerror=alert(1)>', 'occurred_at' => '2026-10-24 09:00:00']);
        }
        $first = $this->getJson(route('admin.report-audit'))->assertOk()->assertJsonPath('pagination.total', 22)
            ->assertJsonPath('pagination.lastPage', 3)->assertJsonPath('pagination.from', 1)->assertJsonPath('pagination.to', 10);
        $this->assertSame(10, substr_count($first->json('html'), 'data-command-audit-row'));
        $this->assertStringContainsString('AUDIT-LIST-21', $first->json('html'));
        $this->assertStringNotContainsString('AUDIT-LIST-11<', $first->json('html'));
        $this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $first->json('html'));
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $first->json('html'));
        $this->assertStringContainsString('Report deleted', $first->json('html'));
        $this->assertStringContainsString('no-store', $first->headers->get('Cache-Control'));
        $last = $this->getJson(route('admin.report-audit', ['audit_page' => 3]))->assertOk()
            ->assertJsonPath('pagination.from', 21)->assertJsonPath('pagination.to', 22);
        $this->assertSame(2, substr_count($last->json('html'), 'data-command-audit-row'));
        $this->assertStringContainsString(route('reports.index', ['review' => $report->id]), $last->json('html'));
        $this->get(route('admin.dashboard', ['audit_page' => 3]))->assertOk()
            ->assertViewHas('auditTrail', fn ($entries) => $entries->pluck('report_code')->all() === ['AUDIT-LIST-21', 'AUDIT-LIST-20', 'AUDIT-LIST-19', 'AUDIT-LIST-18', 'AUDIT-LIST-17', 'AUDIT-LIST-16']);
    }

    public function test_history_dates_are_inclusive_manila_days_and_combine_with_source_action_and_search(): void
    {
        $this->actingAs($this->admin());
        foreach (['2026-10-23 15:59:59', '2026-10-23 16:00:00', '2026-10-24 15:59:59', '2026-10-24 16:00:00', null] as $i => $date) {
            IncidentReportAudit::query()->create(['entry_key' => hash('sha256', 'date-'.$i), 'incident_type' => 'Road Accident',
                'report_code' => 'DATE-'.$i, 'transmission_type' => $i === 1 ? 'lora' : 'online',
                'node_id' => $i === 1 ? 'NODE_2' : null, 'action' => 'coordination', 'label' => 'Response recorded',
                'actor_name' => 'Date Admin', 'actor_role' => 'Admin', 'notes' => $i === 1 ? 'Specific response note' : null, 'occurred_at' => $date]);
        }
        $range = ['audit_from' => '2026-10-24', 'audit_to' => '2026-10-24'];
        $this->getJson(route('admin.report-audit', $range))->assertOk()->assertJsonPath('pagination.total', 2);
        $matched = $this->getJson(route('admin.report-audit', $range + ['audit_source' => 'lora', 'audit_action' => 'coordination', 'audit_search' => 'specific response']))
            ->assertOk()->assertJsonPath('pagination.total', 1);
        $this->assertStringContainsString('DATE-1', $matched->json('html'));
        $this->getJson(route('admin.report-audit', ['audit_to' => '2026-10-24']))->assertOk()->assertJsonPath('pagination.total', 3);
        $this->getJson(route('admin.report-audit', ['audit_from' => '2026-10-24']))->assertOk()->assertJsonPath('pagination.total', 3);
        $this->getJson(route('admin.report-audit', ['audit_search' => 'NODE_2']))->assertOk()->assertJsonPath('pagination.total', 1);
        foreach ([['audit_page' => 0], ['audit_source' => 'other'], ['audit_action' => 'other'], ['audit_search' => str_repeat('a', 101)], ['audit_from' => '2026-02-31'], ['audit_from' => '2026-10-25', 'audit_to' => '2026-10-24']] as $invalid) {
            $this->getJson(route('admin.report-audit', $invalid))->assertUnprocessable();
        }
    }

    public function test_history_endpoint_requires_admin_access(): void
    {
        $this->getJson(route('admin.report-audit'))->assertUnauthorized();
        foreach (['civilian', 'responder'] as $role) {
            $user = User::factory()->create(['role' => $role, 'is_admin' => false]);
            $this->actingAs($user)->getJson(route('admin.report-audit'))->assertRedirect(route('dashboard'));
        }
        $this->actingAs($this->admin())->getJson(route('admin.report-audit'))->assertOk()->assertJsonPath('pagination.total', 0);
        $this->assertDatabaseCount('incident_report_audits', 0);
    }

    public function test_opening_pages_or_receiving_a_report_does_not_fabricate_an_admin_action(): void
    {
        $report = $this->report('lora');
        $this->actingAs($this->admin())->get(route('admin.dashboard'))->assertOk();
        $this->get(route('lora-reports.index', ['review' => $report->id]))->assertOk();
        $this->assertDatabaseCount('incident_report_audits', 0);
        $civilian = User::factory()->create(['role' => 'civilian', 'is_admin' => false]);
        $this->actingAs($civilian)->get(route('admin.dashboard'))->assertRedirect(route('dashboard'));
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_admin' => true, 'name' => 'Audit Admin']);
    }

    private function report(string $source, array $values = []): IncidentReport
    {
        return IncidentReport::query()->create(array_replace([
            'report_code' => strtoupper($source).'-AUDIT-'.uniqid(), 'reporter_name' => 'Witness',
            'incident_type' => 'Road Accident', 'description' => 'A road incident requiring review.',
            'severity' => 'Serious', 'status' => 'received', 'transmission_type' => $source,
            'channel' => $source === 'lora' ? 'LoRa Mesh' : 'Internet', 'location_text' => 'Test site',
            'lora_sender_id' => $source === 'lora' ? 'NODE_2' : null, 'ai_source' => 'manual_only',
            'ai_status' => 'needs_review', 'ai_review_required' => true,
        ], $values));
    }
}
