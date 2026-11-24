<?php

namespace Tests\Feature;

use App\Models\IncidentReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LoRaReporterNameTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.lora_ingest.token' => 'test-lora-token', 'services.ai_text.mode' => 'MANUAL_ONLY']);
    }

    private function payload(string $description): array
    {
        return ['sender_id' => 'NODE_9', 'sequence' => 'NODE_9-123', 'gateway_id' => 'NODE_1',
            'radio_auth' => 'hmac-sha256-v2', 'incident_type' => 'Road Accident',
            'incident_landmark' => 'Near school', 'created_at' => '1793145600', 'description' => $description];
    }

    public function test_old_gateway_forwards_named_report_without_changing_description_analysis_or_retries(): void
    {
        $name = "José Niño";
        $payload = $this->payload('@N1:'.strlen($name).':'.$name.'Motorcycle crash, one injured.');
        $headers = ['X-STITCH-LORA-TOKEN' => 'test-lora-token'];
        $this->postJson('/api/v1/lora/alerts', $payload, $headers)->assertCreated()
            ->assertJsonPath('data.reporter_name', $name);
        $report = IncidentReport::query()->firstOrFail();
        $this->assertSame($name, $report->reporter_name);
        $this->assertSame('Motorcycle crash, one injured.', $report->description);
        $this->assertSame('Near school', $report->lora_incident_landmark);
        $this->postJson('/api/v1/lora/alerts', $payload, $headers)->assertOk()->assertJsonPath('duplicate', true);
        $this->assertDatabaseCount('incident_reports', 1);
        $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
        $this->actingAs($admin)->get(route('lora-reports.index', ['review' => $report->id]))
            ->assertOk()->assertSee($name)->assertSee('Reporter name')->assertSee('self-declared')
            ->assertDontSee('@N1:');
    }

    public function test_name_does_not_trigger_the_fatality_detector_and_legacy_reports_still_work(): void
    {
        $headers = ['X-STITCH-LORA-TOKEN' => 'test-lora-token'];
        $this->postJson('/api/v1/lora/alerts', $this->payload('@N1:4:deadMinor collision.'), $headers)->assertCreated();
        $this->assertFalse(IncidentReport::query()->firstOrFail()->reported_fatality_flag);
        $legacy = $this->payload('Motorcycle crash.');
        $legacy['sequence'] = 'NODE_9-124';
        $this->postJson('/api/v1/lora/alerts', $legacy, $headers)->assertCreated();
        $report = IncidentReport::query()->where('lora_sequence', 'NODE_9-124')->firstOrFail();
        $this->assertSame('Motorcycle crash.', $report->description);
        $this->assertNotSame('', $report->reporter_name);
    }

    public static function invalidEnvelopes(): array
    {
        return [
            ['@N1:0:Crash'], ['@N1:25:'.str_repeat('a', 25).'Crash'], ['@N1:04:JuanCrash'],
            ['@N1:4:Juan'], ['@N1:4:    Crash'], ["@N1:4:Ju\nnCrash"], ['@N1:4:Ju|nCrash'],
            ["@N1:1:éCrash"], ['@N1:24:'.str_repeat('a', 24).str_repeat('b', 40)],
        ];
    }

    #[DataProvider('invalidEnvelopes')]
    public function test_malformed_or_oversized_name_envelopes_are_not_saved(string $message): void
    {
        $this->postJson('/api/v1/lora/alerts', $this->payload($message), ['X-STITCH-LORA-TOKEN' => 'test-lora-token'])
            ->assertUnprocessable()->assertJsonValidationErrors('description');
        $this->assertDatabaseCount('incident_reports', 0);
    }

    public function test_name_html_is_escaped_in_admin_review(): void
    {
        $name = '<b>Juan</b>';
        $this->postJson('/api/v1/lora/alerts', $this->payload('@N1:'.strlen($name).':'.$name.'Crash.'),
            ['X-STITCH-LORA-TOKEN' => 'test-lora-token'])->assertCreated();
        $report = IncidentReport::query()->firstOrFail();
        $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
        $this->actingAs($admin)->get(route('lora-reports.index', ['review' => $report->id]))
            ->assertOk()->assertSee(e($name), false)->assertDontSee($name, false);
    }
}
