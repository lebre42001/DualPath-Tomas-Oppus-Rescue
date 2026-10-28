<?php

namespace Tests\Feature;

use App\Events\IncidentFeedUpdated;
use App\Models\IncidentReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

class IncomingReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_node_four_then_node_three_are_independent_and_both_reach_the_admin_feed(): void
    {
        Event::fake([IncidentFeedUpdated::class]);
        config(['services.lora_ingest.token' => 'test-incoming-token']);
        $admin = User::factory()->create(['is_admin' => true, 'role' => 'admin']);
        $cursor = $this->actingAs($admin)->getJson(route('monitoring.reports.feed'))->assertOk()->json('cursor');
        foreach ([4, 3] as $node) {
            $payload = [
                'sender_id' => "NODE_$node", 'sequence' => "NODE_$node-42", 'gateway_id' => 'NODE_1',
                'incident_type' => 'Road Accident', 'description' => "Crash from node $node",
                'incident_landmark' => 'Test bridge', 'created_at' => '1793145600', 'radio_auth' => 'hmac-sha256-v2',
            ];
            $this->postJson('/api/v1/lora/alerts', $payload, ['X-STITCH-LORA-TOKEN' => 'test-incoming-token'])->assertCreated();
            $this->postJson('/api/v1/lora/alerts', $payload, ['X-STITCH-LORA-TOKEN' => 'test-incoming-token'])->assertOk()->assertJsonPath('duplicate', true);
        }
        $this->assertDatabaseCount('incident_reports', 2);
        $feed = $this->getJson(route('monitoring.reports.feed', ['after' => $cursor]))
            ->assertOk()->assertJsonCount(2, 'items')
            ->assertJsonPath('items.0.report.lora_sender_id', 'NODE_4')
            ->assertJsonPath('items.1.report.lora_sender_id', 'NODE_3');
        $this->getJson(route('monitoring.reports.feed', ['after' => $feed->json('cursor')]))->assertOk()->assertJsonCount(0, 'items');
        $this->get(route('monitoring'))->assertOk()->assertSee('data-incoming-reports', false)->assertSee('Enable sound')->assertSee('Incoming reports');
    }

    public function test_initial_notifications_include_noncritical_reports_but_not_reviewed_history(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'role' => 'admin']);
        $this->report(['severity' => 'Minor', 'status' => 'received']);
        $this->report(['severity' => 'Serious', 'status' => 'received']);
        $this->report(['severity' => 'Critical', 'status' => 'resolved']);
        $this->actingAs($admin)->getJson(route('monitoring.reports.feed'))->assertOk()->assertJsonCount(2, 'items');
    }

    public function test_feed_requires_receiver_authorization_and_paginates_without_skipping_reports(): void
    {
        $this->getJson(route('monitoring.reports.feed'))->assertUnauthorized();
        $civilian = User::factory()->create(['is_admin' => false, 'role' => 'civilian']);
        $this->actingAs($civilian)->getJson(route('monitoring.reports.feed'))->assertForbidden();
        $admin = User::factory()->create(['is_admin' => true, 'role' => 'admin']);
        foreach (range(1, 53) as $number) $this->report();
        $page = $this->actingAs($admin)->getJson(route('monitoring.reports.feed', ['after' => 0]))->assertOk()->assertJsonCount(50, 'items');
        $this->getJson(route('monitoring.reports.feed', ['after' => $page->json('cursor')]))->assertOk()->assertJsonCount(3, 'items');
        $this->getJson(route('monitoring.reports.feed', ['after' => -1]))->assertUnprocessable();
    }

    private function report(array $attributes = []): IncidentReport
    {
        return IncidentReport::create(array_merge([
            'report_code' => 'TEST-'.Str::random(10), 'incident_type' => 'Road Accident',
            'reporter_name' => 'Test reporter',
            'severity' => 'Minor', 'status' => 'received', 'transmission_type' => 'online',
            'channel' => 'Online', 'location_text' => 'Test bridge', 'description' => 'Test incident',
        ], $attributes));
    }
}
