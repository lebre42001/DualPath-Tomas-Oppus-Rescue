<?php

namespace Tests\Feature;

use App\Models\IncidentReport;
use App\Models\LoraNodeState;
use App\Support\LoraMeshNodeDirectory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LoRaMeshGatewayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.lora_ingest.token' => 'test-lora-token']);
    }

    public function test_gateway_heartbeat_marks_node_one_active_and_keeps_direct_peers(): void
    {
        $this->postJson('/api/v1/lora/heartbeat', [
            'node_id' => 'NODE_1',
            'gateway_id' => 'NODE_1',
            'route' => 'NODE_1',
            'peers' => ['node_2', 'NODE_3', 'NODE_1', 'UNKNOWN'],
            'battery' => 'NA',
            'hop' => 0,
            'ttl' => 0,
            'rssi' => -40,
            'packet_type' => 'HB',
        ], $this->gatewayHeaders())
            ->assertOk()
            ->assertJsonPath('nodeId', 'NODE_1')
            ->assertJsonPath('status', 'REACHABLE');

        $state = LoraNodeState::query()->where('node_id', 'NODE_1')->firstOrFail();
        $this->assertSame(['NODE_2', 'NODE_3'], $state->known_peers);
        $this->assertSame('NODE_1', $state->last_known_route);
    }

    public function test_network_summary_counts_each_node_independently_by_last_seen(): void
    {
        Carbon::setTestNow('2026-10-13 10:00:00');

        LoraNodeState::query()->create([
            'node_id' => 'NODE_1',
            'gateway_id' => 'NODE_1',
            'last_seen_at' => now()->subSeconds(5),
            'last_known_route' => 'NODE_1',
        ]);
        LoraNodeState::query()->create([
            'node_id' => 'NODE_3',
            'gateway_id' => 'NODE_1',
            'last_seen_at' => now()->subSeconds(10),
            'last_known_route' => 'NODE_3>NODE_1',
        ]);
        LoraNodeState::query()->create([
            'node_id' => 'NODE_4',
            'gateway_id' => 'NODE_1',
            'last_seen_at' => now()->subSeconds(45),
            'last_known_route' => 'NODE_4>NODE_3>NODE_1',
        ]);
        LoraNodeState::query()->create([
            'node_id' => 'NODE_5',
            'gateway_id' => 'NODE_1',
            'last_seen_at' => now()->subSeconds(8),
            'last_known_route' => 'NODE_5>NODE_3>NODE_1',
        ]);

        $response = $this->getJson('/api/v1/lora/network-summary', $this->gatewayHeaders())
            ->assertOk()
            ->assertJsonPath('activeCount', 3)
            ->assertJsonPath('expectedCount', 10)
            ->assertJsonPath('nodeTimeoutSeconds', 30);

        $mask = (int) $response->json('activeNodeBitmask');
        $this->assertNotSame(0, $mask & (1 << 0));
        $this->assertNotSame(0, $mask & (1 << 2));
        $this->assertSame(0, $mask & (1 << 3));
        $this->assertNotSame(0, $mask & (1 << 4));
    }

    public function test_mesh_directory_always_contains_node_one_through_node_ten_only(): void
    {
        LoraNodeState::query()->create([
            'node_id' => 'NODE_1',
            'gateway_id' => 'NODE_1',
            'last_seen_at' => now(),
            'last_known_route' => 'NODE_1',
        ]);
        LoraNodeState::query()->create([
            'node_id' => 'S01',
            'gateway_id' => 'NODE_1',
            'last_seen_at' => now(),
            'last_known_route' => 'S01>NODE_1',
        ]);

        $nodes = app(LoraMeshNodeDirectory::class)->all();

        $this->assertCount(10, $nodes);
        $this->assertSame('NODE_1', $nodes->first()['sender_id']);
        $this->assertSame('NODE_10', $nodes->last()['sender_id']);
        $this->assertFalse($nodes->contains(fn (array $node): bool => $node['sender_id'] === 'S01'));
        $this->assertSame('REACHABLE', $nodes->first()['status']);
    }

    public function test_connection_status_expires_and_recovers_from_actual_heartbeats(): void
    {
        $this->travelTo(now()->startOfSecond());
        $status = fn (): string => app(LoraMeshNodeDirectory::class)->all()->firstWhere('sender_id', 'NODE_2')['status'];

        $this->assertSame('UNAVAILABLE', $status());
        $heartbeat = ['node_id' => 'NODE_2', 'route' => 'NODE_2>NODE_1'];
        $this->postJson('/api/v1/lora/heartbeat', $heartbeat, $this->gatewayHeaders())->assertOk();
        $this->assertSame('REACHABLE', $status());
        $this->travel(30)->seconds();
        $this->assertSame('REACHABLE', $status());
        $this->travel(1)->seconds();
        $this->assertSame('UNREACHABLE', $status());
        $this->travel(89)->seconds();
        $this->assertSame('UNREACHABLE', $status());
        $this->travel(1)->seconds();
        $this->assertSame('UNAVAILABLE', $status());
        $this->postJson('/api/v1/lora/heartbeat', $heartbeat, $this->gatewayHeaders())->assertOk();
        $this->assertSame('REACHABLE', $status());
    }

    public function test_recent_gateway_discovery_waits_for_node_heartbeat_without_counting_it_reachable(): void
    {
        $this->travelTo(now()->startOfSecond());
        LoraNodeState::query()->create(['node_id' => 'NODE_2', 'last_seen_at' => now()->subWeek()]);
        $this->postJson('/api/v1/lora/heartbeat', [
            'node_id' => 'NODE_1', 'peers' => ['NODE_2', 'NODE_3'],
        ], $this->gatewayHeaders())->assertOk();

        $nodes = app(LoraMeshNodeDirectory::class)->all()->keyBy('sender_id');
        $this->assertSame('REACHABLE', $nodes['NODE_1']['status']);
        $this->assertSame('WAITING', $nodes['NODE_2']['status']);
        $this->assertSame('WAITING', $nodes['NODE_3']['status']);
        $this->assertFalse($nodes['NODE_2']['recently_heard']);
        $this->assertNull($nodes['NODE_3']['last_seen']);
        $this->assertStringContainsString('NODE_1', $nodes['NODE_2']['status_detail']);
        $this->getJson('/api/v1/lora/network-summary', $this->gatewayHeaders())
            ->assertOk()->assertJsonPath('activeCount', 1)->assertJsonPath('activeNodeBitmask', 1);

        $this->postJson('/api/v1/lora/heartbeat', ['node_id' => 'NODE_2'], $this->gatewayHeaders())->assertOk();
        $this->assertSame('REACHABLE', app(LoraMeshNodeDirectory::class)->all()->firstWhere('sender_id', 'NODE_2')['status']);
        $this->travel(31)->seconds();
        $nodes = app(LoraMeshNodeDirectory::class)->all()->keyBy('sender_id');
        $this->assertSame('UNREACHABLE', $nodes['NODE_2']['status']);
        $this->assertSame('UNAVAILABLE', $nodes['NODE_3']['status']);
    }

    public function test_old_peer_lists_and_sos_retained_peers_do_not_invent_reconnection(): void
    {
        $this->travelTo(now()->startOfSecond());
        $observer = LoraNodeState::query()->create([
            'node_id' => 'NODE_4', 'last_seen_at' => now()->subSeconds(31),
            'known_peers' => ['NODE_2'], 'last_packet_type' => 'HB',
        ]);
        $status = fn (): string => app(LoraMeshNodeDirectory::class)->all()->firstWhere('sender_id', 'NODE_2')['status'];
        $this->assertSame('UNAVAILABLE', $status());
        $observer->update(['last_seen_at' => now(), 'last_packet_type' => 'SOS']);
        $this->assertSame('UNAVAILABLE', $status());
        $observer->update(['last_packet_type' => 'HB']);
        $this->assertSame('WAITING', $status());
    }

    public function test_lost_node_waits_only_after_rediscovery_then_becomes_reachable_on_heartbeat(): void
    {
        $this->travelTo(now()->startOfSecond());
        $status = fn (): string => app(LoraMeshNodeDirectory::class)->all()->firstWhere('sender_id', 'NODE_2')['status'];
        $this->postJson('/api/v1/lora/heartbeat', ['node_id' => 'NODE_2'], $this->gatewayHeaders())->assertOk();
        $this->assertSame('REACHABLE', $status());
        $this->travel(31)->seconds();
        $this->assertSame('UNREACHABLE', $status());
        $this->postJson('/api/v1/lora/heartbeat', [
            'node_id' => 'NODE_1', 'peers' => ['NODE_2'],
        ], $this->gatewayHeaders())->assertOk();
        $this->assertSame('WAITING', $status());
        $this->postJson('/api/v1/lora/heartbeat', ['node_id' => 'NODE_2'], $this->gatewayHeaders())->assertOk();
        $this->assertSame('REACHABLE', $status());
    }

    public function test_mesh_view_uses_the_same_connection_status_for_gateway_and_node_rows(): void
    {
        $this->travelTo(now()->startOfSecond());
        $admin = \App\Models\User::factory()->create(['role' => 'admin', 'is_admin' => true]);
        LoraNodeState::query()->create(['node_id' => 'NODE_1', 'last_seen_at' => now()->subWeek()]);
        $this->actingAs($admin)->get('/monitoring/lora-mesh')
            ->assertOk()->assertSee('Reachable nodes')->assertSee('UNAVAILABLE')
            ->assertDontSee('UNREACHABLE')->assertDontSee('AWAITING')->assertDontSee('>ACTIVE<', false);
        $this->postJson('/api/v1/lora/heartbeat', ['node_id' => 'NODE_1'], $this->gatewayHeaders())->assertOk();
        $this->get('/monitoring/lora-mesh/nodes')
            ->assertOk()->assertSee('REACHABLE')->assertSee('data-active-node-count="1"', false);
    }

    public function test_invalid_gateway_token_is_rejected(): void
    {
        $this->postJson('/api/v1/lora/heartbeat', [
            'node_id' => 'NODE_1',
        ], ['X-STITCH-LORA-TOKEN' => 'wrong-token'])
            ->assertUnauthorized();
    }

    public function test_reviewed_lora_report_returns_review_message_and_records_delivery(): void
    {
        $report = IncidentReport::query()->create([
            'report_code' => 'LORA-REVIEW-TEST',
            'reporter_name' => 'Field Node 2',
            'incident_type' => 'LoRa Emergency Alert',
            'severity' => 'Serious',
            'status' => 'responding',
            'channel' => 'LoRa Mesh',
            'transmission_type' => 'lora',
            'location_text' => 'Field Node 2 test location',
            'description' => 'Test review acknowledgment.',
            'lora_sender_id' => 'NODE_2',
            'lora_sequence' => 'NODE_2-42',
            'lora_gateway_id' => 'NODE_1',
            'lora_ack_queued_at' => now(),
        ]);

        $response = $this->getJson(
            '/api/v1/lora/acks/pending?gatewayId=NODE_1',
            $this->gatewayHeaders()
        )
            ->assertOk()
            ->assertJsonPath('pending', true)
            ->assertJsonPath('msgId', 'NODE_2-42');

        $this->assertStringContainsString(
            'ACK|NODE_2-42|NODE_2|NODE_1|ACKNOWLEDGED|',
            (string) $response->json('packet')
        );
        $this->assertStringEndsWith(
            'The emergency team is responding to your report.',
            (string) $response->json('packet')
        );

        $this->postJson(
            '/api/v1/lora/acks/NODE_2-42/delivered',
            [],
            $this->gatewayHeaders()
        )
            ->assertOk()
            ->assertJsonPath('msgId', 'NODE_2-42');

        $this->assertNotNull($report->fresh()?->lora_ack_delivered_at);
    }

    private function gatewayHeaders(): array
    {
        return ['X-STITCH-LORA-TOKEN' => 'test-lora-token'];
    }
}
