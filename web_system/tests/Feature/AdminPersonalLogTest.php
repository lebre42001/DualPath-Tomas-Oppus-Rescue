<?php

namespace Tests\Feature;

use App\Models\AdminActivity;
use App\Models\AdminDutyShift;
use App\Models\User;
use App\Services\AdminActivityRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminPersonalLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['services.ai_text.mode' => 'MANUAL_ONLY', 'services.routing.enabled' => false]);
    }

    private function admin(array $attributes = []): User
    {
        return User::factory()->create(['role' => 'admin', 'is_admin' => true, ...$attributes]);
    }

    private function entry(User $actor, array $attributes = []): AdminActivity
    {
        return AdminActivity::query()->create([
            'actor_reference' => $actor->id,
            'actor_name' => $actor->name,
            'category' => 'report',
            'action' => 'report_coordination',
            'label' => 'Recorded report action',
            'summary' => 'Synthetic officer activity.',
            'target_label' => 'TEST-REPORT',
            'source' => 'online',
            'occurred_at' => now(),
            ...$attributes,
        ]);
    }

    public function test_personal_log_view_print_and_duty_require_an_authenticated_admin(): void
    {
        $this->get(route('profile.admin-logs'))->assertRedirect(route('admin.login'));
        $this->get(route('profile.admin-logs.print'))->assertRedirect(route('admin.login'));
        $this->post(route('profile.admin-logs.duty'), ['action' => 'start'])->assertRedirect(route('admin.login'));
        $this->getJson(route('profile.admin-logs'))->assertUnauthorized();
        $this->getJson(route('profile.admin-logs.print'))->assertUnauthorized();
        $this->postJson(route('profile.admin-logs.duty'), ['action' => 'start'])->assertUnauthorized();

        foreach (['civilian', 'responder'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'is_admin' => false]));
            $this->get(route('profile.admin-logs'))->assertRedirect(route('dashboard'));
            $this->get(route('profile.admin-logs.print'))->assertRedirect(route('dashboard'));
            $this->post(route('profile.admin-logs.duty'), ['action' => 'start'])->assertRedirect(route('dashboard'));
        }

        $this->assertDatabaseCount('admin_activities', 0);
        $this->assertDatabaseCount('admin_duty_shifts', 0);
    }

    public function test_personal_history_is_bound_to_the_signed_in_identity_even_when_query_identity_is_forged(): void
    {
        $actor = $this->admin(['name' => 'Officer Shared Name']);
        $other = $this->admin(['name' => 'Officer Shared Name']);
        $own = $this->entry($actor, ['label' => 'OWN-PRIVATE-ACTION']);
        $this->entry($other, ['label' => 'OTHER-PRIVATE-ACTION']);
        $this->entry($actor, ['actor_reference' => null, 'label' => 'UNATTRIBUTED-HISTORY']);
        $filters = [
            'from' => '', 'to' => '', 'category' => 'report',
            'officer' => $other->id, 'actor_id' => $other->id, 'actor_reference' => $other->id,
        ];

        $this->actingAs($actor)->get(route('profile.admin-logs', $filters))->assertOk()
            ->assertViewHas('events', fn ($events) => $events->pluck('id')->all() === [$own->id])
            ->assertSee('OWN-PRIVATE-ACTION')->assertDontSee('OTHER-PRIVATE-ACTION')->assertDontSee('UNATTRIBUTED-HISTORY');
        $this->get(route('profile.admin-logs.print', $filters))->assertOk()
            ->assertViewHas('entries', fn ($entries) => $entries->pluck('id')->all() === [$own->id])
            ->assertDontSee('OTHER-PRIVATE-ACTION')->assertDontSee('UNATTRIBUTED-HISTORY');

        $this->actingAs($other)->get(route('profile.admin-logs', ['from' => '', 'to' => '', 'category' => 'report']))
            ->assertOk()->assertSee('OTHER-PRIVATE-ACTION')->assertDontSee('OWN-PRIVATE-ACTION');
    }

    public function test_personal_and_general_history_read_the_same_records_without_copying_actions(): void
    {
        $actor = $this->admin();
        $other = $this->admin();
        $own = $this->entry($actor, ['label' => 'OWN-SHARED-RECORD']);
        $otherEntry = $this->entry($other, ['label' => 'OTHER-SHARED-RECORD']);
        $filters = ['from' => '', 'to' => '', 'category' => 'report'];

        $this->actingAs($actor)->get(route('profile.admin-logs', $filters))->assertOk()
            ->assertViewHas('events', fn ($events) => $events->pluck('id')->all() === [$own->id]);
        $this->get(route('admin.system-log', $filters))->assertOk()
            ->assertViewHas('events', fn ($events) => $events->pluck('id')->sort()->values()->all() === [$own->id, $otherEntry->id]);
        $this->get(route('profile.admin-logs.print', $filters))->assertOk();

        $this->assertSame(2, AdminActivity::query()->where('category', 'report')->count());
        $this->assertSame(1, AdminActivity::query()->where('label', 'OWN-SHARED-RECORD')->count());
    }

    public function test_all_recorded_activity_categories_are_available_for_the_current_officer(): void
    {
        $actor = $this->admin();
        $other = $this->admin();
        $ownIds = [];
        foreach (['account', 'duty', 'report', 'navigation', 'system'] as $category) {
            $ownIds[] = $this->entry($actor, ['category' => $category, 'label' => 'OWN-'.$category])->id;
            $this->entry($other, ['category' => $category, 'label' => 'OTHER-'.$category]);
        }

        $this->actingAs($actor)->get(route('profile.admin-logs', ['from' => '', 'to' => '']))->assertOk()
            ->assertViewHas('events', fn ($events) => $events->pluck('id')->sort()->values()->all() === $ownIds);
        foreach (['account', 'duty', 'report', 'navigation', 'system'] as $category) {
            $this->get(route('profile.admin-logs', ['from' => '', 'to' => '', 'category' => $category, 'q' => 'OWN-']))->assertOk()
                ->assertViewHas('events', fn ($events) => $events->count() === 1 && $events->first()->category === $category)
                ->assertSee('OWN-'.$category)->assertDontSee('OTHER-'.$category);
        }
    }

    public function test_search_category_source_and_philippine_date_bounds_apply_together(): void
    {
        $actor = $this->admin();
        $other = $this->admin();
        $matching = $this->entry($actor, ['label' => 'MATCH%-A', 'source' => 'lora', 'occurred_at' => '2026-11-08 16:00:00']);
        $this->entry($actor, ['label' => 'MATCH%-BEFORE', 'source' => 'lora', 'occurred_at' => '2026-11-08 15:59:59']);
        $this->entry($actor, ['label' => 'MATCH%-AFTER', 'source' => 'lora', 'occurred_at' => '2026-11-09 16:00:00']);
        $this->entry($actor, ['label' => 'MATCH%-ONLINE', 'source' => 'online', 'occurred_at' => '2026-11-09 01:00:00']);
        $this->entry($actor, ['label' => 'MATCH%-SYSTEM', 'category' => 'system', 'source' => 'lora', 'occurred_at' => '2026-11-09 01:00:00']);
        $this->entry($actor, ['label' => 'MATCHX-NOT-LITERAL', 'source' => 'lora', 'occurred_at' => '2026-11-09 01:00:00']);
        $this->entry($other, ['label' => 'MATCH%-OTHER', 'source' => 'lora', 'occurred_at' => '2026-11-09 01:00:00']);
        $filters = ['from' => '2026-11-09', 'to' => '2026-11-09', 'q' => 'MATCH%', 'category' => 'report', 'source' => 'lora'];

        $this->actingAs($actor)->get(route('profile.admin-logs', $filters))->assertOk()
            ->assertViewHas('events', fn ($events) => $events->pluck('id')->all() === [$matching->id]);
        $this->get(route('profile.admin-logs.print', $filters))->assertOk()
            ->assertViewHas('entries', fn ($entries) => $entries->pluck('id')->all() === [$matching->id]);
        $this->getJson(route('profile.admin-logs', ['from' => '2026-11-10', 'to' => '2026-11-09']))->assertUnprocessable();
    }

    public function test_default_today_rolls_over_at_philippine_midnight_and_retains_previous_days(): void
    {
        $actor = $this->admin();
        $this->travelTo(CarbonImmutable::parse('2026-11-09T15:59:59Z'));
        $today = $this->entry($actor, ['occurred_at' => '2026-11-08 16:00:00']);
        $tomorrow = $this->entry($actor, ['occurred_at' => '2026-11-09 16:00:00']);

        $this->actingAs($actor)->get(route('profile.admin-logs', ['category' => 'report']))->assertOk()
            ->assertViewHas('dailyView', true)
            ->assertViewHas('events', fn ($events) => $events->pluck('id')->all() === [$today->id]);
        $this->travelTo(CarbonImmutable::parse('2026-11-09T16:00:01Z'));
        $this->get(route('profile.admin-logs', ['category' => 'report']))->assertOk()
            ->assertViewHas('events', fn ($events) => $events->pluck('id')->all() === [$tomorrow->id]);
        $this->assertSame(2, AdminActivity::query()->where('category', 'report')->count());
    }

    public function test_all_dates_search_and_source_persist_across_pages_and_print_every_matching_page(): void
    {
        $actor = $this->admin();
        $other = $this->admin();
        $this->travelTo(CarbonImmutable::parse('2026-11-09T12:00:00Z'));
        foreach (range(1, 34) as $number) {
            $this->entry($actor, ['label' => 'ARCHIVE%-'.$number, 'source' => 'lora', 'occurred_at' => '2026-10-01 12:00:00']);
            $this->entry($other, ['label' => 'ARCHIVE%-OTHER-'.$number, 'source' => 'lora', 'occurred_at' => '2026-10-01 12:00:00']);
        }
        $filters = ['from' => '', 'to' => '', 'q' => 'ARCHIVE%', 'category' => 'report', 'source' => 'lora'];

        $result = $this->actingAs($actor)->get(route('profile.admin-logs', $filters))->assertOk()
            ->assertViewHas('dailyView', false)
            ->assertViewHas('events', fn ($events) => $events->total() === 34 && $events->count() === 30);
        $next = $result->viewData('events')->nextPageUrl();
        $this->assertStringContainsString('/profile/admin-logs?', $next);
        $this->assertStringContainsString('from=', $next);
        $this->assertStringContainsString('to=', $next);
        $this->get($next)->assertOk()
            ->assertViewHas('events', fn ($events) => $events->total() === 34 && $events->count() === 4 && $events->every(fn ($event) => $event->actor_reference === $actor->id));
        $this->assertEquals($filters, $result->viewData('filters'));
        $printUrl = route('profile.admin-logs.print', $result->viewData('filters'));
        $result->assertSee($printUrl);
        $this->get($printUrl)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertViewHas('entries', fn ($entries) => $entries->count() === 34)
            ->assertSee('ARCHIVE%-1')->assertSee('ARCHIVE%-34')->assertDontSee('ARCHIVE%-OTHER');
    }

    public function test_print_limit_counts_only_the_current_officers_matching_records(): void
    {
        $actor = $this->admin();
        $other = $this->admin();
        $row = [
            'actor_name' => 'Synthetic officer', 'category' => 'report', 'action' => 'report_coordination',
            'label' => 'Bulk test activity', 'summary' => 'Synthetic log entry.', 'source' => 'online',
            'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ];
        foreach (array_chunk(array_fill(0, 2001, ['actor_reference' => $other->id, ...$row]), 250) as $rows) {
            DB::table('admin_activities')->insert($rows);
        }
        $this->entry($actor);
        $filters = ['from' => '', 'to' => '', 'category' => 'report'];
        $this->actingAs($actor)->get(route('profile.admin-logs.print', $filters))->assertOk()
            ->assertViewHas('entries', fn ($entries) => $entries->count() === 1 && $entries->first()->actor_reference === $actor->id);

        foreach (array_chunk(array_fill(0, 2000, ['actor_reference' => $actor->id, ...$row]), 250) as $rows) {
            DB::table('admin_activities')->insert($rows);
        }
        $this->getJson(route('profile.admin-logs.print', $filters))->assertUnprocessable()->assertJsonValidationErrors('from');
    }

    public function test_personal_duty_controls_log_start_and_end_once_for_the_current_officer(): void
    {
        $actor = $this->admin();
        $other = $this->admin();
        app(AdminActivityRecorder::class)->startDuty($other);

        $this->actingAs($actor);
        foreach (range(1, 2) as $_) {
            $this->post(route('profile.admin-logs.duty'), ['action' => 'start', 'actor_id' => $other->id])->assertRedirect(route('profile.admin-logs'));
        }
        $this->get(route('profile.admin-logs', ['category' => 'duty']))->assertOk()
            ->assertViewHas('shifts', fn ($shifts) => $shifts->count() === 1 && $shifts->first()->actor_reference === $actor->id)
            ->assertViewHas('events', fn ($events) => $events->count() === 1 && $events->first()->action === 'duty_started' && $events->first()->actor_reference === $actor->id);
        foreach (range(1, 2) as $_) {
            $this->post(route('profile.admin-logs.duty'), ['action' => 'end', 'actor_id' => $other->id])->assertRedirect(route('profile.admin-logs'));
        }

        $this->assertSame(1, AdminActivity::query()->where('actor_reference', $actor->id)->where('action', 'duty_started')->count());
        $this->assertSame(1, AdminActivity::query()->where('actor_reference', $actor->id)->where('action', 'duty_ended')->count());
        $this->assertNotNull(AdminDutyShift::query()->where('actor_reference', $actor->id)->sole()->ended_at);
        $this->assertNull(AdminDutyShift::query()->where('actor_reference', $other->id)->sole()->ended_at);
        $this->get(route('profile.admin-logs', ['category' => 'duty']))->assertOk()
            ->assertViewHas('shifts', fn ($shifts) => $shifts->isEmpty())
            ->assertViewHas('events', fn ($events) => $events->count() === 2 && $events->every(fn ($event) => $event->actor_reference === $actor->id));
        $this->get(route('admin.system-log', ['category' => 'duty']))->assertOk()
            ->assertViewHas('events', fn ($events) => $events->count() === 3);
    }

    public function test_profile_link_is_admin_only_and_personal_ajax_refresh_does_not_log_itself(): void
    {
        $actor = $this->admin();
        $this->actingAs($actor)->get(route('profile.show'))->assertOk()->assertSee(route('profile.admin-logs'));
        $this->get(route('profile.admin-logs'))->assertOk();
        $before = AdminActivity::query()->count();
        $this->withHeader('X-Requested-With', 'XMLHttpRequest')->get(route('profile.admin-logs'))->assertOk();
        $this->assertSame($before, AdminActivity::query()->count());

        foreach (['civilian', 'responder'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'is_admin' => false]));
            $this->get(route('profile.show'))->assertOk()->assertDontSee(route('profile.admin-logs'));
        }
    }
}
