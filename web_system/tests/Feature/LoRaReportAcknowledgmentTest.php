<?php

namespace Tests\Feature;

use App\Models\IncidentReport;
use App\Models\User;
use App\Support\LoraMeshNodeDirectory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoRaReportAcknowledgmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.lora_ingest.token' => 'ack-test-token']);
    }

    public function test_mesh_only_shows_node_telemetry_and_report_review_stays_in_lora_history(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
        $second = $this->report('NODE_2');
        $tenth = $this->report('NODE_10');

        $this->actingAs($admin)->get(route('monitoring.lora-mesh'))
            ->assertOk()->assertSee('View LoRa reports')
            ->assertSee('NODE_2')->assertSee('NODE_10')->assertSee('Radio signal')
            ->assertDontSee($second->report_code)->assertDontSee($tenth->report_code)
            ->assertDontSee('Latest emergency report')->assertDontSee('No emergency report received')
            ->assertDontSee(route('reports.coordination', $tenth), false);

        $this->actingAs($admin)->get(route('monitoring.lora-mesh.nodes'))
            ->assertOk()->assertSee('NODE_2')->assertSee('Radio signal')
            ->assertDontSee($second->report_code)->assertDontSee($second->lora_sequence)
            ->assertDontSee('Latest emergency report')->assertDontSee('View report');

        $this->get(route('lora-reports.index'))
            ->assertOk()->assertSee($second->report_code)->assertSee($tenth->report_code)
            ->assertSee($second->lora_sequence)->assertSee('Acknowledge')
            ->assertSee(route('reports.coordination', $tenth), false)
            ->assertViewHas('reports', fn ($reports): bool => $reports->first()->id === $tenth->id);
        $this->assertNull($second->fresh()->lora_ack_queued_at);
    }

    public function test_older_node_report_is_not_lost_after_another_node_sends_over_one_hundred_reports(): void
    {
        $report = $this->report('NODE_10');
        for ($index = 0; $index < 101; $index++) {
            $this->report('NODE_2', (string) $index);
        }

        $node = app(LoraMeshNodeDirectory::class)->all()->firstWhere('sender_id', 'NODE_10');
        $this->assertSame($report->id, $node['latest_report']->id);
    }

    public function test_admin_acknowledgment_and_later_decision_queue_messages_but_identical_save_does_not(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
        $report = $this->report();
        $this->actingAs($admin)->post(route('reports.coordination', $report), ['status' => 'acknowledged'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('acknowledged', $report->fresh()->status);
        $this->assertNotNull($report->fresh()->lora_ack_queued_at);
        $this->getJson('/api/v1/lora/acks/pending?gatewayId=NODE_1', $this->headers())
            ->assertOk()->assertJsonPath('pending', true)
            ->assertJsonPath('msgId', $report->lora_sequence);
        $this->postJson('/api/v1/lora/acks/'.$report->lora_sequence.'/delivered', [], $this->headers())
            ->assertOk();

        $this->post(route('reports.coordination', $report), ['status' => 'acknowledged'])
            ->assertSessionHasNoErrors();
        $this->assertNotNull($report->fresh()->lora_ack_delivered_at);

        $this->post(route('reports.coordination', $report), [
            'status' => 'resolved', 'response_notes' => 'Rescue team completed the response.',
        ])->assertSessionHasNoErrors();
        $this->assertNull($report->fresh()->lora_ack_delivered_at);
        $this->assertSame('resolved', $report->fresh()->status);

        $this->post(route('reports.coordination', $report), ['status' => 'received'])
            ->assertSessionHasNoErrors();
        $this->assertNull($report->fresh()->lora_ack_queued_at);
        $this->getJson('/api/v1/lora/acks/pending?gatewayId=NODE_1', $this->headers())
            ->assertJsonPath('pending', false);
    }

    public function test_retried_sos_requeues_a_reviewed_report_without_creating_a_second_incident(): void
    {
        $payload = ['sender_id' => 'NODE_2', 'sequence' => 'NODE_2-42', 'description' => 'Test incident.'];
        $this->postJson('/api/v1/lora/alerts', $payload, $this->headers())->assertCreated();
        $report = IncidentReport::query()->firstOrFail();

        $this->postJson('/api/v1/lora/alerts', $payload, $this->headers())->assertOk();
        $this->assertNull($report->fresh()->lora_ack_queued_at);

        $report->update(['status' => 'acknowledged', 'lora_ack_queued_at' => now(), 'lora_ack_delivered_at' => now()]);
        $this->postJson('/api/v1/lora/alerts', $payload, $this->headers())
            ->assertOk()->assertJsonPath('duplicate', true)->assertJsonPath('data.lora_ack_delivered_at', null);
        $this->assertDatabaseCount('incident_reports', 1);
        $this->getJson('/api/v1/lora/acks/pending?gatewayId=NODE_1', $this->headers())
            ->assertJsonPath('pending', true)->assertJsonPath('msgId', 'NODE_2-42');
    }

    public function test_civilian_cannot_acknowledge_a_hardware_report(): void
    {
        $civilian = User::factory()->create(['role' => 'civilian', 'is_admin' => false]);
        $report = $this->report();
        $this->actingAs($civilian)->post(route('reports.coordination', $report), ['status' => 'acknowledged'])
            ->assertRedirect(route('dashboard'));
        $this->assertNull($report->fresh()->lora_ack_queued_at);
    }

    public function test_unicode_review_message_fits_radio_budget_after_all_nodes_relay_it(): void
    {
        $report = $this->report('NODE_10');
        $report->update([
            'status' => 'acknowledged', 'lora_ack_queued_at' => now(),
            'lora_sequence' => str_repeat('a', 40), 'response_notes' => str_repeat('✅ |\n', 60),
        ]);
        $response = $this->getJson('/api/v1/lora/acks/pending?gatewayId=NODE_1', $this->headers())->assertOk();
        $parts = explode('|', $response->json('packet'));
        $this->assertCount(9, $parts);
        $this->assertTrue(mb_check_encoding($parts[8], 'UTF-8'));
        $this->assertLessThanOrEqual(72, strlen($parts[8]));
        $parts[7] = implode('>', array_map(fn ($n) => 'NODE_'.$n, range(1, 10)));
        $this->assertLessThanOrEqual(245, strlen(implode('|', $parts)));
    }

    public function test_admin_can_find_exact_radio_message_id_in_report_search(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
        $report = $this->report();
        $this->actingAs($admin)->get(route('reports.index', ['transmission' => 'lora', 'search' => $report->lora_sequence]))
            ->assertOk()->assertSee($report->report_code)->assertSee('Acknowledge');
    }

    private function report(string $node = 'NODE_2', string $sequence = '42'): IncidentReport
    {
        return IncidentReport::query()->create([
            'report_code' => 'LORA-TEST-'.$node.'-'.$sequence,
            'reporter_name' => 'Field user', 'incident_type' => 'Road Accident',
            'severity' => 'Serious', 'status' => 'received', 'channel' => 'LoRa Mesh',
            'transmission_type' => 'lora', 'location_text' => 'Location not configured',
            'description' => 'Test incident.', 'lora_sender_id' => $node,
            'lora_sequence' => $node.'-'.$sequence, 'lora_gateway_id' => 'NODE_1',
        ]);
    }

    private function headers(): array
    {
        return ['X-STITCH-LORA-TOKEN' => 'ack-test-token'];
    }
}
