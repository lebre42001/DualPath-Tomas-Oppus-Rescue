<?php

namespace Tests\Feature;

use App\Events\IncidentFeedUpdated;
use App\Models\IncidentReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class LoRaAlertIngestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.lora_ingest.token' => 'test-lora-token',
            'lora_nodes.NODE_2' => [
                'node_name' => 'Field Node 2',
                'location_label' => 'Test Installation Site',
                'latitude' => 10.1234,
                'longitude' => 124.5678,
            ],
        ]);
    }

    public function test_lora_hardware_alert_creates_responder_visible_report(): void
    {
        Event::fake([IncidentFeedUpdated::class]);
        $this->postJson('/api/v1/lora/alerts', $this->validPayload(), [
            'X-STITCH-LORA-TOKEN' => 'test-lora-token',
        ])
            ->assertCreated()
            ->assertJsonPath('duplicate', false)
            ->assertJsonPath('data.transmission_type', 'lora')
            ->assertJsonPath('data.reporter_name', 'Juan Dela Cruz')
            ->assertJsonPath('data.lora_sender_id', 'NODE_2')
            ->assertJsonPath('data.lora_sequence', 'NODE_2-42');

        $report = IncidentReport::query()->firstOrFail();

        $this->assertSame('Juan Dela Cruz', $report->reporter_name);
        $this->assertSame('LoRa Emergency Alert', $report->incident_type);
        $this->assertSame('Serious', $report->severity);
        $this->assertSame('received', $report->status);
        $this->assertSame('LoRa Mesh', $report->channel);
        $this->assertSame('lora', $report->transmission_type);
        $this->assertSame('arduino_lora_gateway', $report->hardware_source);
        $this->assertNotNull($report->ingest_key);
        $this->assertSame('not_applicable', $report->ai_status);
        $this->assertNull($report->ai_source);
        $this->assertNull($report->ai_confidence);
        $this->assertSame('NODE_1', $report->lora_gateway_id);
        $this->assertSame(-70, $report->lora_gateway_rssi);
        $this->assertSame('9.50', $report->lora_gateway_snr);
        $this->assertSame(-65, $report->lora_receiver_rssi);
        $this->assertSame('8.10', $report->lora_receiver_snr);
        $this->assertNull($report->lora_satellites);
        $this->assertSame('Motorcycle crash, one injured person.', $report->description);
        $this->assertNotNull($report->transmitted_at);
        $this->assertSame('10.123400', $report->latitude);
        $this->assertSame('124.567800', $report->longitude);
        $this->assertStringContainsString('Field Node 2 (NODE_2) at Test Installation Site', $report->location_text);

        Event::assertDispatched(IncidentFeedUpdated::class);
    }

    public function test_lora_hardware_alert_rejects_invalid_token(): void
    {
        $this->postJson('/api/v1/lora/alerts', $this->validPayload(), [
            'X-STITCH-LORA-TOKEN' => 'wrong-token',
        ])->assertUnauthorized();

        $this->assertDatabaseCount('incident_reports', 0);
    }

    public function test_lora_hardware_alert_is_idempotent_by_sender_and_sequence(): void
    {
        $this->postJson('/api/v1/lora/alerts', $this->validPayload(), [
            'X-STITCH-LORA-TOKEN' => 'test-lora-token',
        ])->assertCreated();

        $this->postJson('/api/v1/lora/alerts', $this->validPayload(), [
            'X-STITCH-LORA-TOKEN' => 'test-lora-token',
        ])
            ->assertOk()
            ->assertJsonPath('duplicate', true)
            ->assertJsonPath('data.lora_sender_id', 'NODE_2')
            ->assertJsonPath('data.lora_sequence', 'NODE_2-42');

        $this->assertDatabaseCount('incident_reports', 1);
    }

    public function test_lora_uses_node_name_when_reporter_is_missing_and_limits_optional_description(): void
    {
        $missingName = $this->validPayload();
        unset($missingName['reporter_name']);

        $this->postJson('/api/v1/lora/alerts', $missingName, [
            'X-STITCH-LORA-TOKEN' => 'test-lora-token',
        ])
            ->assertCreated()
            ->assertJsonPath('data.reporter_name', 'Field Node 2');

        $longDescription = $this->validPayload();
        $longDescription['sequence'] = 'NODE_2-43';
        $longDescription['description'] = str_repeat('x', 81);

        $this->postJson('/api/v1/lora/alerts', $longDescription, [
            'X-STITCH-LORA-TOKEN' => 'test-lora-token',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('description');

        $this->assertDatabaseCount('incident_reports', 1);
    }

    public function test_lora_rejects_sender_outside_the_registered_node_one_to_ten_mesh(): void
    {
        $payload = $this->validPayload();
        $payload['sender_id'] = 'S01';

        $this->postJson('/api/v1/lora/alerts', $payload, [
            'X-STITCH-LORA-TOKEN' => 'test-lora-token',
        ])->assertUnprocessable();

        $this->assertDatabaseCount('incident_reports', 0);
    }

    public function test_lora_report_appears_in_monitoring_and_dedicated_history_but_not_online_or_civilian_history(): void
    {
        $this->postJson('/api/v1/lora/alerts', $this->validPayload(), [
            'X-STITCH-LORA-TOKEN' => 'test-lora-token',
        ])->assertCreated();

        $report = IncidentReport::query()->firstOrFail();
        $admin = User::factory()->create([
            'role' => 'admin',
            'is_admin' => true,
        ]);
        $civilian = User::factory()->create([
            'role' => 'civilian',
            'is_admin' => false,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee($report->report_code)
            ->assertSee('Juan Dela Cruz')
            ->assertSee('Gateway NODE_1')
            ->assertSee('Review');

        $this->actingAs($admin)
            ->get(route('monitoring'))
            ->assertOk()
            ->assertSee($report->report_code)
            ->assertSee('LoRa reports')
            ->assertSee($report->lora_sender_id);

        $this->actingAs($admin)
            ->get(route('reports.index', [
                'search' => $report->report_code,
                'review' => $report->id,
            ]))
            ->assertOk()
            ->assertViewHas('reports', fn ($reports): bool => $reports->isEmpty())
            ->assertDontSee('id="incident-report-'.$report->id.'"', false);

        $this->get(route('lora-reports.index', ['search' => $report->report_code, 'review' => $report->id]))
            ->assertOk()
            ->assertSee('id="incident-report-'.$report->id.'"', false)
            ->assertSee('data-report-open-on-load', false)
            ->assertSee($report->description)->assertSee('Juan Dela Cruz')
            ->assertSee('Message ID')->assertSee('Acknowledge');

        $this->actingAs($civilian)
            ->get(route('reports.index'))
            ->assertOk()
            ->assertDontSee($report->report_code);
    }

    private function validPayload(): array
    {
        return [
            'sender_id' => 'NODE_2',
            'sequence' => 'NODE_2-42',
            'reporter_name' => 'Juan Dela Cruz',
            'gateway_id' => 'NODE_1',
            'latitude' => 10.307156,
            'longitude' => 124.980718,
            'satellites' => 7,
            'gateway_rssi' => -70,
            'gateway_snr' => 9.5,
            'receiver_rssi' => -65,
            'receiver_snr' => 8.1,
            'severity' => 'Serious',
            'incident_type' => 'LoRa Emergency Alert',
            'description' => 'Motorcycle crash, one injured person.',
        ];
    }
}
