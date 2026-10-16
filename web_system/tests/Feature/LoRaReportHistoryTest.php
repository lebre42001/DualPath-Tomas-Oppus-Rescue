<?php

namespace Tests\Feature;

use App\Events\IncidentFeedUpdated;
use App\Models\IncidentReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoRaReportHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_live_monitoring_only_displays_the_latest_three_and_keeps_all_reports_saved(): void
    {
        $admin = $this->admin();
        for ($number = 1; $number <= 7; $number++) {
            $this->report($number, $number === 6 ? ['transmission_type' => 'online'] : []);
        }
        $this->actingAs($admin)->get(route('monitoring', ['page' => 2]))
            ->assertOk()->assertViewHas('reports', function ($reports): bool {
                return $reports->pluck('report_code')->all() === ['LORA-HISTORY-7', 'LORA-HISTORY-6', 'LORA-HISTORY-5'];
            })->assertSee('All LoRa reports')->assertSee('Online')
            ->assertSee('monitoring-latest-only', false)
            ->assertDontSee('Current load by severity')->assertDontSee('Online and LoRa coverage')
            ->assertDontSee('Dispatch recommendation')
            ->assertDontSee('<h2 class="panel-title">Latest fatal incidents</h2>', false)
            ->assertDontSee('Field communication snapshot')->assertDontSee('Node heartbeat monitor')
            ->assertDontSee('Available admin receivers')->assertDontSee('Latest incident pages');
        $this->assertDatabaseCount('incident_reports', 7);
    }

    public function test_dedicated_history_paginates_every_lora_report_and_excludes_online_reports(): void
    {
        for ($number = 1; $number <= 12; $number++) {
            $this->report($number);
        }
        $this->report(99, ['transmission_type' => 'online', 'report_code' => 'ONLINE-ONLY-REPORT']);
        $this->actingAs($this->admin())->get(route('lora-reports.index'))
            ->assertOk()->assertViewHas('reports', fn ($reports) => $reports->total() === 12 && $reports->count() === 10)
            ->assertSee('LORA-HISTORY-12')->assertDontSee('LORA-HISTORY-2')
            ->assertDontSee('ONLINE-ONLY-REPORT')->assertSee('Next')->assertSee('Rows per page')
            ->assertSee('Report status toggle')->assertSee('data-report-modal-trigger', false)
            ->assertSee('dashboard-modal-source lora-review-detail', false)
            ->assertSee('data-report-detail-modal', false);

        $this->get(route('lora-reports.index', ['page' => 2]))
            ->assertOk()->assertViewHas('reports', fn ($reports) => $reports->count() === 2 && $reports->currentPage() === 2)
            ->assertSee('LORA-HISTORY-2')->assertSee('LORA-HISTORY-1')->assertDontSee('LORA-HISTORY-12');
    }

    public function test_node_status_search_and_page_size_filters_are_preserved_in_pagination(): void
    {
        for ($number = 1; $number <= 7; $number++) {
            $this->report($number, ['lora_sender_id' => 'NODE_10', 'reporter_name' => 'Matching sender']);
        }
        $this->report(8, ['lora_sender_id' => 'NODE_2']);
        $this->report(9, ['lora_sender_id' => 'NODE_10', 'status' => 'resolved']);
        $this->actingAs($this->admin())->get(route('lora-reports.index', [
            'node' => 'NODE_10', 'scope' => 'active', 'search' => 'Matching', 'per_page' => 5, 'sort' => 'oldest',
        ]))->assertOk()->assertViewHas('reports', function ($reports): bool {
            parse_str(parse_url($reports->nextPageUrl(), PHP_URL_QUERY), $query);
            return $reports->total() === 7 && $reports->count() === 5
                && $reports->first()->report_code === 'LORA-HISTORY-1'
                && $query['node'] === 'NODE_10' && $query['scope'] === 'active'
                && $query['search'] === 'Matching' && $query['per_page'] === '5' && $query['page'] === '2';
        })->assertDontSee('LORA-HISTORY-8')->assertDontSee('LORA-HISTORY-9');

        $this->get(route('lora-reports.index', ['scope' => 'closed']))
            ->assertOk()->assertViewHas('reports', fn ($reports) => $reports->total() === 1 && $reports->first()->status === 'resolved');
    }

    public function test_direct_report_link_opens_the_requested_modal_even_for_an_older_report(): void
    {
        $oldest = $this->report(1, ['description' => '<script>alert("unsafe")</script>', 'coordination_log' => [
            ['event' => 'lora_alert_ingested', 'created_at' => '2026-10-15T15:13:08+08:00', 'route' => 'NODE_2>NODE_1', 'hop' => 0],
        ]]);
        for ($number = 2; $number <= 15; $number++) {
            $this->report($number);
        }
        $this->actingAs($this->admin())->get(route('lora-reports.index', ['review' => $oldest->id]))
            ->assertOk()->assertViewHas('reports', fn ($reports) => $reports->first()->id === $oldest->id)
            ->assertSee('data-report-open-on-load', false)->assertSee('Original submitted time')
            ->assertSee('2026-10-15T15:13:08+08:00')->assertSee('Acknowledge')
            ->assertSee(route('reports.coordination', $oldest), false)
            ->assertDontSee('<script>alert("unsafe")</script>', false);
    }

    public function test_modal_acknowledgment_uses_the_existing_queue_and_returns_to_the_filtered_history(): void
    {
        $report = $this->report(1);
        $url = route('lora-reports.index', ['node' => 'NODE_2', 'page' => 1]);
        $this->actingAs($this->admin())->from($url)->post(route('reports.coordination', $report), [
            'status' => 'acknowledged', 'response_notes' => 'Your report is under review.', 'review_report_id' => $report->id,
        ])->assertSessionHasNoErrors()->assertRedirect($url);
        $this->assertSame('acknowledged', $report->fresh()->status);
        $this->assertNotNull($report->fresh()->lora_ack_queued_at);
        $this->assertSame('Your report is under review.', $report->fresh()->response_notes);
    }

    public function test_missing_decision_message_reopens_the_modal_and_does_not_change_the_report(): void
    {
        $report = $this->report(1);
        $url = route('lora-reports.index');
        $this->actingAs($this->admin())->from($url)->post(route('reports.coordination', $report), [
            'status' => 'responding', 'response_notes' => '', 'review_report_id' => $report->id,
        ])->assertSessionHasErrors('response_notes')->assertRedirect($url);
        $this->get($url)->assertOk()->assertSee('data-report-open-on-load', false);
        $this->assertSame('received', $report->fresh()->status);
    }

    public function test_civilians_and_guests_cannot_open_node_report_history(): void
    {
        $this->get(route('lora-reports.index'))->assertRedirect(route('login'));
        $civilian = User::factory()->create(['role' => 'civilian', 'is_admin' => false]);
        $this->actingAs($civilian)->get(route('lora-reports.index'))->assertRedirect(route('dashboard'));
    }

    public function test_lora_live_notifications_link_to_the_dedicated_review_modal(): void
    {
        $report = $this->report(1);
        $payload = (new IncidentFeedUpdated($report, 'created'))->broadcastWith();
        $this->assertSame(route('lora-reports.index', ['review' => $report->id]).'#incident-report-'.$report->id, $payload['report']['detail_url']);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    }

    private function report(int $number, array $overrides = []): IncidentReport
    {
        return IncidentReport::query()->create(array_replace([
            'report_code' => 'LORA-HISTORY-'.$number, 'reporter_name' => 'Field sender',
            'incident_type' => 'Road Accident', 'severity' => 'Serious', 'status' => 'received',
            'channel' => 'LoRa Mesh', 'transmission_type' => 'lora', 'location_text' => 'Node site',
            'description' => 'Test report description.', 'lora_sender_id' => 'NODE_2',
            'lora_sequence' => 'NODE_2-'.$number, 'lora_gateway_id' => 'NODE_1',
            'created_at' => now()->subMinutes(100 - $number),
        ], $overrides));
    }
}
