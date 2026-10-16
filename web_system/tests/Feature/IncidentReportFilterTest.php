<?php

namespace Tests\Feature;

use App\Models\IncidentReport;
use App\Models\User;
use App\Services\IncidentReportFilterQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class IncidentReportFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['services.ai_text.mode' => 'MODEL_ADVISORY']);
    }

    public function test_severity_filter_matches_the_single_current_category_and_legacy_fatal(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'role' => 'admin']);
        foreach (['Minor', 'Serious', 'Critical', 'Fatal'] as $label) {
            $this->report(['severity' => $label]);
        }
        $this->report(['severity' => null]); // Admin explicitly needs review.
        $this->report(['severity' => null, 'ai_source' => 'manual_only']);
        foreach (['minor', 'serious', 'critical', 'needs_review'] as $top) {
            $scores = array_fill_keys(['minor', 'serious', 'critical', 'needs_review'], 0.15);
            $scores[$top] = 0.55; // Below cutoff, still the displayed category.
            $this->report(['severity' => null, 'ai_source' => 'text_review', 'ai_probabilities' => $scores]);
        }
        $this->report(['severity' => 'Critical', 'ai_source' => 'text_rule_advisory',
            'ai_probabilities' => ['minor' => 0.1, 'serious' => 0.6, 'critical' => 0.2, 'needs_review' => 0.1]]);
        $this->report(['severity' => null, 'ai_source' => 'text_review',
            'ai_probabilities' => array_fill_keys(['minor', 'serious', 'critical', 'needs_review'], 0.25)]);
        $this->report(['severity' => null, 'ai_source' => 'text_review',
            'ai_probabilities' => ['minor' => 99, 'serious' => 1, 'critical' => 0, 'needs_review' => 0]]);
        foreach (['Minor', 'Serious', 'Critical', 'Needs review', 'Unassessed'] as $category) {
            $expected = IncidentReport::all()->filter(fn ($r) => $r->current_severity_category === $category)->pluck('id')->sort()->values()->all();
            $actual = $this->query(['severity' => $category], $admin)->orderBy('id')->pluck('id')->all();
            $this->assertSame($expected, $actual, $category);
        }
        $this->assertSame($this->query(['severity' => 'Critical'], $admin)->pluck('id')->all(),
            $this->query(['severity' => 'Fatal'], $admin)->pluck('id')->all());
        config(['services.ai_text.mode' => 'MANUAL_ONLY']);
        foreach (['Critical', 'Needs review', 'Unassessed'] as $category) {
            $expected = IncidentReport::all()->filter(fn ($r) => $r->current_severity_category === $category)->pluck('id')->sort()->values()->all();
            $this->assertSame($expected, $this->query(['severity' => $category], $admin)->orderBy('id')->pluck('id')->all());
        }
    }

    public function test_admin_correction_becomes_the_only_current_category(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'role' => 'admin']);
        $report = $this->report(['severity' => null, 'ai_source' => 'text_review', 'ai_model_name' => 'fixture',
            'ai_probabilities' => ['minor' => 0.1, 'serious' => 0.1, 'critical' => 0.6, 'needs_review' => 0.2]]);
        $this->assertSame('Critical', $report->current_severity_category);
        $this->actingAs($admin)->post(route('reports.severity-review', $report), [
            'severity_review_label' => 'serious', 'severity_review_notes' => 'Reviewed and corrected.'])->assertRedirect();
        $this->assertSame('Serious', $report->fresh()->current_severity_category);
        $this->assertSame('Critical', $report->fresh()->ai_top_category['label']);
        $this->assertSame([$report->id], $this->query(['severity' => 'Serious'], $admin)->pluck('id')->all());
        $this->assertSame([], $this->query(['severity' => 'Critical'], $admin)->pluck('id')->all());
    }

    public function test_search_matches_all_words_across_fields_and_treats_symbols_literally(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'role' => 'admin']);
        $match = $this->report(['description' => 'Truck stopped at zone_1, 50% marked!', 'location_text' => 'Hilaan']);
        $this->report(['description' => 'Truck stopped at zoneX1, 50 marked', 'location_text' => 'Elsewhere']);
        foreach (['  TRUCK   hilaan ', 'zone_1', '50%', 'marked!'] as $search) {
            $this->assertSame([$match->id], $this->query(['search' => $search], $admin)->pluck('id')->all());
        }
        $reporter = User::factory()->create(['name' => 'Linked Reporter']);
        $linked = $this->report(['reported_by' => $reporter->id, 'reporter_name' => '']);
        $this->assertSame([$linked->id], $this->query(['search' => 'linked reporter'], $admin)->pluck('id')->all());
        $this->assertSame([], $this->query(['search' => "' OR 1=1 --"], $admin)->pluck('id')->all());
    }

    public function test_combined_filters_pagination_and_export_use_identical_matching_records(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'role' => 'admin']);
        $matches = collect(range(1, 5))->map(fn () => $this->report(['severity' => null, 'ai_source' => 'text_review',
            'ai_probabilities' => ['minor' => 0.1, 'serious' => 0.2, 'critical' => 0.6, 'needs_review' => 0.1],
            'status' => 'received', 'transmission_type' => 'Online', 'location_text' => 'Hilaan', 'description' => 'Truck report.']));
        $this->report(['severity' => 'Minor', 'location_text' => 'Hilaan', 'description' => 'Truck report.']);
        $this->report(['severity' => 'Critical', 'status' => 'resolved', 'location_text' => 'Hilaan', 'description' => 'Truck report.']);
        $filters = ['search' => 'truck Hilaan', 'severity' => ' critical ', 'status' => 'pending', 'transmission' => 'ONLINE'];
        $response = $this->actingAs($admin)->get(route('reports.index', $filters))->assertOk();
        $this->assertSame(5, $response->viewData('reports')->total());
        $this->assertSame('Critical', $response->viewData('filters')['severity']);
        $pageTwo = $this->get(route('reports.index', $filters + ['page' => 2]))->assertOk()->viewData('reports');
        $this->assertCount(2, $pageTwo->items());
        $this->assertSame([], array_intersect($response->viewData('reports')->pluck('id')->all(), $pageTwo->pluck('id')->all()));
        $export = $filters + ['period' => 'custom', 'date_from' => now('Asia/Manila')->subDay()->toDateString(), 'date_to' => now('Asia/Manila')->toDateString()];
        $this->getJson(route('reports.export.preview', $export))->assertOk()->assertJsonPath('count', 5);
        $csv = $this->get(route('reports.export', $export + ['format' => 'csv']))->assertOk()->streamedContent();
        foreach ($matches as $match) { $this->assertStringContainsString($match->report_code, $csv); }
        $this->assertStringContainsString('Critical', $csv);
        $this->get(route('reports.index'))->assertOk()->assertSee('value="Critical"', false)->assertDontSee('value="Fatal"', false);
    }

    public function test_unknown_filters_are_rejected_instead_of_becoming_minor_or_online(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'role' => 'admin']);
        foreach (['severity', 'status', 'transmission', 'sort'] as $filter) {
            $this->actingAs($admin)->getJson(route('reports.index', [$filter => 'wrong']))->assertUnprocessable()->assertJsonValidationErrors($filter);
        }
    }

    public function test_admin_history_counts_and_exports_exclude_lora_while_its_own_tab_keeps_it(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'role' => 'admin']);
        $online = $this->report(['report_code' => 'ONLINE-VISIBLE']);
        $this->report(['report_code' => 'ONLINE-NORMALIZED', 'transmission_type' => ' Online ']);
        $lora = $this->report(['report_code' => 'LORA-SEPARATE', 'transmission_type' => 'lora', 'lora_sender_id' => 'NODE_2']);

        $this->actingAs($admin)->get(route('reports.index'))->assertOk()
            ->assertViewHas('reports', fn ($reports): bool => $reports->total() === 2)
            ->assertViewHas('stats', fn ($stats): bool => $stats['total'] === 2 && $stats['active'] === 2)
            ->assertSee($online->report_code)->assertDontSee($lora->report_code)
            ->assertDontSee('All transports')->assertSee('Online reports. Node reports are available in LoRa Reports.');
        foreach ([['search' => 'LORA-SEPARATE'], ['transmission' => 'lora'], ['review' => $lora->id]] as $filters) {
            $page = $this->get(route('reports.index', $filters))->assertOk()
                ->assertDontSee('id="incident-report-'.$lora->id.'"', false)
                ->assertViewHas('reports', fn ($reports): bool => ! $reports->contains('id', $lora->id));
            if (! isset($filters['review'])) {
                $this->assertSame(0, $page->viewData('reports')->total());
            }
        }
        $window = ['period' => 'custom', 'date_from' => now('Asia/Manila')->subDay()->toDateString(), 'date_to' => now('Asia/Manila')->toDateString()];
        $this->getJson(route('reports.export.preview', $window))->assertOk()->assertJsonPath('count', 2);
        $csv = $this->get(route('reports.export', $window + ['format' => 'csv']))->assertOk()->streamedContent();
        $this->assertStringContainsString($online->report_code, $csv);
        $this->assertStringNotContainsString($lora->report_code, $csv);
        $this->get(route('lora-reports.index'))->assertOk()->assertSee($lora->report_code)->assertDontSee($online->report_code);
        $this->get(route('monitoring'))->assertOk()->assertSee($lora->report_code)->assertSee($online->report_code);
        $this->assertDatabaseCount('incident_reports', 3);
    }

    public function test_search_cannot_expose_another_civilian_report(): void
    {
        $owner = User::factory()->create(['role' => 'civilian', 'is_admin' => false]);
        $other = User::factory()->create(['role' => 'civilian', 'is_admin' => false]);
        $mine = $this->report(['reported_by' => $owner->id, 'description' => 'Shared search words.']);
        $this->report(['reported_by' => $other->id, 'description' => 'Shared search words.']);
        $this->assertSame([$mine->id], $this->query(['search' => 'shared words', 'severity' => 'Critical'], $owner)->pluck('id')->all());
    }

    private function query(array $filters, User $user)
    {
        return app(IncidentReportFilterQuery::class)->build(Request::create('/reports', 'GET', $filters), $user);
    }

    private function report(array $attributes = []): IncidentReport
    {
        return IncidentReport::create(array_replace(['report_code' => 'FILTER-'.uniqid(), 'reporter_name' => 'Witness',
            'incident_type' => 'Road Accident', 'description' => 'A vehicle stopped.', 'location_text' => 'Test Road',
            'status' => 'received', 'transmission_type' => 'online', 'severity' => 'Minor', 'ai_source' => 'human_responder',
            'ai_status' => 'needs_review', 'coordination_log' => []], $attributes));
    }
}
