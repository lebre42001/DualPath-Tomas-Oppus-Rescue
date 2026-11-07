<?php

namespace Tests\Feature;

use App\Events\IncidentFeedUpdated;
use App\Models\AdminActivity;
use App\Models\IncidentReport;
use App\Models\User;
use App\Support\SystemVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp(); $this->withoutVite();
        config(['services.ai_text.mode' => 'MANUAL_ONLY', 'services.routing.enabled' => false]);
    }

    public function test_admin_pages_offer_the_floating_reports_and_updates_modal_without_the_old_banner(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true, 'role' => 'admin']));
        foreach (['monitoring', 'lora-reports.index', 'reports.index', 'admin.system-log'] as $route) {
            $this->get(route($route))->assertOk()->assertSee('data-admin-notifications-toggle', false)
                ->assertSee('data-notification-tab="reports"', false)->assertSee('data-notification-tab="updates"', false)
                ->assertSee('data-admin-apply-update', false)->assertSee('data-incoming-sound', false)
                ->assertDontSee('data-incoming-banner', false)->assertDontSee('class="alert-center"', false);
        }
    }

    public function test_updates_require_admin_and_feed_is_private_and_read_only(): void
    {
        $this->getJson(route('admin.notification-updates'))->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role' => 'civilian', 'is_admin' => false]))
            ->getJson(route('admin.notification-updates'))->assertRedirect(route('dashboard'));
        $this->actingAs(User::factory()->create(['role' => 'admin', 'is_admin' => true]))
            ->getJson(route('admin.notification-updates'))->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('version', app(SystemVersion::class)->current())->assertSee('Floating report notifications');
        $this->assertDatabaseCount('admin_activities', 0);
    }

    public function test_update_events_keep_the_real_officer_and_build_and_cannot_forge_report_actions(): void
    {
        $actor = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
        $this->actingAs($actor);
        $version = app(SystemVersion::class)->current();
        $event = ['event_id' => Str::uuid()->toString(), 'kind' => 'app_update_available', 'version' => $version,
            'previous_version' => '1111111111111111', 'actor_id' => 98765];
        $this->postJson(route('admin.app-events'), $event)->assertOk()->assertJsonPath('saved', true);
        $this->postJson(route('admin.app-events'), [...$event, 'event_id' => Str::uuid()->toString()])->assertOk();
        $this->assertDatabaseCount('admin_activities', 1);
        $entry = AdminActivity::sole();
        $this->assertSame($actor->id, $entry->actor_reference);
        $this->assertSame($version, $entry->target_label);
        $this->assertStringContainsString('Changes:', $entry->summary);
        foreach (['app_update_requested', 'app_update_applied', 'app_update_failed', 'app_version_seen'] as $kind) {
            $this->postJson(route('admin.app-events'), [...$event, 'kind' => $kind, 'event_id' => Str::uuid()->toString()])->assertOk();
        }
        $this->postJson(route('admin.app-events'), [...$event, 'kind' => 'report_coordination'])->assertUnprocessable();
        $this->postJson(route('admin.app-events'), [...$event, 'version' => '2222222222222222'])->assertStatus(409);
        $this->assertDatabaseCount('admin_activities', 5);
        $this->get(route('admin.system-log', ['category' => 'system']))->assertOk()->assertSee($version)
            ->assertSee('Requested admin app update')->assertSee('Updated admin app version opened');
    }

    public function test_notification_actions_are_logged_without_changing_report_coordination(): void
    {
        $actor = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
        $report = IncidentReport::create(['report_code' => 'LORA-NOTICE-TEST', 'reporter_name' => 'Test sender', 'description' => 'Test collision', 'severity' => 'Serious', 'location_text' => 'Test site', 'channel' => 'LoRa', 'incident_type' => 'Road Accident', 'transmission_type' => 'lora', 'status' => 'received']);
        $this->actingAs($actor);
        foreach (['report_notification_received', 'report_notification_opened', 'notifications_opened', 'notification_tab_updates', 'update_notice_seen', 'notifications_cleared'] as $event) {
            $data = ['event' => $event, 'event_id' => Str::uuid()->toString(), 'report_id' => $report->id];
            $this->postJson(route('admin.system-log.activity'), $data)->assertOk();
            if ($event === 'report_notification_received') $this->postJson(route('admin.system-log.activity'), [...$data, 'event_id' => Str::uuid()->toString()])->assertOk();
        }
        $this->assertSame('received', $report->fresh()->status);
        $this->assertDatabaseCount('admin_activities', 6);
    }

    public function test_separate_lora_incidents_from_the_same_or_different_nodes_arrive_in_the_same_second(): void
    {
        Event::fake([IncidentFeedUpdated::class]);
        $this->travelTo(now()->startOfSecond());
        config(['services.lora_ingest.token' => 'test-burst-token']);
        $headers = ['X-STITCH-LORA-TOKEN' => 'test-burst-token'];
        foreach ([2, 3, 4] as $node) foreach ([1, 2, 3] as $sequence) {
            $payload = ['sender_id' => 'NODE_'.$node, 'sequence' => 'NODE_'.$node.'-'.$sequence, 'gateway_id' => 'NODE_1',
                'incident_type' => 'Road Accident', 'description' => 'Separate collision '.$sequence, 'incident_landmark' => 'Location '.$node.'-'.$sequence,
                'created_at' => (string) now()->timestamp, 'radio_auth' => 'hmac-sha256-v2'];
            $this->postJson('/api/v1/lora/alerts', $payload, $headers)->assertCreated();
            $this->postJson('/api/v1/lora/alerts', $payload, $headers)->assertOk()->assertJsonPath('duplicate', true);
        }
        $this->assertDatabaseCount('incident_reports', 9);
        $this->actingAs(User::factory()->create(['is_admin' => true, 'role' => 'admin']))
            ->getJson(route('monitoring.reports.feed', ['after' => 0]))->assertOk()->assertJsonCount(9, 'items');
        Event::assertDispatchedTimes(IncidentFeedUpdated::class, 9);
    }
}
