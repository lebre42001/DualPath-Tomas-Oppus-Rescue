<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LegalDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private function registration(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Policy Test Civilian',
            'email' => 'policy-test@example.com',
            'phone' => '09170000000',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'terms_accepted' => true,
            'privacy_acknowledged' => true,
            'legal_version' => config('legal.version'),
        ], $overrides);
    }

    public function test_documents_are_readable_before_login_and_after_login(): void
    {
        foreach (['/privacy-policy', '/terms-and-conditions', '/app-license'] as $url) {
            $this->get($url)->assertOk()->assertSee('Version '.config('legal.version'));
        }
        $this->actingAs(User::factory()->create())
            ->get('/privacy-policy')->assertOk()->assertSee('Back to Settings');
    }

    public function test_public_entry_points_link_to_all_documents_and_registration_is_unchecked(): void
    {
        foreach (['/', '/login', '/admin/login', '/register'] as $url) {
            $response = $this->get($url)->assertOk();
            foreach (['legal.privacy', 'legal.terms', 'legal.license'] as $route) {
                $response->assertSee(route($route), false);
            }
        }
        $this->get('/register')->assertSee('name="terms_accepted"', false)
            ->assertSee('name="privacy_acknowledged"', false)
            ->assertDontSee('required checked', false);
    }

    public function test_web_registration_rejects_missing_or_false_acknowledgments_without_creating_an_account(): void
    {
        foreach ([null, false] as $value) {
            $this->post('/register', $this->registration([
                'terms_accepted' => $value, 'privacy_acknowledged' => $value,
            ]))->assertSessionHasErrors(['terms_accepted', 'privacy_acknowledged']);
        }
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('responder_profiles', 0);
        $this->assertGuest();
    }

    public function test_web_registration_records_the_server_version_and_acceptance_time(): void
    {
        $this->travelTo(now()->startOfSecond());
        $this->post('/register', $this->registration([
            'terms_accepted_at' => '2000-01-01', 'legal_documents_version' => 'spoofed',
        ]))->assertRedirect(route('account.verify'));
        $user = User::where('email', 'policy-test@example.com')->firstOrFail();
        $this->assertSame(config('legal.version'), $user->legal_documents_version);
        $this->assertTrue($user->terms_accepted_at->equalTo(now()));
        $this->assertTrue($user->privacy_acknowledged_at->equalTo(now()));
    }

    public function test_stale_document_versions_are_rejected_in_web_and_api_registration(): void
    {
        $this->post('/register', $this->registration(['legal_version' => 'old-version']))
            ->assertSessionHasErrors('legal_version');
        $this->postJson('/api/v1/auth/register', $this->registration(['legal_version' => 'old-version']))
            ->assertUnprocessable()->assertJsonValidationErrors('legal_version');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_api_registration_cannot_bypass_acknowledgment(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registration([
            'terms_accepted' => false, 'privacy_acknowledged' => false,
        ]))->assertUnprocessable()->assertJsonValidationErrors(['terms_accepted', 'privacy_acknowledged']);
        $this->assertDatabaseCount('users', 0);
        $this->postJson('/api/v1/auth/register', $this->registration())->assertCreated();
        $user = User::where('email', 'policy-test@example.com')->firstOrFail();
        $this->assertSame(config('legal.version'), $user->legal_documents_version);
        $this->assertNotNull($user->terms_accepted_at);
        $this->assertNotNull($user->privacy_acknowledged_at);
    }

    public function test_existing_accounts_are_not_assigned_fabricated_acceptance_or_blocked_at_login(): void
    {
        $user = User::factory()->create(['password' => 'password123', 'role' => 'civilian', 'is_admin' => false]);
        $this->post('/login', ['identity' => $user->email, 'password' => 'password123'])->assertRedirect();
        $this->assertAuthenticatedAs($user);
        $this->assertNull($user->fresh()->terms_accepted_at);
        $this->assertNull($user->legal_documents_version);
        $this->get('/settings')->assertOk()->assertSee(route('legal.privacy'), false);
    }

    public function test_google_authorization_requires_acknowledgment_before_leaving_the_app(): void
    {
        config(['services.google.client_id' => 'test-id', 'services.google.client_secret' => 'test-secret',
            'services.google.redirect' => route('auth.google.callback')]);
        $this->get('/auth/google')->assertOk()->assertSee('Before Google sign-in');
        $this->post('/auth/google', [])->assertSessionHasErrors(['terms_accepted', 'privacy_acknowledged', 'legal_version']);
        $this->assertDatabaseCount('users', 0);
        $this->post('/auth/google', $this->registration())
            ->assertRedirect()->assertSessionHas('google_legal_acknowledgment');
    }

    public function test_new_google_account_cannot_be_created_without_a_current_recent_acknowledgment(): void
    {
        config(['services.google.client_id' => 'test-id', 'services.google.client_secret' => 'test-secret',
            'services.google.redirect' => route('auth.google.callback')]);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'test-token']),
            'https://www.googleapis.com/oauth2/v3/userinfo' => Http::response([
                'sub' => 'test-google-user', 'email' => 'new-google@example.com', 'email_verified' => true,
            ]),
        ]);
        foreach ([null, ['version' => 'stale', 'recorded_at' => now()->timestamp],
            ['version' => config('legal.version'), 'recorded_at' => now()->subHour()->timestamp]] as $acknowledgment) {
            $this->withSession(['google_oauth_state' => 'valid-state', 'google_legal_acknowledgment' => $acknowledgment])
                ->get('/auth/google/callback?state=valid-state&code=test-code')->assertRedirect(route('auth.google.redirect'));
            $this->assertGuest();
            $this->assertDatabaseCount('users', 0);
        }
    }

    public function test_privacy_contact_is_configurable_and_missing_details_are_not_invented(): void
    {
        config(['legal.operator_name' => 'Test Operator', 'legal.contact_email' => null]);
        $this->get('/privacy-policy')->assertOk()->assertSee('Test Operator')
            ->assertSee('A dedicated privacy contact has not yet been published.');
        config(['legal.contact_email' => 'privacy@example.com']);
        $this->get('/privacy-policy')->assertOk()->assertSee('mailto:privacy@example.com', false)
            ->assertDontSee('A dedicated privacy contact has not yet been published.');
    }
}
