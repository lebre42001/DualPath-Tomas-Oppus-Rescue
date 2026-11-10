<?php

namespace Tests\Feature;

use App\Models\AdminActivity;
use App\Models\LoraNodeRestartCommand;
use App\Models\LoraNodeState;
use App\Models\User;
use App\Services\LoraNodeRestartService;
use App\Support\LoraMeshNodeDirectory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoRaNodeRestartTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(now()->startOfSecond());
        config(['services.lora_ingest.token' => 'test-only-restart-gateway-token']);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_admin' => true, 'name' => 'Restart Officer']);
    }

    private function headers(): array
    {
        return ['Authorization' => 'Bearer test-only-restart-gateway-token'];
    }

    private function capable(string $node = 'NODE_2', string $boot = 'aabb1234'): LoraNodeState
    {
        return LoraNodeState::query()->updateOrCreate(['node_id' => $node], [
            'last_seen_at' => now(), 'last_packet_type' => 'HB', 'guard_boot' => $boot,
            'guard_reported_at' => now(), 'radio_auth' => 'hmac-sha256-v2',
            'restart_protocol' => LoraNodeRestartService::PROTOCOL,
        ]);
    }

    private function queued(string $node = 'NODE_2'): LoraNodeRestartCommand
    {
        $this->capable('NODE_1', '1111aaaa');
        $this->capable($node, $node === 'NODE_1' ? '1111aaaa' : 'aabb1234');
        $this->actingAs($this->admin())->postJson(route('admin.node-restarts.store', $node), ['confirm' => true])
            ->assertAccepted()->assertJsonPath('command.status', 'queued');

        return LoraNodeRestartCommand::query()->latest('id')->firstOrFail();
    }

    private function receipt(LoraNodeRestartCommand $command, string $status, array $attributes = [])
    {
        return $this->postJson('/api/v1/lora/commands/'.$command->id.'/result', [
            'node_id' => $command->node_id, 'status' => $status, ...$attributes,
        ], $this->headers());
    }

    public function test_only_active_admins_can_request_restarts_and_gateway_token_cannot_use_the_web_action(): void
    {
        $url = route('admin.node-restarts.store', 'NODE_2');
        $this->postJson($url, ['confirm' => true], $this->headers())->assertUnauthorized();
        $this->getJson(route('admin.node-restarts.index'))->assertUnauthorized();
        foreach (['civilian', 'responder'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'is_admin' => false]))
                ->postJson($url, ['confirm' => true])->assertRedirect(route('dashboard'));
            $this->getJson(route('admin.node-restarts.index'))->assertRedirect(route('dashboard'));
        }
        $this->actingAs($this->admin())->postJson($url, [])->assertUnprocessable();
        $this->postJson(route('admin.node-restarts.store', 'NODE_11'), ['confirm' => true])->assertNotFound();
        $this->postJson(route('admin.node-restarts.store', 'S01'), ['confirm' => true])->assertNotFound();
        $this->assertDatabaseCount('lora_node_restart_commands', 0);
    }

    public function test_web_restart_requires_csrf_outside_the_test_environment(): void
    {
        $this->actingAs($this->admin());
        $this->app['env'] = 'local';
        try {
            $this->postJson(route('admin.node-restarts.store', 'NODE_2'), ['confirm' => true])->assertStatus(419);
        } finally {
            $this->app['env'] = 'testing';
        }
        $this->assertDatabaseCount('lora_node_restart_commands', 0);
    }

    public function test_old_firmware_stale_target_or_missing_gateway_cannot_receive_a_restart_request(): void
    {
        $this->actingAs($this->admin());
        $url = route('admin.node-restarts.store', 'NODE_2');
        $this->postJson($url, ['confirm' => true])->assertConflict();
        $state = $this->capable();
        $this->postJson($url, ['confirm' => true])->assertConflict();
        $this->capable('NODE_1');
        $state->update(['restart_protocol' => null]);
        $this->postJson($url, ['confirm' => true])->assertConflict();
        $state->update(['restart_protocol' => LoraNodeRestartService::PROTOCOL, 'guard_reported_at' => now()->subSeconds(121)]);
        $this->postJson($url, ['confirm' => true])->assertConflict();
        $state->update(['guard_reported_at' => now(), 'radio_auth' => null]);
        $this->postJson($url, ['confirm' => true])->assertConflict();
        $this->assertDatabaseCount('lora_node_restart_commands', 0);
    }

    public function test_admin_request_is_durable_deduplicated_target_bound_and_logged_without_touching_incidents(): void
    {
        $command = $this->queued();
        $this->postJson(route('admin.node-restarts.store', 'NODE_2'), ['confirm' => true, 'node_id' => 'NODE_3', 'actor_name' => 'Impersonated'])
            ->assertOk()->assertJsonPath('created', false)->assertJsonPath('command.id', (string) $command->id);
        $this->assertDatabaseCount('lora_node_restart_commands', 1);
        $this->assertSame('NODE_2', $command->node_id);
        $this->assertSame('NODE_2', $command->active_node_id);
        $this->assertSame('Restart Officer', $command->actor_name);
        $this->assertSame(300, (int) $command->requested_at->diffInSeconds($command->expires_at));
        $log = AdminActivity::query()->where('action', 'node_restart_requested')->sole();
        $this->assertSame('NODE_2', $log->target_label);
        $this->assertStringNotContainsString('test-only-restart-gateway-token', $log->toJson());
        $this->assertDatabaseCount('admin_activities', 1);
        $this->assertDatabaseCount('incident_reports', 0);
        $this->assertDatabaseCount('incident_report_audits', 0);
    }

    public function test_gateway_poll_and_results_require_gateway_auth_and_correct_target(): void
    {
        $command = $this->queued();
        $poll = '/api/v1/lora/commands/pending?gateway_id=NODE_1';
        $this->getJson($poll)->assertUnauthorized();
        $this->postJson('/api/v1/lora/commands/'.$command->id.'/result', ['node_id' => 'NODE_2', 'status' => 'sent'])->assertUnauthorized();
        $this->getJson('/api/v1/lora/commands/pending?gateway_id=NODE_2', $this->headers())->assertUnprocessable();
        $this->getJson($poll, $this->headers())->assertOk()->assertJsonPath('pending', true)
            ->assertJsonPath('command.id', (string) $command->id)->assertJsonPath('command.expected_boot', 'aabb1234')
            ->assertJsonPath('command.expires_in_seconds', 300)->assertJsonMissing(['actor_name' => 'Restart Officer']);
        $this->assertSame('queued', $command->fresh()->status);
        $this->receipt($command, 'accepted', ['node_id' => 'NODE_3', 'boot_id' => 'aabb1234'])->assertUnprocessable();
        $this->receipt($command, 'accepted')->assertUnprocessable();
        $this->receipt($command, 'failed', ['reason' => 'arbitrary-secret-text'])->assertUnprocessable();
        config(['services.lora_ingest.token' => '']);
        $this->getJson($poll, $this->headers())->assertStatus(503);
    }

    public function test_restart_is_confirmed_only_by_command_acceptance_and_a_different_post_boot_receipt(): void
    {
        $command = $this->queued();
        $this->receipt($command, 'restarted', ['boot_id' => 'ffff9999'])->assertConflict();
        $this->receipt($command, 'sent')->assertOk()->assertJsonPath('status', 'sent');
        $this->receipt($command, 'accepted', ['boot_id' => 'bbbb9999'])->assertConflict();
        $this->receipt($command, 'accepted', ['boot_id' => 'aabb1234'])->assertOk()->assertJsonPath('status', 'accepted');
        $this->getJson('/api/v1/lora/commands/pending?gateway_id=NODE_1', $this->headers())->assertJsonPath('pending', false);
        // An unrelated heartbeat or manual power cycle does not confirm this command.
        $this->postJson('/api/v1/lora/heartbeat', ['node_id' => 'NODE_2', 'guard_boot' => 'ffff9999', 'radio_auth' => 'hmac-sha256-v2', 'restart_protocol' => LoraNodeRestartService::PROTOCOL], $this->headers())->assertOk();
        $this->assertSame('accepted', $command->fresh()->status);
        $this->receipt($command, 'restarted', ['boot_id' => 'aabb1234'])->assertConflict();
        $this->receipt($command, 'restarted', ['boot_id' => 'ffff9999'])->assertOk()->assertJsonPath('status', 'restarted');
        $this->receipt($command, 'restarted', ['boot_id' => 'ffff9999'])->assertOk();
        $this->receipt($command, 'accepted', ['boot_id' => 'aabb1234'])->assertOk()->assertJsonPath('status', 'restarted');
        $this->receipt($command, 'sent')->assertOk()->assertJsonPath('status', 'restarted');
        $this->receipt($command, 'accepted', ['boot_id' => 'bbbb9999'])->assertConflict();
        $this->receipt($command, 'restarted', ['boot_id' => '1111ffff'])->assertConflict();
        $this->receipt($command, 'failed', ['reason' => 'delivery_failed'])->assertConflict();
        $command->refresh();
        $this->assertNull($command->active_node_id);
        $this->assertSame('ffff9999', $command->restarted_boot);
        $this->assertNotNull($command->restarted_at);
        $this->assertSame(1, AdminActivity::query()->where('action', 'node_restart_restarted')->count());
        $this->assertDatabaseCount('incident_reports', 0);
    }

    public function test_expired_requests_stop_delivery_but_late_signed_receipts_can_record_the_actual_completed_restart(): void
    {
        $command = $this->queued();
        $this->travel(300)->seconds();
        $this->getJson('/api/v1/lora/commands/pending?gateway_id=NODE_1', $this->headers())->assertOk()->assertJsonPath('pending', false);
        $this->assertSame('expired', $command->fresh()->status);
        $this->assertNull($command->fresh()->active_node_id);
        $this->receipt($command, 'sent')->assertConflict();
        $this->receipt($command, 'accepted', ['boot_id' => 'aabb1234'])->assertOk()->assertJsonPath('status', 'expired');
        $this->getJson('/api/v1/lora/commands/pending?gateway_id=NODE_1', $this->headers())->assertJsonPath('pending', false);
        $this->receipt($command, 'restarted', ['boot_id' => 'ffff9999'])->assertOk()->assertJsonPath('status', 'restarted');
    }

    public function test_failed_request_is_cooldown_limited_and_cannot_be_reactivated_by_an_old_receipt(): void
    {
        $command = $this->queued();
        $this->receipt($command, 'failed', ['reason' => 'busy'])->assertOk()->assertJsonPath('status', 'failed');
        $this->receipt($command, 'failed', ['reason' => 'busy'])->assertOk();
        $this->receipt($command, 'accepted', ['boot_id' => 'aabb1234'])->assertConflict();
        $this->postJson(route('admin.node-restarts.store', 'NODE_2'), ['confirm' => true])->assertTooManyRequests();
        $this->travel(120)->seconds();
        $this->capable('NODE_1');
        $this->capable();
        $this->postJson(route('admin.node-restarts.store', 'NODE_2'), ['confirm' => true])->assertAccepted();
        $this->assertDatabaseCount('lora_node_restart_commands', 2);
        $this->assertSame(1, AdminActivity::query()->where('action', 'node_restart_failed')->count());
    }

    public function test_polling_rotates_outstanding_targets_and_never_fabricates_delivery(): void
    {
        $first = $this->queued('NODE_2');
        $second = $this->queued('NODE_3');
        $poll = '/api/v1/lora/commands/pending?gateway_id=NODE_1';
        $this->getJson($poll, $this->headers())->assertJsonPath('command.id', (string) $first->id);
        $this->travel(1)->seconds();
        $this->getJson($poll, $this->headers())->assertJsonPath('command.id', (string) $second->id);
        $this->travel(1)->seconds();
        $this->getJson($poll, $this->headers())->assertJsonPath('command.id', (string) $first->id);
        $this->assertSame('queued', $first->fresh()->status);
        $this->assertSame('queued', $second->fresh()->status);
        $this->assertNull($first->fresh()->sent_at);
    }

    public function test_gateway_can_restart_itself_with_the_same_durable_pre_and_post_boot_protocol(): void
    {
        $command = $this->queued('NODE_1');
        $this->receipt($command, 'accepted', ['boot_id' => '1111aaaa'])->assertOk();
        $this->receipt($command, 'restarted', ['boot_id' => '2222bbbb'])->assertOk()->assertJsonPath('status', 'restarted');
        $this->assertSame('NODE_1', $command->fresh()->node_id);
    }

    public function test_restart_capability_is_advertised_by_actual_heartbeats_and_withdrawn_by_older_firmware(): void
    {
        $heartbeat = ['node_id' => 'NODE_2', 'guard_boot' => 'aabb1234', 'radio_auth' => 'hmac-sha256-v2', 'restart_protocol' => LoraNodeRestartService::PROTOCOL];
        $this->postJson('/api/v1/lora/heartbeats', ['nodes' => [$heartbeat]], $this->headers())->assertOk();
        $state = LoraNodeState::query()->where('node_id', 'NODE_2')->firstOrFail();
        $this->assertTrue(app(LoraNodeRestartService::class)->capable($state));
        unset($heartbeat['restart_protocol']);
        $this->postJson('/api/v1/lora/heartbeat', $heartbeat, $this->headers())->assertOk();
        $this->assertNull($state->fresh()->restart_protocol);
        $this->assertFalse(app(LoraNodeRestartService::class)->capable($state->fresh()));
        $this->postJson('/api/v1/lora/heartbeat', [...$heartbeat, 'restart_protocol' => 'unsigned-restart'], $this->headers())->assertUnprocessable();
    }

    public function test_mesh_directory_exposes_safe_status_capability_and_expiry_without_changing_connection_status(): void
    {
        $this->capable('NODE_1');
        $this->capable();
        $directory = fn () => app(LoraMeshNodeDirectory::class)->all()->keyBy('sender_id');
        $this->assertTrue($directory()['NODE_2']['restart_available']);
        $this->assertFalse($directory()['NODE_3']['restart_available']);
        $this->assertStringContainsString('Upload', $directory()['NODE_3']['restart_disabled_reason']);
        $command = $this->queued();
        $row = $directory()['NODE_2'];
        $this->assertSame('REACHABLE', $row['status']);
        $this->assertFalse($row['restart_available']);
        $this->assertSame('queued', $row['restart_command']['status']);
        $this->assertSame('Queued', $row['restart_command']['label']);
        $this->assertArrayNotHasKey('expected_boot', $row['restart_command']);
        $this->travel(301)->seconds();
        $this->actingAs($this->admin())->getJson(route('admin.node-restarts.index'))->assertOk()
            ->assertJsonPath('commands.NODE_2.status', 'expired')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame('expired', $command->fresh()->status);
        $this->assertDatabaseCount('incident_reports', 0);
    }
}
