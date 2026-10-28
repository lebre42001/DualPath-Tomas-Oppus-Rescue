<?php

namespace Tests\Feature;

use App\Models\IncidentReport;
use App\Models\LoraNodeState;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoRaAntiPrankTest extends TestCase
{
    use RefreshDatabase;

    private function headers(): array
    {
        config(['services.lora_ingest.token' => 'test-guard-token']);

        return ['X-STITCH-LORA-TOKEN' => 'test-guard-token'];
    }

    private function payload(): array
    {
        return [
            'sender_id' => 'NODE_2', 'sequence' => 'NODE_2-123', 'gateway_id' => 'NODE_1',
            'incident_type' => 'Road Accident', 'description' => 'Test crash with injuries.',
            'incident_landmark' => 'Bogo bridge', 'created_at' => '1793145600',
            'radio_auth' => 'hmac-sha256-v2',
        ];
    }

    public function test_v2_requires_actual_landmark_description_and_road_accident_and_preserves_duplicate_logic(): void
    {
        $headers = $this->headers();
        foreach (['incident_landmark', 'description', 'created_at'] as $field) {
            $this->postJson('/api/v1/lora/alerts', array_replace($this->payload(), [$field => '']), $headers)
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->postJson('/api/v1/lora/alerts', array_replace($this->payload(), ['incident_type' => 'Fire']), $headers)
            ->assertUnprocessable()->assertJsonValidationErrors('incident_type');
        $this->assertDatabaseCount('incident_reports', 0);
        $this->postJson('/api/v1/lora/alerts', $this->payload(), $headers)->assertCreated();
        $this->postJson('/api/v1/lora/alerts', $this->payload(), $headers)->assertOk()->assertJsonPath('duplicate', true);
        $this->assertDatabaseCount('incident_reports', 1);
        $report = IncidentReport::firstOrFail();
        $this->assertSame('Bogo bridge', $report->lora_incident_landmark);
        $this->assertSame('hmac-sha256-v2', $report->lora_radio_auth);
        $this->assertSame('unverified', $report->lora_verification);
    }

    public function test_admin_can_record_all_three_verification_decisions_without_changing_rescue_progress(): void
    {
        $headers = $this->headers();
        $this->postJson('/api/v1/lora/alerts', $this->payload(), $headers)->assertCreated();
        $report = IncidentReport::firstOrFail();
        $admin = User::factory()->create(['is_admin' => true, 'role' => 'admin']);
        foreach (['confirmed' => 'Confirmed', 'needs_clarification' => 'Needs clarification', 'false_report' => 'False report'] as $value => $label) {
            $this->actingAs($admin)->post(route('lora-reports.verification', $report), [
                'verification' => $value, 'verification_notes' => 'Checked by operator: '.$label,
            ])->assertRedirect()->assertSessionHasNoErrors();
            $report->refresh();
            $this->assertSame($value, $report->lora_verification);
            $this->assertSame($admin->id, $report->lora_verified_by);
            $this->assertSame('received', $report->status);
            $this->assertNotNull($report->lora_verified_at);
            $this->assertNull($report->lora_ack_delivered_at);
            $ack = $this->getJson('/api/v1/lora/acks/pending?gatewayId=NODE_1', $headers)
                ->assertOk()->assertJsonPath('pending', true)->json('packet');
            $parts = explode('|', $ack);
            $this->assertCount(10, $parts);
            $this->assertSame($label, $parts[9]);
            $this->assertStringContainsString('Checked by operator', $parts[8]);
            $this->postJson('/api/v1/lora/acks/'.$report->lora_sequence.'/delivered', [], $headers)->assertOk();
            $previousCount = count($report->coordination_log);
            $this->post(route('lora-reports.verification', $report), [
                'verification' => $value, 'verification_notes' => 'Checked by operator: '.$label,
            ])->assertSessionHasNoErrors();
            $this->assertCount($previousCount, $report->fresh()->coordination_log);
            $this->assertNotNull($report->fresh()->lora_ack_delivered_at);
        }
        // Exact SOS retry requeues verification ACK even while status=received.
        $this->postJson('/api/v1/lora/alerts', $this->payload(), $headers)->assertJsonPath('duplicate', true);
        $this->assertNull($report->fresh()->lora_ack_delivered_at);
    }

    public function test_verification_requires_admin_and_reason_and_rejects_online_reports(): void
    {
        $headers = $this->headers();
        $this->postJson('/api/v1/lora/alerts', $this->payload(), $headers)->assertCreated();
        $report = IncidentReport::firstOrFail();
        $civilian = User::factory()->create(['is_admin' => false, 'role' => 'civilian']);
        $data = ['verification' => 'confirmed', 'verification_notes' => 'Reviewed.'];
        $this->actingAs($civilian)->post(route('lora-reports.verification', $report), $data)->assertRedirect(route('dashboard'));
        $this->assertSame('unverified', $report->fresh()->lora_verification);
        $admin = User::factory()->create(['is_admin' => true, 'role' => 'admin']);
        $this->actingAs($admin)->post(route('lora-reports.verification', $report), ['verification' => 'false_report'])
            ->assertSessionHasErrors('verification_notes');
        $this->post(route('lora-reports.verification', $report), array_replace($data, ['verification' => 'random']))
            ->assertSessionHasErrors('verification');
        $report->update(['transmission_type' => 'online']);
        $this->post(route('lora-reports.verification', $report), $data)->assertForbidden();
    }

    public function test_sender_protection_telemetry_is_visible_to_admin_without_claiming_prank_and_handles_legacy(): void
    {
        $headers = $this->headers();
        $data = [
            'node_id' => 'NODE_2', 'blocked_submissions' => 3, 'radio_rejections' => 5,
            'guard_boot' => 'abcd1234', 'radio_auth' => 'hmac-sha256-v2',
        ];
        $this->postJson('/api/v1/lora/heartbeat', $data, $headers)->assertOk();
        $state = LoraNodeState::firstOrFail();
        $this->assertSame(3, $state->blocked_submissions);
        $this->assertSame('abcd1234', $state->guard_boot);
        $admin = User::factory()->create(['is_admin' => true, 'role' => 'admin']);
        $this->actingAs($admin)->get(route('lora-reports.index'))->assertOk()
            ->assertSee('Node protection indicators')->assertSee('3 blocked submission(s)')->assertSee('not proof of a prank');
        $this->get(route('monitoring.lora-mesh.nodes'))->assertOk()
            ->assertSee('Submission protection')->assertSee('Authenticated firmware v2');
        $this->postJson('/api/v1/lora/heartbeat', ['node_id' => 'NODE_2'], $headers)->assertOk();
        $this->assertNull($state->fresh()->blocked_submissions);
        $this->postJson('/api/v1/lora/heartbeat', array_replace($data, ['blocked_submissions' => -1]), $headers)->assertUnprocessable();
    }

    public function test_print_respects_location_privacy_and_shows_review_without_system_keys(): void
    {
        $headers = $this->headers();
        $this->postJson('/api/v1/lora/alerts', $this->payload(), $headers)->assertCreated();
        $report = IncidentReport::firstOrFail();
        $report->update(['lora_verification' => 'confirmed', 'lora_verification_notes' => 'Operator checked.', 'lora_verified_at' => now()]);
        $admin = User::factory()->create(['is_admin' => true, 'role' => 'admin']);
        $this->actingAs($admin)->get(route('lora-reports.print', $report))->assertOk()
            ->assertSee('Confirmed')->assertSee('Operator checked.')->assertDontSee('Bogo bridge')->assertDontSee('test-guard-token');
        $this->get(route('lora-reports.print', [$report, 'include_location' => 1]))->assertOk()->assertSee('Bogo bridge');
    }

    public function test_v2_ack_reserves_authentication_and_full_route_with_escaped_or_unicode_notes(): void
    {
        $headers = $this->headers();
        $this->postJson('/api/v1/lora/alerts', $this->payload(), $headers)->assertCreated();
        $report = IncidentReport::firstOrFail();
        foreach ([str_repeat('~', 100), str_repeat('✅', 60)] as $notes) {
            $report->update([
                'lora_verification' => 'needs_clarification', 'lora_verification_notes' => $notes,
                'lora_verified_at' => now(), 'lora_ack_queued_at' => now(),
            ]);
            $response = $this->getJson('/api/v1/lora/acks/pending?gatewayId=NODE_1', $headers)->assertOk();
            // Firmware reads raw UTF-8 from this compact JSON field; avoid
            // silently changing Unicode into literal uXXXX radio text.
            $this->assertStringNotContainsString('\\u2705', $response->getContent());
            $packet = $response->json('packet');
            $parts = explode('|', $packet);
            $this->assertCount(10, $parts);
            $this->assertTrue(mb_check_encoding($parts[8], 'UTF-8'));
            $parts[7] = implode('>', array_map(fn ($n) => 'NODE_'.$n, range(1, 10)));
            $compact = str_replace('NODE_', '~N', str_replace('~', '~~', implode('|', $parts)));
            $this->assertLessThanOrEqual(255, strlen($compact) + 34 + 23);
        }
    }
}
