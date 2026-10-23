<?php

namespace Tests\Feature;

use App\Models\IncidentReport;
use App\Models\LoraNodeState;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoRaTelemetryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.lora_ingest.token' => 'telemetry-test-token']);
    }

    public function test_probe_returns_the_website_echo_without_inventing_a_report_or_heartbeat(): void
    {
        $this->postJson('/api/v1/lora/probe', ['node_id' => 'NODE_2', 'probe_id' => 'boot1-42'], $this->headers())
            ->assertOk()->assertJsonPath('packet', 'PONG|NODE_2|boot1-42|12|NODE_1');

        $this->assertSame(0, IncidentReport::query()->count());
        $this->assertSame(0, LoraNodeState::query()->count());
    }

    public function test_probe_rejects_an_invalid_token_unknown_node_and_packet_separator(): void
    {
        $this->postJson('/api/v1/lora/probe', ['node_id' => 'NODE_2', 'probe_id' => 'boot-1'])
            ->assertUnauthorized();
        $this->postJson('/api/v1/lora/probe', ['node_id' => 'NODE_99', 'probe_id' => 'boot-1'], $this->headers())
            ->assertUnprocessable();
        $this->postJson('/api/v1/lora/probe', ['node_id' => 'NODE_2', 'probe_id' => 'bad|packet'], $this->headers())
            ->assertJsonValidationErrors('probe_id');
    }

    public function test_heartbeat_stores_measured_voltage_round_trip_and_actual_snr(): void
    {
        $this->travelTo(now()->startOfSecond());
        $this->postJson('/api/v1/lora/heartbeat', [
            'node_id' => 'NODE_2', 'battery' => '3.96 V', 'snr' => 7.5,
            'round_trip_ms' => 731, 'round_trip_sample' => 'boot-1', 'round_trip_age_ms' => 10000,
        ], $this->headers())->assertOk();

        $state = LoraNodeState::query()->firstOrFail();
        $this->assertSame('3.96 V', $state->battery);
        $this->assertSame(731, $state->round_trip_ms);
        $this->assertSame(7.5, $state->snr);
        $this->assertTrue($state->round_trip_measured_at->equalTo(now()->subSeconds(10)));
    }

    public function test_repeated_heartbeat_does_not_make_an_old_timing_sample_new(): void
    {
        $payload = ['node_id' => 'NODE_2', 'round_trip_ms' => 500, 'round_trip_sample' => 'boot-1'];
        $this->postJson('/api/v1/lora/heartbeat', $payload, $this->headers())->assertOk();
        $measuredAt = LoraNodeState::query()->firstOrFail()->round_trip_measured_at;
        $this->travel(60)->seconds();
        $this->postJson('/api/v1/lora/heartbeat', $payload, $this->headers())->assertOk();
        $this->assertTrue(LoraNodeState::query()->firstOrFail()->round_trip_measured_at->equalTo($measuredAt));
    }

    public function test_old_firmware_remains_compatible_and_does_not_invent_measurements(): void
    {
        $this->postJson('/api/v1/lora/heartbeat', ['node_id' => 'NODE_2', 'battery' => 'NA'], $this->headers())
            ->assertOk();
        $state = LoraNodeState::query()->firstOrFail();
        $this->assertNull($state->battery);
        $this->assertNull($state->round_trip_ms);
        $this->assertNull($state->round_trip_measured_at);
    }

    public function test_invalid_timing_samples_are_rejected_and_zero_is_a_valid_measurement(): void
    {
        $this->postJson('/api/v1/lora/heartbeat', [
            'node_id' => 'NODE_1', 'round_trip_ms' => -1, 'round_trip_sample' => 'boot-1',
        ], $this->headers())->assertJsonValidationErrors('round_trip_ms');
        $this->postJson('/api/v1/lora/heartbeat', [
            'node_id' => 'NODE_1', 'round_trip_ms' => 15,
        ], $this->headers())->assertJsonValidationErrors('round_trip_sample');
        $this->postJson('/api/v1/lora/heartbeat', [
            'node_id' => 'NODE_1', 'round_trip_ms' => 0, 'round_trip_sample' => 'boot-1',
        ], $this->headers())->assertOk();
        $this->assertSame(0, LoraNodeState::query()->firstOrFail()->round_trip_ms);
    }

    public function test_admin_monitor_displays_fresh_measurements_and_expires_old_samples(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
        LoraNodeState::query()->create([
            'node_id' => 'NODE_2', 'last_seen_at' => now(), 'battery' => '3.96 V',
            'round_trip_ms' => 731, 'round_trip_measured_at' => now(),
        ]);
        $this->actingAs($admin)->get('/monitoring/lora-mesh')
            ->assertOk()->assertSee('3.96 V')->assertSee('731 ms')
            ->assertSee('Delay (RTT)')->assertSee('No voltage reading');

        $this->travel(121)->seconds();
        $this->get('/monitoring/lora-mesh/nodes')->assertOk()
            ->assertSee('Sample expired')->assertDontSee('731 ms');
    }

    private function headers(): array
    {
        return ['X-STITCH-LORA-TOKEN' => 'telemetry-test-token'];
    }
}
