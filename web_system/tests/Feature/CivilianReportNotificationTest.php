<?php

namespace Tests\Feature;

use App\Events\IncidentFeedUpdated;
use App\Models\CivilianActivity;
use App\Models\IncidentReport;
use App\Models\IncidentReportAudit;
use App\Models\User;
use App\Services\CivilianActivityRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class CivilianReportNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['services.ai_text.mode' => 'MANUAL_ONLY', 'services.routing.enabled' => false]);
        Event::fake([IncidentFeedUpdated::class]);
        Http::preventStrayRequests();
    }

    private function civilian(): User
    {
        return User::factory()->create(['role' => 'civilian', 'is_admin' => false]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_admin' => true, 'name' => 'Duty Administrator']);
    }

    private function report(User $owner): IncidentReport
    {
        return IncidentReport::query()->create([
            'report_code' => 'INC-'.Str::upper(Str::random(10)), 'reported_by' => $owner->id,
            'reporter_name' => $owner->name, 'incident_type' => 'Road Accident', 'severity' => null,
            'status' => 'received', 'transmission_type' => 'online', 'channel' => 'Internet',
            'description' => 'Simulated road accident near the bridge.', 'location_text' => 'Test bridge',
            'ai_summary' => 'PRIVATE-AI-SUMMARY', 'ai_model_name' => 'PRIVATE-MODEL-NAME',
        ]);
    }

    private function entry(User $owner, string $message, array $extra = []): CivilianActivity
    {
        return app(CivilianActivityRecorder::class)->record($owner, 'report_status', 'Report update', $message, [
            'notifiable' => true, ...$extra,
        ]);
    }

    public function test_server_receipt_is_not_an_admin_acknowledgment_but_admin_status_changes_notify_the_reporter(): void
    {
        $this->travelTo(now()->startOfSecond());
        $owner = $this->civilian();
        $admin = $this->admin();
        $report = $this->report($owner);
        $receipt = CivilianActivity::where('user_id', $owner->id)->sole();
        $this->assertSame('report_submitted', $receipt->kind);
        $this->assertSame('Report received by the server', $receipt->title);
        $this->assertFalse($receipt->notifiable);
        $this->assertStringNotContainsString($admin->name, $receipt->message);
        $this->actingAs($owner)->getJson(route('civilian.system-updates'))
            ->assertOk()->assertJsonPath('unread_count', 0);

        foreach (['acknowledged', 'responding', 'resolved'] as $status) {
            $this->travel(15)->seconds();
            $this->actingAs($admin)->post(route('reports.coordination', $report), [
                'status' => $status, 'response_notes' => 'PRIVATE-COORDINATION-NOTES-'.$status,
            ])->assertSessionHasNoErrors()->assertRedirect();

            $notice = CivilianActivity::where('user_id', $owner->id)->latest('id')->firstOrFail();
            $this->assertTrue($notice->notifiable);
            $this->assertNull($notice->read_at);
            $this->assertSame($report->report_code, $notice->report_code);
            $this->assertSame($report->id, $notice->incident_report_id);
            $this->assertStringContainsString($admin->name, $notice->message);
            $this->assertStringContainsString($report->report_code, $notice->message);
            $this->assertTrue($notice->occurred_at->equalTo(now()));
            $this->assertMatchesRegularExpression(match ($status) {
                'acknowledged' => '/acknowledged|received/i',
                'responding' => '/respond/i',
                'resolved' => '/resolved/i',
            }, $notice->title.' '.$notice->message);

            $feed = $this->actingAs($owner)->getJson(route('civilian.system-updates'))
                ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
                ->assertJsonPath('notifications.0.id', $notice->id)
                ->assertJsonPath('notifications.0.report_code', $report->report_code)
                ->assertJsonPath('notifications.0.report_url', route('reports.show', $report))
                ->assertJsonPath('notifications.0.occurred_at', $notice->occurred_at->toIso8601String());
            foreach (['PRIVATE-COORDINATION-NOTES', 'PRIVATE-AI-SUMMARY', 'PRIVATE-MODEL-NAME'] as $private) {
                $feed->assertDontSee($private);
            }
        }

        $this->getJson(route('civilian.system-updates'))->assertJsonPath('unread_count', 3);
        $this->get(route('civilian.system-log', ['type' => 'report']))->assertOk()
            ->assertSee($report->report_code)->assertSee($admin->name)
            ->assertDontSee('PRIVATE-COORDINATION-NOTES')->assertDontSee('PRIVATE-AI-SUMMARY');
    }

    public function test_redundant_coordination_saves_remain_in_the_log_without_repeated_alerts_but_changed_response_notifies(): void
    {
        $owner = $this->civilian();
        $report = $this->report($owner);
        $this->actingAs($this->admin())->post(route('reports.coordination', $report), [
            'status' => 'acknowledged', 'response_notes' => 'PRIVATE-FIRST-RESPONSE',
        ])->assertSessionHasNoErrors();
        $this->post(route('reports.coordination', $report), [
            'status' => 'acknowledged', 'response_notes' => 'PRIVATE-FIRST-RESPONSE',
        ])->assertSessionHasNoErrors();
        $this->post(route('reports.coordination', $report), [
            'status' => 'acknowledged', 'response_notes' => '  PRIVATE-FIRST-RESPONSE  ',
        ])->assertSessionHasNoErrors();

        $events = CivilianActivity::where('user_id', $owner->id)->where('kind', 'report_status')->orderBy('id')->get();
        $this->assertCount(3, $events);
        $this->assertSame([true, false, false], $events->pluck('notifiable')->all());
        $this->assertSame(3, IncidentReportAudit::where('incident_report_id', $report->id)->count());

        $this->post(route('reports.coordination', $report), [
            'status' => 'acknowledged', 'response_notes' => 'PRIVATE-CHANGED-RESPONSE',
        ])->assertSessionHasNoErrors();
        $latest = CivilianActivity::where('user_id', $owner->id)->latest('id')->firstOrFail();
        $this->assertTrue($latest->notifiable);
        $this->assertSame(4, IncidentReportAudit::where('incident_report_id', $report->id)->count());
        $this->actingAs($owner)->getJson(route('civilian.system-updates'))->assertOk()
            ->assertJsonPath('unread_count', 2)->assertJsonPath('notifications.0.id', $latest->id)
            ->assertDontSee('PRIVATE-FIRST-RESPONSE')->assertDontSee('PRIVATE-CHANGED-RESPONSE');
        $this->get(route('civilian.system-log'))->assertOk()->assertViewHas('events', fn ($events) => $events->total() === 5);
    }

    public function test_new_response_is_first_even_when_more_than_twenty_older_notices_are_unread(): void
    {
        $owner = $this->civilian();
        $report = $this->report($owner);
        for ($index = 0; $index < 25; $index++) {
            $this->entry($owner, 'Older unread update '.$index);
        }
        $this->actingAs($this->admin())->post(route('reports.coordination', $report), [
            'status' => 'responding', 'response_notes' => 'PRIVATE-RESPONSE-NOTES',
        ])->assertSessionHasNoErrors();
        $newest = CivilianActivity::where('user_id', $owner->id)->latest('id')->firstOrFail();
        $feed = $this->actingAs($owner)->getJson(route('civilian.system-updates'))->assertOk()
            ->assertJsonPath('unread_count', 26)->assertJsonCount(20, 'notifications')
            ->assertJsonPath('notifications.0.id', $newest->id)
            ->assertJsonPath('notifications.0.report_url', route('reports.show', $report));
        $ids = array_column($feed->json('notifications'), 'id');
        $sorted = $ids;
        rsort($sorted);
        $this->assertSame($sorted, $ids);
    }

    public function test_notification_links_are_owner_scoped_and_deleted_reports_keep_their_permanent_history(): void
    {
        $owner = $this->civilian();
        $other = $this->civilian();
        $ownReport = $this->report($owner);
        $foreignReport = $this->report($other);
        $own = $this->entry($owner, 'Own report notification', [
            'incident_report_id' => $ownReport->id, 'report_code' => $ownReport->report_code,
        ]);
        $this->entry($other, 'OTHER-ACCOUNT-PRIVATE-NOTICE', [
            'incident_report_id' => $foreignReport->id, 'report_code' => $foreignReport->report_code,
        ]);
        $forgedRelation = $this->entry($owner, 'Owner activity with an inconsistent historical relation', [
            'incident_report_id' => $foreignReport->id, 'report_code' => 'HISTORICAL-CODE',
        ]);

        $feed = $this->actingAs($owner)->getJson(route('civilian.system-updates', ['user_id' => $other->id]))
            ->assertOk()->assertJsonCount(2, 'notifications')
            ->assertDontSee('OTHER-ACCOUNT-PRIVATE-NOTICE')->assertDontSee($foreignReport->report_code)
            ->assertDontSee(route('reports.show', $foreignReport));
        $notices = collect($feed->json('notifications'))->keyBy('id');
        $this->assertSame(route('reports.show', $ownReport), $notices[$own->id]['report_url']);
        $this->assertNull($notices[$forgedRelation->id]['report_url']);
        $this->get(route('reports.show', $foreignReport))->assertNotFound();

        $code = $ownReport->report_code;
        $this->actingAs($this->admin())->delete(route('reports.destroy', $ownReport))->assertRedirect();
        $this->assertNull($own->fresh()->incident_report_id);
        $feed = $this->actingAs($owner)->getJson(route('civilian.system-updates'))->assertOk();
        $notices = collect($feed->json('notifications'))->keyBy('id');
        $this->assertSame($code, $notices[$own->id]['report_code']);
        $this->assertNull($notices[$own->id]['report_url']);
        $this->get(route('civilian.system-log', ['q' => $code]))->assertOk()
            ->assertSee('Own report notification')->assertSee($code);
    }

    public function test_marking_a_notice_read_keeps_the_log_and_cannot_consume_another_accounts_notification(): void
    {
        $owner = $this->civilian();
        $other = $this->civilian();
        $own = $this->entry($owner, 'Permanent acknowledgment record');
        $foreign = $this->entry($other, 'Private other account record');
        $this->actingAs($owner)->postJson(route('civilian.system-updates.read'), ['ids' => [$own->id, $foreign->id]])
            ->assertOk()->assertJsonPath('saved', true);
        $this->assertNotNull($own->fresh()->read_at);
        $this->assertNull($foreign->fresh()->read_at);
        $this->assertDatabaseHas('civilian_activities', ['id' => $own->id, 'message' => 'Permanent acknowledgment record']);
        $this->getJson(route('civilian.system-updates'))->assertOk()->assertJsonPath('unread_count', 0);
        $this->get(route('civilian.system-log', ['q' => 'acknowledgment']))->assertOk()
            ->assertSee('Permanent acknowledgment record')->assertSee('Copy text')->assertDontSee('Private other account record');
    }

    public function test_alert_preferences_are_reported_without_hiding_unread_notices_or_system_log_records(): void
    {
        $owner = $this->civilian();
        $this->entry($owner, 'Saved report status notification');
        $profile = $owner->responderProfile()->create(['notification_profile' => 'critical_alerts,push_notifications']);
        foreach ([
            ['critical_alerts,push_notifications', true, true],
            ['critical_alerts', true, false],
            ['push_notifications', false, false],
            ['', false, false],
        ] as [$value, $reportAlerts, $browserNotifications]) {
            $profile->update(['notification_profile' => $value]);
            $owner->unsetRelation('responderProfile');
            $this->actingAs($owner)->getJson(route('civilian.system-updates'))->assertOk()
                ->assertJsonPath('report_alerts_enabled', $reportAlerts)
                ->assertJsonPath('browser_notifications_enabled', $browserNotifications)
                ->assertJsonPath('unread_count', 1)->assertJsonCount(1, 'notifications');
        }
        $this->get(route('civilian.system-log'))->assertOk()->assertSee('Saved report status notification');
    }

    public function test_new_web_registration_enables_report_alerts_without_claiming_browser_permission(): void
    {
        $this->post(route('register'), [
            'name' => 'New Civilian Web User', 'email' => 'civilian-web@example.com', 'phone' => '09170001234',
            'password' => 'CivilianPass123!', 'password_confirmation' => 'CivilianPass123!',
            'terms_accepted' => true, 'privacy_acknowledged' => true, 'legal_version' => config('legal.version'),
        ])->assertSessionHasNoErrors()->assertRedirect(route('dashboard'));
        $owner = User::where('email', 'civilian-web@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($owner);
        $this->assertTrue($owner->isCivilian());
        $preferences = explode(',', $owner->responderProfile->notification_profile);
        $this->assertContains('critical_alerts', $preferences);
        $this->assertContains('push_notifications', $preferences);
        $this->getJson(route('civilian.system-updates'))->assertOk()
            ->assertJsonPath('report_alerts_enabled', true)->assertJsonPath('browser_notifications_enabled', true);
        // These flags express saved preferences; browser permission still gates delivery in the client.
        $this->assertNull($owner->account_verified_at);
        $this->assertDatabaseCount('incident_reports', 0);
    }

    public function test_new_api_registration_enables_report_alerts_in_the_saved_profile_and_authenticated_settings(): void
    {
        $registered = $this->postJson('/api/v1/auth/register', [
            'name' => 'New Civilian API User', 'email' => 'civilian-api@example.com', 'phone' => '09170005678',
            'password' => 'CivilianPass123!', 'password_confirmation' => 'CivilianPass123!', 'role' => 'civilian',
            'terms_accepted' => true, 'privacy_acknowledged' => true, 'legal_version' => config('legal.version'),
        ])->assertCreated()->assertJsonPath('user.role', 'civilian')->assertJsonPath('user.is_admin', false);
        $owner = User::where('email', 'civilian-api@example.com')->firstOrFail();
        $preferences = explode(',', $owner->responderProfile->notification_profile);
        $this->assertContains('critical_alerts', $preferences);
        $this->assertContains('push_notifications', $preferences);
        $this->withToken($registered->json('token'))->getJson('/api/v1/settings')->assertOk()
            ->assertJsonPath('data.critical_alerts', true)->assertJsonPath('data.push_notifications', true);
    }

    public function test_explicitly_disabled_existing_preferences_survive_sign_in_feed_requests_and_profile_updates(): void
    {
        $owner = User::factory()->create([
            'role' => 'civilian', 'is_admin' => false, 'password' => 'CivilianPass123!',
        ]);
        $profile = $owner->responderProfile()->create(['notification_profile' => '']);
        $this->entry($owner, 'Unread notice remains available despite disabled active alerts');
        $this->post(route('login'), ['identity' => $owner->email, 'password' => 'CivilianPass123!'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->getJson(route('civilian.system-updates'))->assertOk()
            ->assertJsonPath('report_alerts_enabled', false)->assertJsonPath('browser_notifications_enabled', false)
            ->assertJsonPath('unread_count', 1);
        $this->assertSame('', $profile->fresh()->notification_profile);
        $this->post(route('logout'))->assertRedirect();

        $login = $this->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => 'CivilianPass123!'])
            ->assertOk()->assertJsonPath('user.notification_profile', '');
        $this->withToken($login->json('token'))->getJson('/api/v1/settings')->assertOk()
            ->assertJsonPath('data.critical_alerts', false)->assertJsonPath('data.push_notifications', false);
        $this->putJson('/api/v1/profile', ['name' => $owner->name, 'phone' => '09170001111'])->assertOk();
        $this->assertSame('', $profile->fresh()->notification_profile);
        $this->assertSame(1, $owner->responderProfile()->count());
        $this->assertDatabaseHas('civilian_activities', [
            'user_id' => $owner->id, 'message' => 'Unread notice remains available despite disabled active alerts', 'read_at' => null,
        ]);
    }
}
