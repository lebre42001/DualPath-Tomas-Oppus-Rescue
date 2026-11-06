<?php

namespace Tests\Feature;

use App\Events\IncidentFeedUpdated;
use App\Models\CivilianActivity;
use App\Models\IncidentReport;
use App\Models\IncidentReportAudit;
use App\Models\User;
use App\Services\CivilianActivityRecorder;
use App\Support\SystemVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

class CivilianSystemLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['services.ai_text.mode' => 'MANUAL_ONLY', 'services.routing.enabled' => false]);
        Event::fake([IncidentFeedUpdated::class]);
    }

    private function civilian(): User
    {
        return User::factory()->create(['role' => 'civilian', 'account_verified_at' => now()]);
    }

    private function entry(User $user, string $message, array $extra = []): CivilianActivity
    {
        return app(CivilianActivityRecorder::class)->record($user, 'report_status', 'Report update', $message, ['notifiable' => true, ...$extra]);
    }

    private function report(User $user): IncidentReport
    {
        return IncidentReport::query()->create([
            'report_code' => 'INC-'.Str::upper(Str::random(10)), 'reported_by' => $user->id, 'reporter_name' => $user->name,
            'incident_type' => 'Road Accident', 'severity' => null, 'status' => 'received', 'transmission_type' => 'online',
            'channel' => 'Internet', 'description' => 'Road accident near the bridge.', 'location_text' => 'Bridge',
        ]);
    }

    public function test_log_and_notifications_require_an_active_civilian_and_never_expose_another_account(): void
    {
        foreach (['civilian.system-log', 'civilian.system-updates'] as $route) {
            $this->getJson(route($route))->assertUnauthorized();
        }
        $owner = $this->civilian();
        $other = $this->civilian();
        $this->entry($owner, 'Owner-only activity');
        $this->entry($other, 'Other private activity');
        $this->actingAs($owner)->get(route('civilian.system-log', ['q' => 'activity', 'user_id' => $other->id]))
            ->assertOk()->assertSee('Owner-only activity')->assertDontSee('Other private activity');
        $this->getJson(route('civilian.system-updates'))->assertOk()->assertJsonPath('unread_count', 1)
            ->assertJsonCount(1, 'notifications')->assertDontSee('Other private activity')->assertHeader('Cache-Control', 'no-store, private');
        foreach (['admin', 'responder'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'is_admin' => $role === 'admin']))
                ->getJson(route('civilian.system-updates'))->assertForbidden();
            $this->get(route('civilian.system-log'))->assertForbidden();
        }
        $owner->update(['blocked_at' => now()]);
        $this->actingAs($owner)->get(route('civilian.system-log'))->assertRedirect(route('login'));
    }

    public function test_real_report_submission_admin_acknowledgment_resolution_review_and_deletion_are_saved_without_internal_notes(): void
    {
        $civilian = $this->civilian();
        $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true, 'name' => 'Duty Administrator']);
        $report = $this->report($civilian);
        $code = $report->report_code;
        $this->assertSame(1, CivilianActivity::where('kind', 'report_submitted')->count());
        $this->actingAs($admin)->post(route('reports.coordination', $report), ['status' => 'acknowledged', 'response_notes' => 'INTERNAL-PRIVATE-NOTES'])->assertSessionHasNoErrors();
        $this->post(route('reports.severity-review', $report), ['severity_review_label' => 'critical', 'severity_review_notes' => 'PRIVATE-SEVERITY-REASON'])->assertSessionHasNoErrors();
        $this->post(route('reports.coordination', $report), ['status' => 'resolved'])->assertSessionHasNoErrors();
        $this->delete(route('reports.destroy', $report))->assertRedirect();
        $events = CivilianActivity::where('user_id', $civilian->id)->get();
        $this->assertCount(5, $events);
        $this->assertTrue($events->every(fn ($event) => $event->incident_report_id === null));
        $this->assertTrue($events->every(fn ($event) => $event->report_code === $code && $event->occurred_at !== null));
        $this->actingAs($civilian)->get(route('civilian.system-log'))->assertOk()
            ->assertSee('Report acknowledged')->assertSee('Report resolved')->assertSee('Report severity reviewed')
            ->assertSee('Duty Administrator')->assertSee($code)
            ->assertDontSee('INTERNAL-PRIVATE-NOTES')->assertDontSee('PRIVATE-SEVERITY-REASON');
        $this->getJson(route('civilian.system-updates'))->assertJsonPath('unread_count', 4);
    }

    public function test_backfill_is_idempotent_uses_recorded_times_and_does_not_invent_old_session_or_unknown_deletion_history(): void
    {
        $owner = $this->civilian();
        $report = IncidentReport::withoutEvents(fn () => $this->report($owner));
        IncidentReportAudit::withoutEvents(fn () => IncidentReportAudit::create([
            'incident_report_id' => $report->id, 'entry_key' => hash('sha256', 'historic-one'), 'report_code' => $report->report_code,
            'incident_type' => 'Road Accident', 'transmission_type' => 'online', 'actor_name' => 'Historical Admin', 'actor_role' => 'Admin',
            'action' => 'coordination', 'label' => 'Updated report', 'status_after' => 'acknowledged', 'occurred_at' => now()->subDay(), 'imported' => true,
        ]));
        $recorder = app(CivilianActivityRecorder::class);
        $recorder->backfill();
        $recorder->backfill();
        $this->assertDatabaseCount('civilian_activities', 2);
        $this->assertSame(0, CivilianActivity::whereIn('kind', ['login', 'logout'])->count());
        $action = CivilianActivity::where('kind', 'report_status')->sole();
        $this->assertNotNull($action->read_at);
        $this->assertTrue($action->occurred_at->equalTo(IncidentReportAudit::sole()->occurred_at));
    }

    public function test_mark_read_scopes_ids_to_owner_and_does_not_consume_a_new_notification(): void
    {
        $owner = $this->civilian();
        $other = $this->civilian();
        $first = $this->entry($owner, 'First');
        $private = $this->entry($other, 'Private');
        $new = $this->entry($owner, 'New arrival');
        $this->actingAs($owner)->postJson(route('civilian.system-updates.read'), ['ids' => [$first->id, $private->id]])
            ->assertOk()->assertJsonPath('saved', true);
        $this->assertNotNull($first->fresh()->read_at);
        $this->assertNull($private->fresh()->read_at);
        $this->assertNull($new->fresh()->read_at);
        $this->getJson(route('civilian.system-updates'))->assertJsonPath('unread_count', 1);
        $this->postJson(route('civilian.system-updates.read'), ['ids' => array_fill(0, 21, $first->id)])->assertUnprocessable();
    }

    public function test_filters_use_inclusive_philippine_dates_literal_search_and_pagination_with_copyable_escaped_text(): void
    {
        $owner = $this->civilian();
        $this->entry($owner, 'Before local day', ['occurred_at' => '2026-11-06 15:59:59']);
        $this->entry($owner, 'Day starts 100% <script>unsafe</script>', ['occurred_at' => '2026-11-06 16:00:00']);
        $this->entry($owner, 'Day ends 100%', ['occurred_at' => '2026-11-07 15:59:59']);
        $this->entry($owner, 'After local day', ['occurred_at' => '2026-11-07 16:00:00']);
        $this->actingAs($owner)->get(route('civilian.system-log', ['from' => '2026-11-07', 'to' => '2026-11-07', 'q' => '100%']))
            ->assertOk()->assertSee('Day starts 100% <script>unsafe</script>')->assertSee('Day ends 100%')
            ->assertDontSee('Before local day')->assertDontSee('After local day')->assertDontSee('<script>unsafe</script>', false)
            ->assertSee('data-system-log-copy', false)->assertSee('Copy text');
        $this->get(route('civilian.system-log', ['to' => '2026-11-07']))->assertOk();
        $this->getJson(route('civilian.system-log', ['from' => '2026-11-08', 'to' => '2026-11-07']))->assertUnprocessable();
        for ($i = 0; $i < 31; $i++) {
            $this->entry($owner, 'Page entry '.$i);
        }
        $this->get(route('civilian.system-log', ['q' => 'Page entry']))->assertOk()->assertSee('Copies the 30 entries')->assertSee('page=2', false);
    }

    public function test_client_events_are_allowlisted_idempotent_and_cannot_forge_admin_actions_or_other_accounts(): void
    {
        $owner = $this->civilian();
        $other = $this->civilian();
        $version = '0123456789abcdef';
        $mock = \Mockery::mock(SystemVersion::class);
        $mock->shouldReceive('current')->andReturn($version);
        $this->app->instance(SystemVersion::class, $mock);
        $payload = ['event_id' => (string) Str::uuid(), 'kind' => 'app_update_available', 'version' => $version, 'user_id' => $other->id, 'message' => 'FORGED MESSAGE', 'occurred_at' => now()->toIso8601String()];
        $this->actingAs($owner)->postJson(route('civilian.app-events'), $payload)->assertOk();
        $this->postJson(route('civilian.app-events'), $payload)->assertOk();
        $this->postJson(route('civilian.app-events'), [...$payload, 'event_id' => (string) Str::uuid()])->assertOk();
        $this->assertDatabaseCount('civilian_activities', 1);
        $event = CivilianActivity::sole();
        $this->assertSame($owner->id, $event->user_id);
        $this->assertNotSame('FORGED MESSAGE', $event->message);
        $this->getJson(route('civilian.system-updates'))->assertJsonPath('unread_count', 1);
        $this->postJson(route('civilian.app-events'), [...$payload, 'kind' => 'report_status'])->assertUnprocessable();
        $this->postJson(route('civilian.app-events'), [...$payload, 'version' => '1111111111111111'])->assertStatus(409);
        $this->postJson(route('civilian.app-events'), [...$payload, 'occurred_at' => now()->addDay()->toIso8601String()])->assertUnprocessable();
        $this->postJson(route('civilian.app-events'), [...$payload, 'event_id' => (string) Str::uuid(), 'kind' => 'app_update_applied', 'previous_version' => '1111111111111111'])->assertOk();
        $this->getJson(route('civilian.system-updates'))->assertJsonPath('unread_count', 0);
    }

    public function test_successful_web_and_api_login_logout_are_recorded_but_app_closing_is_not_fabricated_as_logout(): void
    {
        $owner = User::factory()->create(['role' => 'civilian', 'password' => 'CivilianPass123!']);
        $this->post(route('login'), ['identity' => $owner->email, 'password' => 'CivilianPass123!'])->assertRedirect();
        $this->assertSame(1, CivilianActivity::where('kind', 'login')->count());
        $this->get(route('dashboard'))->assertOk();
        $this->assertSame(0, CivilianActivity::where('kind', 'logout')->count());
        $this->post(route('logout'))->assertRedirect();
        $this->assertSame(1, CivilianActivity::where('kind', 'logout')->count());
        $response = $this->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => 'CivilianPass123!'])->assertOk();
        $this->withToken($response->json('token'))->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertSame(2, CivilianActivity::where('kind', 'login')->count());
        $this->assertSame(2, CivilianActivity::where('kind', 'logout')->count());
    }

    public function test_bottom_logout_is_replaced_but_sidebar_logout_remains_and_updates_are_global(): void
    {
        $this->actingAs($this->civilian())->get(route('civilian.system-log'))->assertOk()
            ->assertSee('data-civilian-system-log-nav', false)->assertSee('data-civilian-updates-toggle', false)
            ->assertSee('data-civilian-updates-modal', false)->assertSee('sidebar-logout', false)
            ->assertDontSee('mobile-quick-nav__logout', false)->assertSee('data-app-running', false);
        $form = $this->get(route('reports.create'))->assertOk()->getContent();
        $this->assertSame(1, substr_count($form, 'data-connection-url='));
    }

    public function test_release_notes_are_visible_on_first_render_and_remain_after_notices_are_read(): void
    {
        $owner = $this->civilian();
        $notice = $this->entry($owner, 'Own report update');
        $release = config('civilian_releases.0');
        $this->actingAs($owner)->get(route('dashboard'))->assertOk()
            ->assertSee('data-civilian-release-notes', false)->assertSee('Mga giusab')
            ->assertSee($release['title'])->assertSee($release['id'])->assertSee($release['changes'][0])
            ->assertSee('Organized report lists')->assertSee('Move the Updates badge');
        $html = $this->getJson(route('civilian.system-updates'))->assertOk()->assertJsonPath('unread_count', 1)->json('release_notes_html');
        $this->assertStringContainsString($release['title'], $html);
        $this->assertStringContainsString('datetime="'.$release['date'].'"', $html);
        $this->postJson(route('civilian.system-updates.read'), ['ids' => [$notice->id]])->assertOk();
        $this->getJson(route('civilian.system-updates'))->assertOk()->assertJsonPath('unread_count', 0)
            ->assertJsonPath('release_notes_html', $html);
    }

    public function test_release_notes_are_server_owned_escaped_and_describe_new_build_notifications(): void
    {
        $owner = $this->civilian();
        $version = '0123456789abcdef';
        $mock = \Mockery::mock(SystemVersion::class);
        $mock->shouldReceive('current')->andReturn($version);
        $this->app->instance(SystemVersion::class, $mock);
        config(['civilian_releases' => [[
            'id' => 'release-test', 'date' => '2026-11-07', 'title' => '<script>unsafe-title</script>',
            'changes' => ['Search reports by date.', '<img src=x onerror=alert(1)>'],
        ]]]);
        $this->actingAs($owner)->postJson(route('civilian.app-events'), [
            'event_id' => (string) Str::uuid(), 'kind' => 'app_update_available', 'version' => $version,
            'message' => 'FORGED RELEASE', 'release_notes_html' => 'FORGED RELEASE',
        ])->assertOk();
        $this->assertStringContainsString('Search reports by date.', CivilianActivity::sole()->message);
        $html = $this->getJson(route('civilian.system-updates'))->assertOk()->assertDontSee('FORGED RELEASE')->json('release_notes_html');
        $this->assertStringContainsString('&lt;script&gt;unsafe-title&lt;/script&gt;', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
        config(['civilian_releases' => []]);
        $this->getJson(route('civilian.system-updates'))->assertOk()->assertJsonPath('release_notes_html', view('partials.civilian-release-notes')->render());
    }
}
