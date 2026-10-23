<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CivilianDirectoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_accounts_are_paginated_fifteen_at_a_time_without_duplicates_or_staff(): void
    {
        $users = User::factory()->count(31)->create(['role' => 'civilian', 'is_admin' => false, 'created_at' => now()]);
        $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
        User::factory()->create(['role' => 'responder', 'is_admin' => false]);
        $expected = $users->sortByDesc('id')->pluck('id')->values()->all();
        $seen = [];
        foreach ([1 => 15, 2 => 15, 3 => 1] as $page => $size) {
            // Main directory filters must not hide accounts in the View all list.
            $response = $this->actingAs($admin)->getJson('/civilian-accounts/all?page='.$page.'&search=missing&status=blocked')
                ->assertOk()->assertJsonCount($size, 'accounts')
                ->assertJsonPath('pagination.page', $page)->assertJsonPath('pagination.lastPage', 3)
                ->assertJsonPath('pagination.total', 31)
                ->assertJsonMissingPath('accounts.0.password')->assertJsonMissingPath('accounts.0.api_token')
                ->assertJsonMissingPath('accounts.0.last_login_ip_address');
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            $seen = array_merge($seen, array_column($response->json('accounts'), 'id'));
        }
        $this->assertSame($expected, $seen);
        $this->getJson('/civilian-accounts/all?page=4')->assertOk()->assertJsonCount(0, 'accounts');
    }

    public function test_only_admin_can_read_the_full_directory(): void
    {
        $this->getJson('/civilian-accounts/all')->assertUnauthorized();
        foreach (['civilian', 'responder'] as $role) {
            $user = User::factory()->create(['role' => $role, 'is_admin' => false]);
            $this->actingAs($user)->getJson('/civilian-accounts/all')->assertForbidden();
        }
    }

    public function test_empty_directory_and_invalid_pages_are_handled(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
        $this->actingAs($admin)->getJson('/civilian-accounts/all')->assertOk()
            ->assertJsonCount(0, 'accounts')->assertJsonPath('pagination.total', 0)
            ->assertJsonPath('pagination.lastPage', 1)->assertJsonPath('pagination.from', null);
        foreach (['0', '-1', 'abc', '1.5'] as $page) {
            $this->getJson('/civilian-accounts/all?page='.$page)->assertUnprocessable()->assertJsonValidationErrors('page');
        }
        $this->get('/civilian-accounts')->assertOk()->assertSee('data-civilian-directory-open', false)
            ->assertSee('data-civilian-directory-modal', false);
    }

    public function test_list_uses_current_presence_and_blocked_accounts_are_offline(): void
    {
        $users = User::factory()->count(3)->create(['role' => 'civilian', 'is_admin' => false]);
        $users[1]->forceFill(['blocked_at' => now()])->save();
        foreach ($users as $index => $user) {
            DB::table('civilian_presences')->insert([
                'user_id' => $user->id, 'connection_key' => hash('sha256', 'connection-'.$user->id),
                'context_key' => hash('sha256', 'context-'.$user->id), 'client_sequence' => 1, 'is_online' => true,
                'last_seen_at' => $index === 2 ? now()->subMinutes(2) : now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
        $accounts = collect($this->actingAs($admin)->getJson('/civilian-accounts/all')->assertOk()->json('accounts'))->keyBy('id');
        $this->assertTrue($accounts[$users[0]->id]['online']);
        $this->assertFalse($accounts[$users[1]->id]['online']);
        $this->assertTrue($accounts[$users[1]->id]['blocked']);
        $this->assertFalse($accounts[$users[2]->id]['online']);
    }
}
