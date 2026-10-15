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
            ->assertJsonPath('status', 'ACTIVE');

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
        $this->assertSame('ACTIVE', $nodes->first()['status']);
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
            'Your emergency report has been reviewed by the emergency team.',
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
