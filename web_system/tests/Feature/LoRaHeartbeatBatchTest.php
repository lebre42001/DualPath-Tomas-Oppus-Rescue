<?php

namespace Tests\Feature;

use App\Models\LoraNodeState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LoRaHeartbeatBatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_authenticated_request_updates_all_ten_nodes_and_preserves_capture_age(): void
    {
        Carbon::setTestNow('2026-10-29 12:00:00');
        config(['services.lora_ingest.token' => 'test-batch']);
        $nodes = [];
        foreach (range(1, 10) as $node) {
            $nodes[] = ['node_id' => "NODE_$node", 'gateway_id' => 'NODE_1', 'battery' => '3.90 V',
                'route' => "NODE_$node>NODE_1", 'rssi' => -60, 'received_age_ms' => $node === 3 ? 12000 : 0];
        }
        $this->postJson('/api/v1/lora/heartbeats', ['nodes' => $nodes], ['X-STITCH-LORA-TOKEN' => 'test-batch'])
            ->assertOk()->assertJsonCount(10, 'nodeIds');
        $this->assertDatabaseCount('lora_node_states', 10);
        $state = LoraNodeState::where('node_id', 'NODE_3')->firstOrFail();
        $this->assertSame(-60, $state->rssi);
        $this->assertEquals(now()->subSeconds(12), $state->last_seen_at);
        $this->assertSame('3.90 V', $state->battery);
    }

    public function test_invalid_batch_is_atomic_and_requires_gateway_token(): void
    {
        config(['services.lora_ingest.token' => 'test-batch']);
        $nodes = [['node_id' => 'NODE_2'], ['node_id' => 'UNKNOWN']];
        $this->postJson('/api/v1/lora/heartbeats', ['nodes' => $nodes])->assertUnauthorized();
        $this->postJson('/api/v1/lora/heartbeats', ['nodes' => $nodes], ['X-STITCH-LORA-TOKEN' => 'test-batch'])->assertUnprocessable();
        $this->assertDatabaseCount('lora_node_states', 0);
        $this->postJson('/api/v1/lora/heartbeats', ['nodes' => [['node_id' => 'NODE_2'], ['node_id' => 'node_2']]], ['X-STITCH-LORA-TOKEN' => 'test-batch'])->assertUnprocessable();
        $this->postJson('/api/v1/lora/heartbeats', ['nodes' => [['node_id' => 'NODE_2', 'received_age_ms' => 30001]]], ['X-STITCH-LORA-TOKEN' => 'test-batch'])->assertUnprocessable();
    }
}
