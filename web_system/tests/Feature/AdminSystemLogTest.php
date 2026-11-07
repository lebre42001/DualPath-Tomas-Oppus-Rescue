<?php

namespace Tests\Feature;

use App\Events\IncidentFeedUpdated;
use App\Models\AdminActivity;
use App\Models\AdminDutyShift;
use App\Models\IncidentReport;
use App\Models\IncidentReportAudit;
use App\Models\User;
use App\Services\AdminActivityRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminSystemLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['services.ai_text.mode' => 'MANUAL_ONLY', 'services.routing.enabled' => false]);
        Event::fake([IncidentFeedUpdated::class]);
    }

    private function admin(array $attributes = []): User
    {
        return User::factory()->create(['role' => 'admin', 'is_admin' => true, ...$attributes]);
    }

    private function entry(User $actor, array $attributes = []): AdminActivity
    {
        return AdminActivity::query()->create([
            'actor_reference' => $actor->id, 'actor_name' => $actor->name,
            'category' => 'report', 'action' => 'report_coordination', 'label' => 'Test activity',
            'summary' => 'Recorded action', 'target_label' => 'REPORT-TEST', 'occurred_at' => now(), ...$attributes,
        ]);
    }

    public function test_only_admins_can_view_print_declare_duty_or_send_interface_events(): void
    {
        $this->getJson(route('admin.system-log'))->assertUnauthorized();
        foreach (['civilian', 'responder'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'is_admin' => false]));
            $this->get(route('admin.system-log'))->assertRedirect(route('dashboard'));
            $this->get(route('admin.system-log.print'))->assertRedirect(route('dashboard'));
            $this->post(route('admin.system-log.duty'), ['action' => 'start'])->assertRedirect(route('dashboard'));
            $this->postJson(route('admin.system-log.activity'), ['event' => 'alarm_enabled', 'event_id' => Str::uuid()->toString()])->assertRedirect(route('dashboard'));
        }
        $this->assertDatabaseCount('admin_activities', 0);
        $this->assertDatabaseCount('admin_duty_shifts', 0);
    }

    public function test_daily_view_rolls_over_at_philippine_midnight_without_deleting_old_entries(): void
    {
        $actor = $this->admin();
        $this->travelTo(CarbonImmutable::parse('2026-11-07T15:59:00Z'));
        $yesterday = $this->entry($actor, ['label' => 'Yesterday retained', 'occurred_at' => '2026-11-06 15:59:59']);
        $today = $this->entry($actor, ['label' => 'Today retained', 'occurred_at' => '2026-11-06 16:00:00']);
        $next = $this->entry($actor, ['label' => 'Next day retained', 'occurred_at' => '2026-11-07 16:00:00']);
        $this->actingAs($actor)->get(route('admin.system-log', ['category' => 'report']))->assertOk()
            ->assertViewHas('events', fn ($events) => $events->pluck('id')->all() === [$today->id])->assertViewHas('dailyView', true);
        $this->travelTo(CarbonImmutable::parse('2026-11-07T16:00:01Z'));
        $this->get(route('admin.system-log', ['category' => 'report']))->assertOk()
            ->assertViewHas('events', fn ($events) => $events->pluck('id')->all() === [$next->id]);
        $this->get(route('admin.system-log', ['from' => '2026-11-06', 'to' => '2026-11-06', 'category' => 'report']))->assertOk()
            ->assertViewHas('events', fn ($events) => $events->pluck('id')->all() === [$yesterday->id]);
        $this->assertSame(3, AdminActivity::where('category', 'report')->count());
    }

    public function test_each_officers_real_online_and_lora_decisions_are_retained_after_report_deletion(): void
    {
        $officers = collect(range(1, 6))->map(fn ($number) => $this->admin(['name' => 'Officer '.$number]));
        $report = IncidentReport::create(['report_code' => 'LORA-OFFICER-TRACE', 'reporter_name' => 'Test witness', 'incident_type' => 'Road Accident', 'transmission_type' => 'lora', 'channel' => 'LoRa', 'status' => 'received', 'description' => 'Motorcycle collision.', 'location_text' => 'Node site', 'lora_sender_id' => 'NODE_2']);
        $this->actingAs($officers[0])->post(route('reports.coordination', $report), ['status' => 'acknowledged', 'actor_id' => $officers[5]->id])->assertSessionHasNoErrors();
        $this->actingAs($officers[1])->post(route('reports.severity-review', $report), ['severity_review_label' => 'serious', 'severity_review_notes' => 'INTERNAL-SECRET'])->assertSessionHasNoErrors();
        $this->actingAs($officers[2])->post(route('lora-reports.verification', $report), ['verification' => 'confirmed', 'verification_notes' => 'Checked by officer.'])->assertSessionHasNoErrors();
        $this->actingAs($officers[3])->post(route('reports.coordination', $report), ['status' => 'resolved'])->assertSessionHasNoErrors();
        $this->actingAs($officers[4])->delete(route('reports.destroy', $report), ['return_to' => 'lora-reports'])->assertRedirect(route('lora-reports.index'));
        $online = IncidentReport::create(['report_code' => 'ONLINE-OFFICER-TRACE', 'reporter_name' => 'Test witness', 'incident_type' => 'Road Accident', 'transmission_type' => 'online', 'channel' => 'Internet', 'status' => 'received', 'description' => 'Car collision.', 'location_text' => 'Test location']);
        $this->actingAs($officers[5])->post(route('reports.coordination', $online), ['status' => 'acknowledged'])->assertSessionHasNoErrors();
        $onlineEvent = AdminActivity::where('target_label', 'ONLINE-OFFICER-TRACE')->sole();
        $this->assertSame($officers[5]->id, $onlineEvent->actor_reference); $this->assertSame('online', $onlineEvent->source);
        $events = AdminActivity::where('category', 'report')->where('source', 'lora')->orderBy('id')->get();
        $this->assertCount(5, $events);
        $this->assertSame($officers->take(5)->pluck('id')->all(), $events->pluck('actor_reference')->all());
        $this->assertTrue($events->every(fn ($entry) => $entry->target_label === 'LORA-OFFICER-TRACE' && $entry->incident_report_id === null && $entry->source === 'lora'));
        $this->assertTrue($events->contains('action', 'report_verification'));
        $this->actingAs($officers[5])->get(route('admin.system-log', ['from' => '', 'to' => '', 'category' => 'report']))
            ->assertOk()->assertSee('Officer 1')->assertSee('Officer 5')->assertSee('LORA-OFFICER-TRACE')->assertDontSee('INTERNAL-SECRET');
    }

    public function test_login_logout_and_explicit_duty_are_logged_once_and_cannot_impersonate_another_officer(): void
    {
        $actor = $this->admin(['password' => 'password123']);
        $other = $this->admin();
        $this->post(route('admin.login.store'), ['identity' => $actor->email, 'password' => 'password123'])->assertSessionHasNoErrors();
        $this->assertSame(1, AdminActivity::where('action', 'login')->where('actor_reference', $actor->id)->count());
        foreach (range(1, 2) as $_) $this->post(route('admin.system-log.duty'), ['action' => 'start', 'actor_id' => $other->id])->assertRedirect();
        $this->assertSame(1, AdminDutyShift::where('active_user_id', $actor->id)->count());
        $this->assertSame(1, AdminActivity::where('action', 'duty_started')->count());
        $this->post(route('logout'))->assertRedirect(route('admin.login'));
        $this->assertSame(0, AdminDutyShift::whereNull('ended_at')->count());
        $this->assertSame(1, AdminActivity::where('action', 'duty_ended')->count());
        $this->assertSame(1, AdminActivity::where('action', 'logout')->count());
        $this->assertFalse(AdminActivity::where('actor_reference', $other->id)->exists());
        $this->assertStringNotContainsString('password123', AdminActivity::all()->toJson());
    }

    public function test_filters_are_literal_paginated_escaped_and_print_every_matching_page(): void
    {
        $actor = $this->admin(['name' => 'Officer <script>unsafe</script>']);
        foreach (range(1, 34) as $number) $this->entry($actor, ['label' => 'LOG%-'.$number, 'target_label' => 'MATCH-REPORT', 'summary' => '<script>private()</script>']);
        $other = $this->admin(); $this->entry($other, ['label' => 'LOGX-unmatched']);
        $filters = ['from' => '', 'to' => '', 'q' => 'LOG%', 'officer' => $actor->id];
        $this->actingAs($actor)->get(route('admin.system-log', $filters))->assertOk()
            ->assertViewHas('events', fn ($events) => $events->total() === 34 && $events->count() === 30)
            ->assertDontSee('<script>private()</script>', false)->assertDontSee('LOGX-unmatched');
        $this->get(route('admin.system-log.print', $filters))->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertViewHas('entries', fn ($entries) => $entries->count() === 34)->assertSee('LOG%-1')->assertSee('LOG%-34')
            ->assertSee('Print / Save as PDF')->assertDontSee('<script>private()</script>', false)->assertDontSee('<script>unsafe</script>', false);
        $this->getJson(route('admin.system-log', ['from' => '2026-11-08', 'to' => '2026-11-07']))->assertUnprocessable();
    }

    public function test_live_refresh_is_read_only_and_does_not_generate_self_poll_activity(): void
    {
        $actor = $this->admin();
        $this->actingAs($actor)->get(route('admin.system-log'))->assertOk();
        $before = AdminActivity::count();
        $this->withHeader('X-Requested-With', 'XMLHttpRequest')->get(route('admin.system-log'))->assertOk();
        $this->assertSame($before, AdminActivity::count());
    }

    public function test_all_dates_is_preserved_on_later_pages_refresh_and_print_links(): void
    {
        $actor = $this->admin();
        $this->travelTo(CarbonImmutable::parse('2026-11-07T12:00:00Z'));
        foreach (range(1, 31) as $number) $this->entry($actor, ['label' => 'Archive-'.$number, 'occurred_at' => '2026-10-01 12:00:00']);
        $result = $this->actingAs($actor)->get(route('admin.system-log', ['from' => '', 'to' => '', 'category' => 'report']))->assertOk();
        $next = $result->viewData('events')->nextPageUrl();
        $this->assertStringContainsString('from=', $next); $this->assertStringContainsString('to=', $next);
        $this->get($next)->assertOk()->assertViewHas('events', fn ($events) => $events->total() === 31 && $events->count() === 1);
        $print = route('admin.system-log.print', $result->viewData('filters'));
        $result->assertSee($print);
        $this->get($print)->assertOk()->assertViewHas('entries', fn ($entries) => $entries->count() === 31);
    }

    public function test_browser_events_are_distinct_from_saved_decisions_and_idempotent(): void
    {
        $actor = $this->admin(); $other = $this->admin();
        $event = ['event_id' => Str::uuid()->toString(), 'event' => 'alarm_tested', 'actor_id' => $other->id, 'label' => 'Forged success'];
        $this->actingAs($actor)->postJson(route('admin.system-log.activity'), $event)->assertOk()->assertJsonPath('saved', true);
        $this->postJson(route('admin.system-log.activity'), $event)->assertOk();
        $entry = AdminActivity::where('action', 'alarm_tested')->sole();
        $this->assertSame($actor->id, $entry->actor_reference);
        $this->assertSame('Requested report alarm test', $entry->label);
        $this->postJson(route('admin.system-log.activity'), ['event_id' => Str::uuid()->toString(), 'event' => 'report_resolved'])->assertUnprocessable();
    }

    public function test_backfill_is_idempotent_and_preserves_unknown_historical_times_without_fake_sessions(): void
    {
        $audit = IncidentReportAudit::withoutEvents(fn () => IncidentReportAudit::create([
            'entry_key' => hash('sha256', 'historical'), 'report_code' => 'HISTORICAL', 'incident_type' => 'Road Accident', 'transmission_type' => 'online',
            'actor_name' => 'Recorded officer', 'actor_role' => 'Admin', 'action' => 'coordination', 'label' => 'Old recorded action', 'imported' => true,
        ]));
        $service = app(AdminActivityRecorder::class);
        $this->assertSame(1, $service->backfill()); $this->assertSame(0, $service->backfill());
        $entry = AdminActivity::sole();
        $this->assertNull($entry->occurred_at); $this->assertNull($entry->actor_reference); $this->assertTrue($entry->imported);
        $this->assertSame('report-audit:'.$audit->id, $entry->entry_key);
        $this->assertSame(0, AdminActivity::whereIn('action', ['login', 'logout', 'duty_started'])->count());
        $this->expectException(\LogicException::class); $entry->delete();
    }
}
