<?php

namespace Tests\Feature;

use App\Models\IncidentReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoRaReportPrintTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function report(array $overrides = []): IncidentReport
    {
        return IncidentReport::create(array_replace([
            'report_code' => 'LORA-PRINT-1', 'reporter_name' => 'Field Node 2',
            'reporter_contact' => '09991112222', 'incident_type' => 'Road Accident',
            'severity' => 'Serious', 'status' => 'received', 'channel' => 'LoRa Mesh',
            'transmission_type' => 'lora', 'ai_status' => 'not_applicable',
            'location_text' => 'Private registered node location',
            'latitude' => 10.123457, 'longitude' => 124.765432,
            'description' => 'LoRa description <script>alert(1)</script>',
            'lora_sender_id' => 'NODE_2', 'lora_sequence' => 'NODE_2-99', 'lora_gateway_id' => 'NODE_1',
            'lora_gateway_rssi' => -50, 'lora_gateway_snr' => 9,
            'response_notes' => 'Recorded admin response.',
            'coordination_log' => [[
                'event' => 'lora_alert_ingested', 'created_at' => '2026-10-15T15:13:08+08:00',
                'route' => 'NODE_2>NODE_3>NODE_1', 'hop' => 1, 'battery' => 'NA',
            ]],
        ], $overrides));
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    }

    public function test_lora_preview_uses_the_online_print_workflow_and_preserves_the_received_record(): void
    {
        $report = $this->report();
        $this->report(['report_code' => 'OTHER-LORA-REPORT', 'lora_sequence' => 'NODE_2-100']);
        $response = $this->actingAs($this->admin())->get(route('lora-reports.print', $report))
            ->assertOk()->assertSee('LoRa report record')->assertSee('LORA-PRINT-1')
            ->assertSee('Choose print details')->assertSee('Update preview')->assertSee('Print selected details')
            ->assertSee('Registered node location')->assertSee('NODE_2-99')
            ->assertSee('NODE_2>NODE_3>NODE_1')->assertSee('2026-10-15T15:13:08+08:00')
            ->assertSee('No voltage reading')->assertSee('Recorded admin response.')
            ->assertSee('data-auto-print="false"', false)->assertDontSee('OTHER-LORA-REPORT')
            ->assertDontSee('<script>alert(1)</script>', false);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame('received', $report->fresh()->status);
        $this->assertNull($report->lora_ack_queued_at);
        $this->assertNull($report->lora_ack_delivered_at);
    }

    public function test_private_details_are_opt_in_and_photos_selfies_are_never_printed(): void
    {
        $report = $this->report(['evidence_type' => 'photo', 'evidence_path' => 'private-evidence.jpg',
            'reporter_selfie_path' => 'private-selfie.jpg']);
        $this->actingAs($this->admin());
        $this->get(route('lora-reports.print', $report))->assertOk()
            ->assertDontSee('09991112222')->assertDontSee('10.123457')->assertDontSee('124.765432')
            ->assertDontSee('Private registered node location')->assertDontSee('name="include_photo"', false);
        $this->get(route('lora-reports.print', [$report, 'include_location' => 1,
            'include_contact' => 1, 'include_photo' => 1, 'include_selfie' => 1, 'print' => 1]))
            ->assertOk()->assertSee('09991112222')->assertSee('10.123457')->assertSee('124.765432')
            ->assertSee('Private registered node location')->assertSee('Registered coordinates:')
            ->assertSee('data-auto-print="true"', false)->assertDontSee('GPS:')
            ->assertDontSee('data-print-scene-photo', false)->assertDontSee('private-selfie.jpg')
            ->assertDontSee(route('reports.selfie', $report), false);
        $this->assertNull($report->fresh()->lora_ack_queued_at);
    }

    public function test_lora_print_has_the_same_admin_boundary_and_rejects_online_records(): void
    {
        $report = $this->report();
        $this->get(route('lora-reports.print', $report))->assertRedirect(route('login'));
        foreach (['civilian', 'responder'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'is_admin' => false]))
                ->getJson(route('lora-reports.print', $report))->assertForbidden();
        }
        $online = $this->report(['report_code' => 'ONLINE-SCOPE', 'transmission_type' => 'online', 'lora_sender_id' => null, 'lora_sequence' => null]);
        $this->actingAs($this->admin())->get(route('lora-reports.print', $online))->assertNotFound();
        $this->get(route('reports.print', $report))->assertNotFound();
    }

    public function test_print_options_are_validated_and_missing_node_location_remains_missing(): void
    {
        $report = $this->report(['latitude' => null, 'longitude' => null, 'location_text' => 'Location not configured']);
        $this->actingAs($this->admin())->getJson(route('lora-reports.print', [$report, 'include_location' => 'invalid']))
            ->assertUnprocessable()->assertJsonValidationErrors('include_location');
        $this->get(route('lora-reports.print', [$report, 'include_location' => 1]))
            ->assertOk()->assertSee('Location not configured')->assertDontSee('Registered coordinates:');
    }
}
