<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CivilianTutorialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function civilian(bool $verified = false): User
    {
        return User::factory()->create(['role' => 'civilian', 'account_verified_at' => $verified ? now() : null, 'password' => 'CivilianPass123!']);
    }

    public function test_unverified_password_login_lands_on_home_even_with_an_intended_report_route(): void
    {
        $user = $this->civilian();
        $this->withSession(['url.intended' => route('reports.create')])->post(route('login'), ['identity' => $user->email, 'password' => 'CivilianPass123!'])
            ->assertRedirect(route('dashboard'))->assertSessionMissing('url.intended');
        $this->get(route('dashboard'))->assertOk()->assertSee('data-civilian-tutorial', false)
            ->assertSee('data-auto="true"', false)->assertSee('Quick tutorial');
        $this->assertNull($user->fresh()->account_verified_at);
    }

    public function test_unverified_google_login_can_browse_home_first_but_verified_login_keeps_its_destination(): void
    {
        config(['services.google.client_id' => 'fixture-client', 'services.google.client_secret' => 'fixture-secret', 'services.google.redirect' => route('auth.google.callback')]);
        $user = $this->civilian();
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'fixture-token']),
            'https://www.googleapis.com/oauth2/v3/userinfo' => Http::response(['sub' => 'fixture-google', 'email' => $user->email, 'name' => $user->name, 'email_verified' => true]),
        ]);
        $this->withSession(['google_oauth_state' => 'fixture-state', 'url.intended' => route('account.verify')])
            ->get(route('auth.google.callback', ['state' => 'fixture-state', 'code' => 'fixture-code']))->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->assertNull($user->fresh()->account_verified_at);
        $this->post(route('logout'));
        $user->forceFill(['account_verified_at' => now()])->save();
        $this->withSession(['url.intended' => route('profile.show')])->post(route('login'), ['identity' => $user->email, 'password' => 'CivilianPass123!'])->assertRedirect(route('profile.show'));
    }

    public function test_tutorial_is_read_only_replayable_and_does_not_unlock_real_reporting(): void
    {
        $user = $this->civilian();
        $this->actingAs($user)->get(route('dashboard', ['tutorial' => 1]))->assertOk()
            ->assertSee('data-civilian-tour-report', false)->assertSee('data-tutorial-next', false)
            ->assertSee('data-tutorial-skip', false)->assertSee('data-tutorial-back', false)->assertSee('data-tutorial-verify', false);
        $demo = view('partials.civilian-tutorial', ['isAccountVerified' => false])->render();
        $this->assertStringNotContainsString('<form', $demo);
        $this->assertStringNotContainsString('type="file"', $demo);
        $this->assertStringNotContainsString('data-report-submit', $demo);
        $this->assertStringNotContainsString('name="incident_type"', $demo);
        $this->assertStringNotContainsString('data-incident-picker', $demo);
        $this->assertStringContainsString('No report is sent.', $demo);
        $this->assertStringContainsString('data-tutorial-example="incident"', $demo);
        $this->assertStringContainsString('data-tutorial-preview-target="incident"', $demo);
        $this->assertStringContainsString('Type of incident', $demo);
        $this->assertStringContainsString('Type of incident preview: Road Accident selected', $demo);
        $this->assertLessThan(strpos($demo, 'data-tutorial-example="photo"'), strpos($demo, 'data-tutorial-example="incident"'));
        $this->get(route('settings.index'))->assertOk()->assertSee(route('dashboard', ['tutorial' => 1]), false);
        $this->get(route('account.verify'))->assertOk()->assertSee('Quick tutorial first')->assertSee('Do this later');
        $this->get(route('reports.create'))->assertRedirect(route('account.verify'));
        $this->post(route('reports.store'), ['description' => 'Trying to bypass verification after the tutorial.'])->assertRedirect(route('account.verify'));
        $this->assertDatabaseCount('incident_reports', 0);
        $this->get(route('reports.drafts'))->assertOk()->assertDontSee('data-tutorial-next', false);
        $this->actingAs($this->civilian(true))->get(route('dashboard'))->assertOk()->assertSee('data-auto="false"', false);
        $this->actingAs(User::factory()->create(['role' => 'admin', 'is_admin' => true]))->get(route('admin.dashboard'))->assertOk()->assertDontSee('data-tutorial-next', false);
    }
}
