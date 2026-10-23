<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\CivilianPresence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CivilianPresenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        $this->withCredentials();
        $this->withCookie(config('session.cookie'), str_repeat('a', 40));
    }

    public function test_saved_browser_session_alone_does_not_mark_an_account_online(): void
    {
        $civilian = $this->civilian();
        $admin = $this->admin();
        DB::table('sessions')->insert(['id' => str_repeat('x', 40), 'user_id' => $civilian->id,
            'ip_address' => '127.0.0.1', 'user_agent' => 'QA browser', 'payload' => '', 'last_activity' => now()->timestamp]);
        $this->actingAs($admin)->getJson('/civilian-accounts/presence?ids[]='.$civilian->id)
            ->assertOk()->assertJsonPath('accounts.0.online', false);
        $this->heartbeat($civilian);
        $this->actingAs($admin)->getJson('/civilian-accounts/presence?ids[]='.$civilian->id)
            ->assertOk()->assertJsonPath('accounts.0.online', true);
    }

    public function test_presence_expires_after_sixty_seconds_and_recovers_on_heartbeat(): void
    {
        $civilian = $this->civilian();
        $this->heartbeat($civilian);
        $this->assertTrue($this->online($civilian));
        $this->travel(60)->seconds();
        $this->assertTrue($this->online($civilian));
        $this->travel(1)->seconds();
        $this->assertFalse($this->online($civilian));
        $this->heartbeat($civilian, sequence: 2);
        $this->assertTrue($this->online($civilian));
    }

    public function test_closing_one_tab_does_not_mark_another_connected_tab_offline(): void
    {
        $civilian = $this->civilian();
        $this->heartbeat($civilian, 'tab-one-client');
        $this->heartbeat($civilian, 'tab-two-client');
        $this->heartbeat($civilian, 'tab-one-client', 2, 'offline');
        $this->assertTrue($this->online($civilian));
        $this->heartbeat($civilian, 'tab-two-client', 2, 'offline');
        $this->assertFalse($this->online($civilian));
    }

    public function test_delayed_beacon_and_heartbeat_cannot_overwrite_newer_client_state(): void
    {
        $civilian = $this->civilian();
        $this->heartbeat($civilian, sequence: 2);
        $this->heartbeat($civilian, sequence: 1, state: 'offline');
        $this->assertTrue($this->online($civilian));
        $this->heartbeat($civilian, sequence: 3, state: 'offline');
        $this->heartbeat($civilian, sequence: 2);
        $this->assertFalse($this->online($civilian));
        $this->heartbeat($civilian, sequence: 4);
        $this->assertTrue($this->online($civilian));
        $this->assertDatabaseCount('civilian_presences', 1);
    }

    public function test_logout_ends_only_that_device_and_another_device_can_remain_online(): void
    {
        $civilian = $this->civilian();
        $this->heartbeat($civilian, 'device-a-client');
        $this->withCookie(config('session.cookie'), str_repeat('b', 40));
        $this->heartbeat($civilian, 'device-b-client');
        $this->withCookie(config('session.cookie'), str_repeat('a', 40));
        $this->actingAs($civilian)->post('/logout')->assertRedirect();
        $this->assertTrue($this->online($civilian));
        $this->withCookie(config('session.cookie'), str_repeat('b', 40));
        $this->actingAs($civilian)->post('/logout')->assertRedirect();
        $this->assertFalse($this->online($civilian));
    }

    public function test_blocking_a_civilian_revokes_presence_immediately(): void
    {
        $civilian = $this->civilian();
        $this->heartbeat($civilian);
        $this->actingAs($this->admin())->patch('/civilian-accounts/'.$civilian->id.'/block')->assertRedirect();
        $this->assertFalse($this->online($civilian));
        $this->actingAs($civilian->fresh())->postJson('/account/presence', $this->packet())->assertRedirect(route('login'));
        $this->assertFalse($this->online($civilian));
    }

    public function test_api_presence_is_bound_to_its_authenticated_account_and_logout_revokes_it(): void
    {
        $civilian = $this->civilian();
        $other = $this->civilian();
        $token = 'presence-api-test-token';
        $civilian->forceFill(['api_token' => hash('sha256', $token)])->save();
        $headers = ['Authorization' => 'Bearer '.$token];
        $this->postJson('/api/v1/account/presence', [...$this->packet(), 'user_id' => $other->id], $headers)->assertOk();
        $this->assertTrue($this->online($civilian));
        $this->assertFalse($this->online($other));
        $this->postJson('/api/v1/auth/logout', [], $headers)->assertOk();
        $this->assertFalse($this->online($civilian));
        $this->postJson('/api/v1/account/presence', $this->packet(), $headers)->assertUnauthorized();
    }

    public function test_presence_endpoints_reject_guests_and_unauthorized_accounts(): void
    {
        $civilian = $this->civilian();
        $this->postJson('/account/presence', $this->packet())->assertUnauthorized();
        $this->postJson('/api/v1/account/presence', $this->packet())->assertUnauthorized();
        $this->getJson('/civilian-accounts/presence?ids[]='.$civilian->id)->assertUnauthorized();
        $this->actingAs($civilian)->getJson('/civilian-accounts/presence?ids[]='.$civilian->id)->assertForbidden();
        $this->actingAs($this->admin())->postJson('/account/presence', $this->packet())->assertForbidden();
        $this->assertDatabaseCount('civilian_presences', 0);
    }

    public function test_invalid_heartbeat_data_is_rejected_and_time_is_set_by_the_server(): void
    {
        $civilian = $this->civilian();
        $this->actingAs($civilian);
        foreach ([['client_id' => 'bad|id'], ['sequence' => 0], ['sequence' => -1], ['state' => 'anything']] as $invalid) {
            $this->postJson('/account/presence', array_replace($this->packet(), $invalid))->assertUnprocessable();
        }
        $this->assertDatabaseCount('civilian_presences', 0);
        $this->postJson('/account/presence', [...$this->packet(), 'last_seen_at' => now()->addYear()->toIso8601String()])->assertOk();
        $this->travel(61)->seconds();
        $this->assertFalse($this->online($civilian));
    }

    public function test_online_and_offline_filters_follow_heartbeat_presence(): void
    {
        $online = $this->civilian();
        $offline = $this->civilian();
        $this->heartbeat($online);
        $this->actingAs($this->admin())->get('/civilian-accounts?status=online')->assertOk()
            ->assertViewHas('civilianAccounts', fn ($accounts): bool => $accounts->pluck('id')->all() === [$online->id]);
        $this->get('/civilian-accounts?status=offline')->assertOk()
            ->assertViewHas('civilianAccounts', fn ($accounts): bool => $accounts->pluck('id')->all() === [$offline->id]);
        $this->getJson('/civilian-accounts/presence?ids[]='.$online->id.'&ids[]='.$offline->id)
            ->assertOk()->assertJsonCount(2, 'accounts')->assertJsonMissingPath('accounts.0.email');
    }

    private function heartbeat(User $user, string $client = 'presence-test-client', int $sequence = 1, string $state = 'online'): void
    {
        $this->actingAs($user)->postJson('/account/presence', [
            'client_id' => $client, 'sequence' => $sequence, 'state' => $state,
        ])->assertOk();
    }

    private function online(User $user): bool
    {
        return (bool) (app(CivilianPresence::class)->snapshots([$user->id])->get($user->id)?->is_online ?? false);
    }

    private function packet(): array
    {
        return ['client_id' => 'presence-test-client', 'sequence' => 1, 'state' => 'online'];
    }

    private function civilian(): User
    {
        return User::factory()->create(['role' => 'civilian', 'is_admin' => false]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    }
}
