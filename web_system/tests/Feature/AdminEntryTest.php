<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminEntryTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_entry_sends_guests_to_the_admin_login_page(): void
    {
        $this->get('/admin')->assertRedirect(route('admin.login'));
        $this->followingRedirects()->get('/admin')->assertOk();
    }

    public function test_protected_admin_pages_send_guests_to_the_admin_login_page(): void
    {
        $this->get('/admin/dashboard')->assertRedirect(route('admin.login'));
        $this->get('/admin/text-ai-test')->assertRedirect(route('admin.login'));
        $this->get('/reports')->assertRedirect(route('login'));
    }

    public function test_signed_in_admin_can_open_the_dashboard_from_admin_entry(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);

        $this->actingAs($admin)->get('/admin')->assertRedirect(route('admin.dashboard'));
        $this->followingRedirects()->get('/admin')->assertOk();
    }

    public function test_civilian_cannot_gain_admin_access_through_admin_entry(): void
    {
        $civilian = User::factory()->create(['role' => 'civilian', 'is_admin' => false]);

        $this->actingAs($civilian)->get('/admin')->assertRedirect(route('admin.dashboard'));
        $this->get('/admin/dashboard')->assertRedirect(route('dashboard'));
    }

    public function test_admin_login_preserves_the_intended_dashboard_destination(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);

        $this->get('/admin/dashboard')->assertRedirect(route('admin.login'));
        $this->post('/admin/login', [
            'identity' => $admin->email,
            'password' => 'password',
        ])->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($admin);
    }
}
