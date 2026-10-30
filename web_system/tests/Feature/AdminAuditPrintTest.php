<?php

namespace Tests\Feature;

use App\Models\IncidentReportAudit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAuditPrintTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function entry(array $values = []): IncidentReportAudit
    {
        return IncidentReportAudit::query()->create(array_replace([
            'entry_key' => hash('sha256', uniqid('audit-print-', true)), 'report_code' => 'AUDIT-'.uniqid(),
            'incident_type' => 'Road Accident', 'transmission_type' => 'online', 'action' => 'coordination',
            'label' => 'Response recorded', 'actor_name' => 'Print Admin', 'actor_role' => 'Admin',
            'status_before' => 'received', 'status_after' => 'acknowledged',
            'severity_before' => 'Serious', 'severity_after' => 'Critical',
            'occurred_at' => '2026-10-23 16:00:00',
        ], $values));
    }

    private function signIn(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true, 'role' => 'admin']));
    }

    public function test_single_print_is_read_only_uses_historical_fields_and_excludes_private_context_and_notes_by_default(): void
    {
        $this->signIn();
        $audit = $this->entry(['actor_name' => 'Historical Admin', 'notes' => 'Sensitive recorded note',
            'context' => ['ip' => 'PRIVATE-IP-MARKER', 'model_version' => 'INTERNAL-MODEL-MARKER', 'token' => 'PRIVATE-TOKEN-MARKER']]);
        $other = $this->entry(['label' => 'Excluded action']);
        $before = $audit->fresh()->getRawOriginal();
        $copy = $this->get(route('admin.report-audit.print', ['audit_ids' => $audit->id]))->assertOk()
            ->assertSee('Admin audit history')->assertSee('Historical Admin')->assertSee('Oct 24, 2026 12:00:00 AM')
            ->assertSee('Original report deleted')->assertSee('Serious')->assertSee('Critical')
            ->assertDontSee('Sensitive recorded note')->assertDontSee('PRIVATE-IP-MARKER')
            ->assertDontSee('INTERNAL-MODEL-MARKER')->assertDontSee('PRIVATE-TOKEN-MARKER')->assertDontSee('Excluded action');
        $this->assertSame([$audit->id], $copy->viewData('entries')->modelKeys());
        $this->assertStringContainsString('no-store', $copy->headers->get('Cache-Control'));
        $this->assertSame($before, $audit->fresh()->getRawOriginal());
        $this->assertDatabaseCount('incident_report_audits', 2);
    }

    public function test_notes_are_opt_in_escaped_and_preserved_by_the_preview_form(): void
    {
        $this->signIn();
        $audit = $this->entry(['notes' => '<script>alert("audit")</script>', 'occurred_at' => null, 'imported' => true]);
        $this->get(route('admin.report-audit.print', ['audit_ids' => $audit->id, 'include_notes' => 1, 'print' => 1]))
            ->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert("audit")</script>', false)
            ->assertSee('name="audit_ids"', false)->assertSee('data-auto-print="true"', false)
            ->assertSee('Imported historical entry')->assertSee('Not recorded');
    }

    public function test_multiple_selected_actions_from_different_list_pages_print_in_stable_order(): void
    {
        $this->signIn();
        $entries = collect(range(1, 23))->map(fn ($i) => $this->entry(['report_code' => 'MULTI-'.$i]));
        $ids = [$entries[0]->id, $entries[11]->id, $entries[22]->id];
        $response = $this->get(route('admin.report-audit.print', ['audit_ids' => implode(',', $ids)]))->assertOk();
        $this->assertSame(array_reverse($ids), $response->viewData('entries')->modelKeys());
        $this->assertSame(3, substr_count($response->getContent(), 'data-printed-audit='));
        $this->assertDatabaseCount('incident_report_audits', 23);
    }

    public function test_filtered_print_includes_every_page_with_the_same_inclusive_manila_dates_and_admin_filters_as_the_modal(): void
    {
        $this->signIn();
        for ($i = 0; $i < 22; $i++) {
            $this->entry(['report_code' => 'MATCH-'.$i, 'actor_name' => 'Night Admin', 'transmission_type' => 'lora', 'node_id' => 'NODE_2']);
        }
        foreach (['2026-10-23 15:59:59', '2026-10-24 16:00:00', null] as $at) {
            $this->entry(['report_code' => 'BOUNDARY-EXCLUDED', 'actor_name' => 'Night Admin', 'transmission_type' => 'lora', 'occurred_at' => $at]);
        }
        $this->entry(['report_code' => 'MATCH-OTHER-ADMIN', 'actor_name' => 'Day Admin', 'transmission_type' => 'lora']);
        $filters = ['audit_from' => '2026-10-24', 'audit_to' => '2026-10-24', 'audit_admin' => 'night', 'audit_source' => 'lora', 'audit_action' => 'coordination', 'audit_search' => 'MATCH'];
        $this->getJson(route('admin.report-audit', $filters))->assertOk()->assertJsonPath('pagination.total', 22);
        $print = $this->get(route('admin.report-audit.print', $filters + ['audit_page' => 2]))->assertOk()
            ->assertSee('22 actions')->assertSee('2026-10-24 to 2026-10-24')->assertDontSee('BOUNDARY-EXCLUDED')->assertDontSee('MATCH-OTHER-ADMIN');
        $this->assertCount(22, $print->viewData('entries'));
        $this->assertSame(22, substr_count($print->getContent(), 'data-printed-audit='));
    }

    public function test_invalid_selection_and_filters_are_rejected_rather_than_silently_printing_another_set(): void
    {
        $this->signIn();
        $audit = $this->entry();
        foreach ([['audit_ids' => ''], ['audit_ids' => '0'], ['audit_ids' => '-1'], ['audit_ids' => '1,abc'],
            ['audit_ids' => '999999'], ['audit_ids' => $audit->id, 'audit_source' => 'lora'],
            ['audit_from' => '2026-02-31'], ['audit_from' => '2026-10-25', 'audit_to' => '2026-10-24'],
            ['audit_admin' => str_repeat('x', 101)], ['include_notes' => 'yes'], ['audit_ids' => implode(',', range(1, 501))]] as $bad) {
            $this->getJson(route('admin.report-audit.print', $bad))->assertUnprocessable();
        }
        $this->get(route('admin.report-audit.print', ['audit_ids' => $audit->id.','.$audit->id]))->assertOk()->assertViewHas('entries', fn ($rows) => $rows->count() === 1);
    }

    public function test_print_requires_admin_and_empty_filters_have_an_honest_empty_state(): void
    {
        $this->getJson(route('admin.report-audit.print'))->assertUnauthorized();
        foreach (['civilian', 'responder'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'is_admin' => false]))->get(route('admin.report-audit.print'))->assertRedirect(route('dashboard'));
        }
        $this->signIn();
        $this->get(route('admin.report-audit.print'))->assertOk()->assertSee('No audit actions match these filters.')->assertSee('disabled', false);
        $this->assertDatabaseCount('incident_report_audits', 0);
        $this->entry(['actor_name' => 'Admin % literal']);
        $this->entry();
        $this->getJson(route('admin.report-audit', ['audit_admin' => '%']))->assertOk()->assertJsonPath('pagination.total', 1);
    }
}
