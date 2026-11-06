<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportConnectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_probe_requires_an_active_session_and_never_creates_reports(): void
    {
        $this->getJson(route('reports.connection'))->assertUnauthorized();
        $civilian = User::factory()->create(['role' => 'civilian', 'account_verified_at' => now()]);
        $this->actingAs($civilian)->getJson(route('reports.connection'))
            ->assertOk()->assertExactJson(['available' => true])
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseCount('incident_reports', 0);
    }

    public function test_blocked_account_cannot_receive_an_available_response(): void
    {
        $civilian = User::factory()->create(['role' => 'civilian', 'blocked_at' => now()]);
        $this->actingAs($civilian)->getJson(route('reports.connection'))->assertRedirect(route('login'));
        $this->assertDatabaseCount('incident_reports', 0);
    }

    public function test_connection_panel_is_visible_only_in_the_civilian_form(): void
    {
        $civilian = User::factory()->create(['role' => 'civilian', 'account_verified_at' => now()]);
        $this->actingAs($civilian)->get(route('reports.create'))->assertOk()
            ->assertSee('data-report-connection', false)->assertSee('Checking online reporting')
            ->assertSee(route('reports.connection'), false)->assertSee('Check again');
        $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
        $this->actingAs($admin)->get(route('reports.create'))->assertOk()
            ->assertDontSee('data-report-connection', false);
    }
}
