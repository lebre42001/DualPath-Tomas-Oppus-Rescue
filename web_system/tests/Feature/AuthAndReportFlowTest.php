<?php

namespace Tests\Feature;

use App\Events\IncidentFeedUpdated;
use App\Models\IncidentReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AuthAndReportFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Existing workflow coverage exercises the opt-in advisory integration.
        // MANUAL_ONLY has separate feature tests and remains the runtime default.
        config(['services.ai_text.mode' => 'MODEL_ADVISORY']);
    }

    public function test_public_register_screen_is_civilian_only_and_installable(): void
    {
        $this->get('/register')
            ->assertOk()
            ->assertSee('name="password"', false)
            ->assertSee('name="password_confirmation"', false)
            ->assertSee('data-auth-toggle-target="password"', false)
            ->assertSee('data-auth-toggle-target="password_confirmation"', false)
            ->assertSee('auth-password.js?v=', false)
            ->assertSee('Min. 6 characters required')
            ->assertSee('Bontoc Rescue')
            ->assertSee('Civilian Account Registration')
            ->assertSee('This public registration creates a civilian sender account only.')
            ->assertSee('Install App')
            ->assertDontSee('name="role"', false)
            ->assertDontSee('Monitor live reports')
            ->assertSee('AI-Powered LoRa Emergency Response and Severity Detection System with Intelligent Location Monitoring');
    }

    public function test_login_and_welcome_pages_show_updated_system_branding(): void
    {
        $branding = 'AI-Powered LoRa Emergency Response and Severity Detection System with Intelligent Location Monitoring';
        $shortBrand = 'Bontoc Rescue';

        $this->get('/')
            ->assertOk()
            ->assertSee($shortBrand)
            ->assertSee($branding)
            ->assertSee('Civilian Sender')
            ->assertSee('Install App')
            ->assertSee('manifest.webmanifest?v=', false)
            ->assertSee('pwa-helper.js?v=', false);

        $this->get('/login')
            ->assertOk()
            ->assertSee($shortBrand)
            ->assertSee($branding)
            ->assertSee('data-auth-toggle-target="password"', false)
            ->assertSee('Minimum of 6 characters.')
            ->assertSee('auth-password.js?v=', false)
            ->assertSee('Register Civilian Account')
            ->assertSee('Install App')
            ->assertSee('manifest.webmanifest?v=', false)
            ->assertSee('pwa-helper.js?v=', false)
            ->assertSee('New civilian reporter? Register first to create your emergency reporting account.')
            ->assertSee('Sign in with Google account')
            ->assertSee(route('auth.google.redirect'), false)
            ->assertDontSee('admin receiver portal')
            ->assertDontSee(route('admin.login'), false);

        $this->get('/responder/login')
            ->assertOk()
            ->assertSee('Admin receiver access')
            ->assertSee('Open Admin Receiver Portal')
            ->assertSee('Only pre-authorized admin accounts can continue here.')
            ->assertDontSee('pwa-helper.js?v=', false)
            ->assertDontSee('Sign in with Google account')
            ->assertDontSee(route('auth.google.redirect'), false)
            ->assertDontSee('Register Civilian Account');
    }

    public function test_civilian_google_sign_in_redirects_to_google_authorization(): void
    {
        config([
            'services.google.client_id' => 'google-client-id',
            'services.google.client_secret' => 'google-client-secret',
            'services.google.redirect' => route('auth.google.callback'),
        ]);

        $response = $this->get(route('auth.google.redirect'))
            ->assertRedirect()
            ->assertSessionHas('google_oauth_state');

        $location = $response->headers->get('Location');
        $this->assertIsString($location);
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $location);

        parse_str(parse_url($location, PHP_URL_QUERY) ?: '', $query);

        $this->assertSame('google-client-id', $query['client_id'] ?? null);
        $this->assertSame(route('auth.google.callback'), $query['redirect_uri'] ?? null);
        $this->assertSame('code', $query['response_type'] ?? null);
        $this->assertSame('openid email profile', $query['scope'] ?? null);
        $this->assertSame('select_account', $query['prompt'] ?? null);
        $this->assertNotEmpty($query['state'] ?? null);
    }

    public function test_civilian_google_callback_creates_civilian_account_and_requires_facial_verification(): void
    {
        config([
            'services.google.client_id' => 'google-client-id',
            'services.google.client_secret' => 'google-client-secret',
            'services.google.redirect' => route('auth.google.callback'),
        ]);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'google-access-token',
                'token_type' => 'Bearer',
            ]),
            'https://www.googleapis.com/oauth2/v3/userinfo' => Http::response([
                'sub' => 'google-user-123',
                'email' => 'google.civilian@example.com',
                'email_verified' => true,
                'name' => 'Google Civilian',
                'picture' => 'https://lh3.googleusercontent.com/google-civilian.jpg',
            ]),
        ]);

        $this->withSession(['google_oauth_state' => 'valid-state'])
            ->get(route('auth.google.callback', [
                'state' => 'valid-state',
                'code' => 'valid-code',
            ]))
            ->assertRedirect(route('account.verify'));

        $this->assertAuthenticated();

        $user = User::query()->where('email', 'google.civilian@example.com')->firstOrFail();

        $this->assertSame('civilian', $user->role);
        $this->assertFalse((bool) $user->is_admin);
        $this->assertSame('google-user-123', $user->google_id);
        $this->assertSame('https://lh3.googleusercontent.com/google-civilian.jpg', $user->google_avatar_url);
        $this->assertNotNull($user->email_verified_at);
        $this->assertNotNull($user->google_linked_at);
        $this->assertNull($user->account_verified_at);
        $this->assertNotNull($user->responderProfile);
        $this->assertSame('Civilian Mobile', $user->responderProfile->assigned_station);

        Http::assertSentCount(2);
    }

    public function test_login_form_uses_https_when_served_behind_render_proxy(): void
    {
        $this
            ->withServerVariables([
                'HTTP_X_FORWARDED_PROTO' => 'https',
                'HTTP_X_FORWARDED_HOST' => 'stitch-web-demo.onrender.com',
                'HTTP_X_FORWARDED_PORT' => '443',
            ])
            ->get('http://stitch-web-demo.onrender.com/login')
            ->assertOk()
            ->assertSee('action="https://stitch-web-demo.onrender.com/login"', false)
            ->assertDontSee('action="http://stitch-web-demo.onrender.com/login"', false);
    }

    public function test_system_version_endpoint_returns_no_store_json_for_auto_updates(): void
    {
        $response = $this->get(route('system.version'))
            ->assertOk()
            ->assertJsonStructure(['version', 'generated_at']);

        $cacheControl = (string) $response->headers->get('Cache-Control');

        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('no-cache', $cacheControl);
        $this->assertStringContainsString('must-revalidate', $cacheControl);
        $this->assertStringContainsString('max-age=0', $cacheControl);
    }

    public function test_mobile_api_registration_accepts_civilian_role_selection(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Civilian Reporter',
            'phone' => '09171234567',
            'email' => 'civilian@example.com',
            'password' => 'abc123',
            'password_confirmation' => 'abc123',
            'role' => 'civilian',
        ])
            ->assertCreated()
            ->assertJsonPath('user.role', 'civilian')
            ->assertJsonPath('user.account_verified', false)
            ->assertJsonMissingPath('user.station')
            ->assertJsonMissingPath('user.connectivity_mode');

        $user = User::query()->where('email', 'civilian@example.com')->firstOrFail();

        $this->assertSame('civilian', $user->role);
        $this->assertNull($user->account_verified_at);
    }

    public function test_web_registration_accepts_civilian_role_selection(): void
    {
        $this->post('/register', [
            'name' => 'Civilian Reporter',
            'phone' => '09179990000',
            'email' => 'civilian.web@example.com',
            'password' => 'abc123',
            'password_confirmation' => 'abc123',
            'role' => 'civilian',
        ])->assertRedirect(route('account.verify'));

        $user = User::query()->where('email', 'civilian.web@example.com')->firstOrFail();

        $this->assertAuthenticatedAs($user);
        $this->assertSame('civilian', $user->role);
        $this->assertNull($user->account_verified_at);
        $this->assertSame('Civilian Mobile', $user->responderProfile?->assigned_station);
    }

    public function test_public_registration_cannot_create_a_responder_account(): void
    {
        $this->post('/register', [
            'name' => 'Spoofed Responder',
            'phone' => '09170000000',
            'email' => 'spoofed.responder@example.com',
            'password' => 'abc123',
            'password_confirmation' => 'abc123',
            'role' => 'responder',
        ])->assertRedirect(route('account.verify'));

        $user = User::query()->where('email', 'spoofed.responder@example.com')->firstOrFail();

        $this->assertTrue($user->isCivilian());
        $this->assertFalse($user->isResponder());
        $this->assertFalse((bool) $user->is_admin);
    }

    public function test_civilian_and_admin_logins_use_separate_portals(): void
    {
        $civilian = User::factory()->create([
            'email' => 'client.portal@example.com',
            'password' => 'ClientPass123!',
            'role' => 'civilian',
        ]);
        $responder = User::factory()->create([
            'email' => 'responder.portal@example.com',
            'password' => 'ResponderPass123!',
            'role' => 'admin',
            'is_admin' => true,
        ]);

        $this->post('/login', [
            'identity' => $responder->email,
            'password' => 'ResponderPass123!',
        ])->assertInvalid(['identity']);
        $this->assertGuest();

        $this->post('/admin/login', [
            'identity' => $civilian->email,
            'password' => 'ClientPass123!',
        ])->assertInvalid(['identity']);
        $this->assertGuest();

        $this->post('/admin/login', [
            'identity' => $responder->email,
            'password' => 'ResponderPass123!',
        ])->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($responder);
    }

    public function test_civilian_dashboard_uses_a_reporting_focused_design(): void
    {
        $civilian = User::factory()->create([
            'name' => 'Civilian Reporter',
            'role' => 'civilian',
        ]);

        $latestReports = collect(['INC-CIV-DASH-NEW-1', 'INC-CIV-DASH-NEW-2', 'INC-CIV-DASH-NEW-3'])
            ->map(function (string $code, int $index) use ($civilian): IncidentReport {
                return IncidentReport::query()->create([
                    'report_code' => $code,
                    'reported_by' => $civilian->id,
                    'reporter_name' => $civilian->name,
                    'incident_type' => 'Road Obstruction',
                    'severity' => 'Minor',
                    'status' => 'received',
                    'channel' => 'Internet',
                    'transmission_type' => 'online',
                    'location_text' => 'Bontoc Main Road',
                    'description' => 'Tree branch blocking one lane.',
                    'transmitted_at' => now()->subMinutes($index + 1),
                    'status_updated_at' => now()->subMinutes($index + 1),
                    'created_at' => now()->subMinutes($index + 1),
                    'updated_at' => now()->subMinutes($index + 1),
                ]);
            });

        $olderReport = IncidentReport::query()->create([
            'report_code' => 'INC-CIV-DASH-OLD',
            'reported_by' => $civilian->id,
            'reporter_name' => $civilian->name,
            'incident_type' => 'Old Report',
            'severity' => 'Minor',
            'status' => 'resolved',
            'channel' => 'Internet',
            'transmission_type' => 'online',
            'location_text' => 'Old location',
            'description' => 'Older report should remain in history only.',
            'transmitted_at' => now()->subMinutes(30),
            'status_updated_at' => now()->subMinutes(30),
            'created_at' => now()->subMinutes(30),
            'updated_at' => now()->subMinutes(30),
        ]);

        $response = $this->actingAs($civilian)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Civilian Home')
            ->assertSee('Report or check status.')
            ->assertSee('What do you need?')
            ->assertSee('Report Emergency')
            ->assertSee('My Reports')
            ->assertSee('Check Status')
            ->assertSee('Latest Status')
            ->assertSee('Your latest reports')
            ->assertSee('Open History')
            ->assertSee('data-civilian-report-modal', false)
            ->assertSee('data-civilian-report-modal-trigger', false)
            ->assertDontSee(route('reports.show', $latestReports->first()), false)
            ->assertDontSee($olderReport->report_code)
            ->assertDontSee('Admin Receiver Deck')
            ->assertDontSee('Open Full Incident Feed');

        $latestReports->each(fn (IncidentReport $report) => $response->assertSee($report->report_code));
    }

    public function test_unverified_civilian_must_verify_account_before_sending_report(): void
    {
        $civilian = User::factory()->create([
            'name' => 'Unverified Civilian',
            'role' => 'civilian',
            'account_verified_at' => null,
            'account_verification_selfie_path' => null,
            'account_verification_method' => null,
        ]);

        $this->actingAs($civilian)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Verify Account')
            ->assertSee('Account Verification Required')
            ->assertDontSee('Send My First Report');

        $this->actingAs($civilian)
            ->get(route('reports.create'))
            ->assertRedirect(route('account.verify'));

        $this->actingAs($civilian)
            ->from(route('reports.create'))
            ->post(route('reports.store'), [
                'description' => 'Trying to send before verification.',
                'location_text' => 'Bontoc',
                'latitude' => '10.354270',
                'longitude' => '124.970400',
            ])
            ->assertRedirect(route('account.verify'));
    }

    public function test_civilian_can_verify_account_with_live_face_selfie_and_unlock_reporting(): void
    {
        Storage::fake();

        $civilian = User::factory()->create([
            'name' => 'Face Verify Civilian',
            'role' => 'civilian',
            'account_verified_at' => null,
            'account_verification_selfie_path' => null,
            'account_verification_method' => null,
        ]);

        $this->actingAs($civilian)
            ->get(route('account.verify'))
            ->assertOk()
            ->assertSee('Live facial verification required.')
            ->assertSee('data-account-verification-root', false)
            ->assertSee('data-account-verification-preview', false)
            ->assertSee('Capture Face')
            ->assertSee('Verify Account');

        $this->actingAs($civilian)
            ->post(route('account.verify.store'), [
                'verification_selfie' => UploadedFile::fake()->image('face-verification.jpg'),
            ])
            ->assertRedirect(route('dashboard'));

        $civilian->refresh();

        $this->assertNotNull($civilian->account_verified_at);
        $this->assertSame('live_front_camera_selfie', $civilian->account_verification_method);
        $this->assertNotNull($civilian->account_verification_selfie_path);
        Storage::assertExists($civilian->account_verification_selfie_path);

        $this->actingAs($civilian)
            ->get(route('reports.create'))
            ->assertOk()
            ->assertSee('Emergency Form')
            ->assertSee('Send Report');
    }

    public function test_civilian_report_history_only_shows_own_reports_and_hides_responder_controls(): void
    {
        Storage::fake();

        $civilian = User::factory()->create([
            'role' => 'civilian',
            'name' => 'Civilian History User',
        ]);
        $responder = User::factory()->create([
            'role' => 'responder',
            'name' => 'Responder Owner',
        ]);
        $evidencePath = UploadedFile::fake()->image('civilian-history-evidence.jpg')->store('incident-evidence');

        $ownReport = IncidentReport::query()->create([
            'report_code' => 'INC-CIV-HISTORY-1',
            'reported_by' => $civilian->id,
            'reporter_name' => $civilian->name,
            'incident_type' => 'Vehicle Skid',
            'severity' => 'Serious',
            'status' => 'received',
            'channel' => 'Internet',
            'transmission_type' => 'online',
            'location_text' => 'Mountain Curve',
            'description' => 'Vehicle lost control on wet pavement.',
            'evidence_type' => 'photo',
            'evidence_path' => $evidencePath,
            'evidence_original_name' => 'civilian-history-evidence.jpg',
            'transmitted_at' => now(),
            'status_updated_at' => now(),
        ]);

        $otherReport = IncidentReport::query()->create([
            'report_code' => 'INC-OTHER-HISTORY-2',
            'reported_by' => $responder->id,
            'reporter_name' => $responder->name,
            'incident_type' => 'Flood Alert',
            'severity' => 'Serious',
            'status' => 'received',
            'channel' => 'Internet',
            'transmission_type' => 'online',
            'location_text' => 'Riverbank',
            'description' => 'Water level rising quickly.',
            'transmitted_at' => now(),
            'status_updated_at' => now(),
        ]);

        $this->actingAs($civilian)
            ->get(route('reports.index'))
            ->assertOk()
            ->assertSee('My Submitted Reports')
            ->assertSee('Recent status updates')
            ->assertSee($ownReport->report_code)
            ->assertDontSee('Total reports')
            ->assertDontSee('Active queue')
            ->assertDontSee('Transport mix')
            ->assertDontSee($otherReport->report_code)
            ->assertSee('INC / ID')
            ->assertSee('Type of Accident')
            ->assertSee('Status')
            ->assertSee('Date')
            ->assertSee('GPS')
            ->assertSee('civilian-history-table', false)
            ->assertSee('civilian-history-table-row', false)
            ->assertSee('Report Details')
            ->assertSee('Submitted evidence')
            ->assertSee('Click to enlarge evidence photo')
            ->assertSee('civilian-history-evidence.jpg')
            ->assertSee(route('reports.evidence', $ownReport), false)
            ->assertSee('<img', false)
            ->assertSee('data-media-viewer-trigger', false)
            ->assertSee('data-civilian-report-row', false)
            ->assertSee('data-civilian-report-modal-trigger', false)
            ->assertSee('data-civilian-report-modal', false)
            ->assertDontSee('AI Severity')
            ->assertDontSee('Delete Report')
            ->assertDontSee('Save Assign')
            ->assertDontSee('>Respond<', false)
            ->assertDontSee('Set Done')
            ->assertDontSee('>Cancel<', false);

        $this->actingAs($civilian)
            ->get(route('reports.show', $otherReport))
            ->assertNotFound();

        $this->actingAs($civilian)
            ->get(route('reports.show', $ownReport))
            ->assertOk()
            ->assertSee('Submitted evidence')
            ->assertSee('Click to enlarge evidence photo')
            ->assertSee('civilian-history-evidence.jpg')
            ->assertSee(route('reports.evidence', $ownReport), false)
            ->assertSee('<img', false)
            ->assertSee('data-media-viewer-trigger', false);
    }

    public function test_civilian_report_history_uses_five_report_pages(): void
    {
        $civilian = User::factory()->create([
            'role' => 'civilian',
            'name' => 'Paged History User',
        ]);

        foreach (range(1, 6) as $index) {
            $report = IncidentReport::query()->create([
                'report_code' => "INC-CIV-PAGED-{$index}",
                'reported_by' => $civilian->id,
                'reporter_name' => $civilian->name,
                'incident_type' => "Road Accident {$index}",
                'severity' => 'Minor',
                'status' => $index === 1 ? 'resolved' : 'received',
                'channel' => 'Internet',
                'transmission_type' => 'online',
                'location_text' => "GPS area {$index}",
                'description' => "History pagination report {$index}.",
                'latitude' => 10.305 + $index,
                'longitude' => 124.980 + $index,
                'transmitted_at' => now()->subMinutes(6 - $index),
                'status_updated_at' => now()->subMinutes(6 - $index),
            ]);

            $timestamp = now()->subMinutes(6 - $index);
            IncidentReport::query()
                ->whereKey($report->getKey())
                ->update([
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);
        }

        $response = $this->actingAs($civilian)
            ->get(route('reports.index'))
            ->assertOk()
            ->assertSee('Viewing 1-5 of 6 reports')
            ->assertSee('Page 1 / 2')
            ->assertSee('INC-CIV-PAGED-6')
            ->assertSee('INC-CIV-PAGED-2')
            ->assertDontSee('INC-CIV-PAGED-1');

        $this->assertSame(
            2,
            substr_count($response->getContent(), 'class="detail-card civilian-status-update-card"')
        );
    }

    public function test_civilian_report_history_empty_state_is_polished_and_actionable(): void
    {
        $civilian = User::factory()->create([
            'role' => 'civilian',
            'name' => 'Empty History User',
        ]);

        $this->actingAs($civilian)
            ->get(route('reports.index'))
            ->assertOk()
            ->assertSee('No Report History Yet')
            ->assertSee('Recent status updates')
            ->assertSee('Status notifications will appear here after you submit your first report.')
            ->assertSee('Send New Report')
            ->assertSee('What you will see here')
            ->assertDontSee('No reports matched the current filters.');
    }

    public function test_civilian_profile_uses_civilian_profile_labels_without_history_preview(): void
    {
        $civilian = User::factory()->create([
            'name' => 'Civilian Profile User',
            'email' => 'civilian.profile@example.com',
            'role' => 'civilian',
        ]);
        $civilian->responderProfile()->create([
            'phone' => '09175557777',
            'assigned_station' => 'Civilian Mobile',
            'connectivity_mode' => 'auto_select',
            'notification_profile' => 'critical_alerts',
        ]);

        IncidentReport::query()->create([
            'report_code' => 'INC-CIV-PROFILE-1',
            'reported_by' => $civilian->id,
            'reporter_name' => $civilian->name,
            'incident_type' => 'Road Emergency',
            'severity' => 'Fatal',
            'status' => 'received',
            'channel' => 'Internet',
            'transmission_type' => 'online',
            'location_text' => 'Bontoc National Road',
            'description' => 'A report used to verify status-only profile rendering.',
            'transmitted_at' => now(),
            'status_updated_at' => now(),
        ]);

        $this->actingAs($civilian)
            ->get(route('profile.show'))
            ->assertOk()
            ->assertSee('Civilian Profile')
            ->assertSee('Update Profile')
            ->assertSee('Change profile picture')
            ->assertSee('Auto-crop and resize')
            ->assertSee('Auto-crop target: 512 x 512 pixels.')
            ->assertSee('Capture from camera')
            ->assertSee('Change password')
            ->assertSee('data-profile-photo-input', false)
            ->assertSee('data-profile-photo-crop-status', false)
            ->assertSee('data-profile-photo-camera-input', false)
            ->assertSee('Reset preview')
            ->assertSee('Show passwords')
            ->assertSee('civilian.profile@example.com')
            ->assertDontSee('History of Report')
            ->assertDontSee('Report history preview')
            ->assertDontSee('INC-CIV-PROFILE-1')
            ->assertDontSee('Pending')
            ->assertDontSee('>Fatal<', false)
            ->assertSee('Civilian');
    }

    public function test_civilian_can_update_profile_information_and_profile_photo(): void
    {
        Storage::fake();

        $civilian = User::factory()->create([
            'name' => 'Original Civilian',
            'email' => 'original.civilian@example.com',
            'role' => 'civilian',
        ]);
        $civilian->responderProfile()->create([
            'phone' => '09170000001',
            'assigned_station' => 'Civilian Mobile',
            'emergency_contact_name' => 'Old Contact',
            'emergency_contact_phone' => '09170000002',
            'connectivity_mode' => 'auto_select',
            'notification_profile' => 'critical_alerts',
        ]);

        $photo = UploadedFile::fake()->image('civilian-avatar.jpg');

        $this->actingAs($civilian)
            ->put(route('profile.update'), [
                'name' => 'Updated Civilian',
                'email' => 'updated.civilian@example.com',
                'phone' => '09178889999',
                'assigned_station' => 'Civilian Mobile Unit A',
                'emergency_contact_name' => 'Jane Contact',
                'emergency_contact_phone' => '09175554444',
                'password' => 'UpdatedCivilianPass123!',
                'password_confirmation' => 'UpdatedCivilianPass123!',
                'profile_photo' => $photo,
            ])->assertRedirect(route('profile.show'));

        $civilian->refresh();

        $this->assertSame('Updated Civilian', $civilian->name);
        $this->assertSame('updated.civilian@example.com', $civilian->email);
        $this->assertNotNull($civilian->profile_photo_path);
        $this->assertTrue(Hash::check('UpdatedCivilianPass123!', $civilian->password));
        $this->assertSame('09178889999', $civilian->responderProfile?->phone);
        $this->assertSame('Civilian Mobile Unit A', $civilian->responderProfile?->assigned_station);
        $this->assertSame('Jane Contact', $civilian->responderProfile?->emergency_contact_name);
        $this->assertSame('09175554444', $civilian->responderProfile?->emergency_contact_phone);

        Storage::assertExists($civilian->profile_photo_path);

        $this->actingAs($civilian)
            ->get(route('profile.show'))
            ->assertOk()
            ->assertSee('Updated Civilian')
            ->assertSee('updated.civilian@example.com')
            ->assertSee('Civilian Mobile Unit A')
            ->assertSee('Auto-crop and resize')
            ->assertSee('Capture from camera')
            ->assertSee('Change password')
            ->assertSee('Remove current photo')
            ->assertSee('Show passwords')
            ->assertSee('Save Profile Changes');

        $this->actingAs($civilian)
            ->get(route('profile.photo'))
            ->assertOk();
    }

    public function test_civilian_can_remove_existing_profile_photo(): void
    {
        Storage::fake();

        $civilian = User::factory()->create([
            'name' => 'Photo Removal User',
            'email' => 'photo.removal@example.com',
            'role' => 'civilian',
        ]);
        $civilian->responderProfile()->create([
            'phone' => '09170000111',
            'assigned_station' => 'Civilian Mobile',
            'connectivity_mode' => 'auto_select',
            'notification_profile' => 'critical_alerts',
        ]);

        $existingPhoto = UploadedFile::fake()->image('existing-photo.jpg')->store('profile-photos');
        $civilian->forceFill(['profile_photo_path' => $existingPhoto])->save();

        $this->actingAs($civilian)
            ->put(route('profile.update'), [
                'name' => 'Photo Removal User',
                'email' => 'photo.removal@example.com',
                'phone' => '09170000111',
                'assigned_station' => 'Civilian Mobile',
                'remove_profile_photo' => '1',
            ])->assertRedirect(route('profile.show'));

        $civilian->refresh();

        $this->assertNull($civilian->profile_photo_path);
        Storage::assertMissing($existingPhoto);
    }

    public function test_civilian_create_report_page_uses_civilian_reporting_design(): void
    {
        $civilian = User::factory()->create([
            'role' => 'civilian',
        ]);

        $this->actingAs($civilian)
            ->get(route('reports.create'))
            ->assertOk()
            ->assertSee('Emergency Report')
            ->assertSee('Field actions')
            ->assertSee('Use these field actions.')
            ->assertSee('Type of incident')
            ->assertSee('Road Accident')
            ->assertSee('Available for this version.')
            ->assertSee('Fire Incident')
            ->assertSee('Flood / Landslide')
            ->assertSee('Medical Emergency')
            ->assertSee('Coming soon.')
            ->assertSee('value="Road Accident"', false)
            ->assertSee('Send Report')
            ->assertSee('Capture Photo')
            ->assertSee('Upload Photo')
            ->assertSee('Lock GPS')
            ->assertSee('Short Description')
            ->assertSee('Use Capture Photo for real scenes or Upload Photo for downloaded test images.')
            ->assertSee('Front-camera selfie verification')
            ->assertSee('Tap Send Report after a captured or uploaded scene photo, GPS or manual location, and a short description.')
            ->assertSee('data-report-draft-form', false)
            ->assertSee('data-capture-trigger="photo"', false)
            ->assertSee('data-capture-trigger="upload"', false)
            ->assertSee('data-capture-selfie-input', false)
            ->assertSee('data-selfie-camera-modal', false)
            ->assertSee('data-selfie-camera-preview', false)
            ->assertSee('data-selfie-camera-capture', false)
            ->assertDontSee('data-capture-trigger="selfie"', false)
            ->assertDontSee('Record Video')
            ->assertDontSee('data-capture-trigger="video"', false)
            ->assertSee('data-capture-trigger="gps"', false)
            ->assertSee('data-geo-fill-button', false)
            ->assertSee('data-geo-latitude', false)
            ->assertSee('data-geo-longitude', false)
            ->assertSee('data-gps-fallback-used', false)
            ->assertSee('data-gps-fallback-panel', false)
            ->assertSee('GPS fallback')
            ->assertSee('Android Chrome')
            ->assertDontSee('iOS GPS fallback')
            ->assertSee('Short description (required)')
            ->assertSee('When available, AI uses only this description to propose severity.')
            ->assertSee('Otherwise a responder assesses the report.')
            ->assertSee('Photos remain evidence for responder review.')
            ->assertDontSee('Manual Report Entry')
            ->assertDontSee('Offline draft queue')
            ->assertDontSee('Save Draft Now')
            ->assertDontSee('Restore Latest Draft');
    }

    public function test_responder_create_report_page_keeps_manual_dashboard_entry_design(): void
    {
        $responder = User::factory()->create([
            'role' => 'admin',
            'is_admin' => true,
        ]);

        $this->actingAs($responder)
            ->get(route('reports.create'))
            ->assertOk()
            ->assertSee('Manual Report Entry')
            ->assertSee('Create an incident from the web dashboard.')
            ->assertSee('Open Incident Form')
            ->assertSee('Check Device Readiness')
            ->assertSee('Submit Report')
            ->assertSee('Get Current Latitude and Longitude')
            ->assertDontSee('Civilian Reporting Flow');
    }

    public function test_civilian_web_report_submission_accepts_scene_media_and_verification_selfie(): void
    {
        Storage::fake();
        Event::fake([IncidentFeedUpdated::class]);
        Http::fake([
            '*' => Http::response([
                'accepted' => true,
                'severity' => 'serious',
                'confidence' => 0.91,
                'responder_review_required' => false,
                'probabilities' => [
                    'minor' => 0.04,
                    'serious' => 0.91,
                    'fatal' => 0.05,
                ],
                'photo_relevance_label' => 'related',
                'photo_relevance_confidence' => 0.99,
            ]),
        ]);

        $civilian = User::factory()->create([
            'role' => 'civilian',
            'name' => 'Camera First User',
        ]);

        $this->actingAs($civilian)
            ->from(route('reports.create'))
            ->post(route('reports.store'), [
                'incident_type' => 'Motorcycle Collision',
                'severity' => '',
                'transmission_type' => 'online',
                'location_text' => 'Barangay Poblacion, Bontoc',
                'latitude' => '10.354270',
                'longitude' => '124.970400',
                'description' => 'Motorcycle and tricycle collision near the main road.',
                'evidence' => UploadedFile::fake()->image('scene-photo.jpg'),
                'selfie' => UploadedFile::fake()->image('verification-selfie.jpg'),
            ])
            ->assertRedirect();

        $report = IncidentReport::query()->latest('id')->firstOrFail();

        $this->assertSame($civilian->id, $report->reported_by);
        $this->assertSame('Road Accident', $report->incident_type);
        $this->assertSame('photo', $report->evidence_type);
        $this->assertNotNull($report->evidence_path);
        $this->assertNotNull($report->reporter_selfie_path);
        $this->assertNotNull($report->reporter_selfie_captured_at);
        $this->assertSame('text_review', $report->ai_source);
        $this->assertSame('Needs review', $report->severity_display);
        $this->assertNull($report->ai_confidence);
        $this->assertNull($report->photo_relevance_label);
        $this->assertNull($report->photo_relevance_confidence);
        Http::assertNothingSent();
        Storage::assertExists($report->evidence_path);
        Storage::assertExists($report->reporter_selfie_path);
        Event::assertDispatched(IncidentFeedUpdated::class);
    }

    public function test_civilian_web_report_submission_accepts_ios_manual_location_fallback(): void
    {
        Storage::fake();
        Event::fake([IncidentFeedUpdated::class]);
        Http::fake([
            '*' => Http::response([
                'accepted' => true,
                'severity' => 'serious',
                'confidence' => 0.88,
                'responder_review_required' => false,
                'probabilities' => [
                    'minor' => 0.08,
                    'serious' => 0.88,
                    'fatal' => 0.04,
                ],
                'photo_relevance_label' => 'related',
                'photo_relevance_confidence' => 0.97,
            ]),
        ]);

        $civilian = User::factory()->create([
            'role' => 'civilian',
            'name' => 'IOS Fallback User',
        ]);

        $manualLocation = 'Poblacion near Bontoc Public Market';

        $this->actingAs($civilian)
            ->post(route('reports.store'), [
                'incident_type' => 'Road Emergency',
                'severity' => '',
                'transmission_type' => 'online',
                'gps_fallback_used' => '1',
                'location_text' => $manualLocation,
                'description' => 'Vehicle crash near the market entrance.',
                'evidence' => UploadedFile::fake()->image('ios-scene-photo.jpg'),
                'selfie' => UploadedFile::fake()->image('ios-verification-selfie.jpg'),
            ])
            ->assertRedirect();

        $report = IncidentReport::query()->latest('id')->firstOrFail();

        $this->assertSame($civilian->id, $report->reported_by);
        $this->assertSame($manualLocation, $report->location_text);
        $this->assertNull($report->latitude);
        $this->assertNull($report->longitude);
        $this->assertSame('photo', $report->evidence_type);
        $this->assertNotNull($report->evidence_path);
        $this->assertNotNull($report->reporter_selfie_path);
        Storage::assertExists($report->evidence_path);
        Storage::assertExists($report->reporter_selfie_path);
        Event::assertDispatched(IncidentFeedUpdated::class);
    }

    public function test_civilian_web_report_submission_rejects_obvious_dummy_photo_before_ai(): void
    {
        Storage::fake();
        Event::fake([IncidentFeedUpdated::class]);

        $civilian = User::factory()->create([
            'role' => 'civilian',
            'name' => 'Dummy Photo User',
        ]);

        $this->actingAs($civilian)
            ->from(route('reports.create'))
            ->post(route('reports.store'), [
                'incident_type' => 'Road Emergency',
                'severity' => '',
                'transmission_type' => 'online',
                'location_text' => 'Barangay Poblacion, Bontoc',
                'latitude' => '10.354270',
                'longitude' => '124.970400',
                'description' => 'Attempted report using a screenshot instead of a real scene photo.',
                'evidence' => UploadedFile::fake()->image('dashboard-screenshot.png', 576, 1280),
                'selfie' => UploadedFile::fake()->image('verification-selfie.jpg'),
            ])
            ->assertRedirect(route('reports.create'))
            ->assertSessionHasErrors(['evidence']);

        $this->assertDatabaseCount('incident_reports', 0);
        Event::assertNotDispatched(IncidentFeedUpdated::class);
    }

    public function test_civilian_web_report_submission_ignores_retired_image_gate_and_requires_text_review(): void
    {
        Storage::fake();
        Event::fake([IncidentFeedUpdated::class]);
        Http::fake([
            '*' => Http::response(['message' => 'AI service unavailable'], 503),
        ]);

        config()->set('services.ai_severity.enabled', true);
        config()->set('services.ai_severity.require_civilian_photo_gate', true);
        config()->set('services.ai_severity.url', 'http://127.0.0.1:8100');
        config()->set('services.ai_severity.retry_attempts', 1);

        $civilian = User::factory()->create([
            'role' => 'civilian',
            'name' => 'Strict Gate User',
        ]);

        $response = $this->actingAs($civilian)
            ->from(route('reports.create'))
            ->post(route('reports.store'), [
                'incident_type' => 'Road Emergency',
                'severity' => '',
                'transmission_type' => 'online',
                'location_text' => 'Barangay Poblacion, Bontoc',
                'latitude' => '10.354270',
                'longitude' => '124.970400',
                'description' => 'Vehicle crash near the highway.',
                'evidence' => UploadedFile::fake()->image('road-scene.jpg', 640, 480),
                'selfie' => UploadedFile::fake()->image('verification-selfie.jpg'),
            ]);

        $report = IncidentReport::query()->latest('id')->firstOrFail();

        $response->assertRedirect(route('reports.success', $report));
        $this->assertDatabaseCount('incident_reports', 1);
        $this->assertSame('Serious', $report->severity);
        $this->assertSame('text_review', $report->ai_source);
        $this->assertSame('needs_review', $report->ai_status);
        $this->assertSame('Needs review', $report->severity_display);
        $this->assertNull($report->ai_confidence);
        $this->assertNull($report->ai_error_message);
        Event::assertDispatched(IncidentFeedUpdated::class);
        $this->assertCount(1, Storage::allFiles('incident-selfies'));
        $this->assertCount(1, Storage::allFiles('incident-evidence'));
        Http::assertNothingSent();
    }

    public function test_civilian_report_submission_requires_photo_selfie_and_gps_only(): void
    {
        Storage::fake();

        $civilian = User::factory()->create([
            'role' => 'civilian',
            'name' => 'Locked Submission User',
        ]);

        $this->actingAs($civilian)
            ->from(route('reports.create'))
            ->post(route('reports.store'), [
                'incident_type' => 'Road Incident',
                'severity' => '',
                'transmission_type' => 'online',
                'location_text' => 'Barangay Poblacion, Bontoc',
                'description' => 'Blocked lane after collision.',
            ])
            ->assertRedirect(route('reports.create'))
            ->assertSessionHasErrors(['latitude', 'longitude', 'evidence', 'selfie']);
    }

    public function test_civilian_web_report_submission_requires_short_description(): void
    {
        Storage::fake();
        Event::fake([IncidentFeedUpdated::class]);
        Http::fake([
            '*' => Http::response([
                'accepted' => true,
                'severity' => 'minor',
                'confidence' => 0.74,
                'responder_review_required' => false,
                'probabilities' => [
                    'minor' => 0.74,
                    'serious' => 0.20,
                    'fatal' => 0.06,
                ],
                'photo_relevance_label' => 'related',
                'photo_relevance_confidence' => 0.91,
            ]),
        ]);

        $civilian = User::factory()->create([
            'role' => 'civilian',
            'name' => 'Required Description User',
        ]);

        $this->actingAs($civilian)
            ->from(route('reports.create'))
            ->post(route('reports.store'), [
                'incident_type' => 'Fire Incident',
                'severity' => '',
                'transmission_type' => 'online',
                'location_text' => 'Barangay Poblacion, Bontoc',
                'latitude' => '10.354270',
                'longitude' => '124.970400',
                'description' => '',
                'evidence' => UploadedFile::fake()->image('scene-photo.jpg'),
                'selfie' => UploadedFile::fake()->image('verification-selfie.jpg'),
            ])
            ->assertRedirect(route('reports.create'))
            ->assertSessionHasErrors(['description']);

        $this->assertDatabaseCount('incident_reports', 0);
        Event::assertNotDispatched(IncidentFeedUpdated::class);
    }

    public function test_permission_readiness_page_checks_device_access_before_reporting(): void
    {
        $civilian = User::factory()->create([
            'role' => 'civilian',
            'name' => 'Readiness User',
        ]);

        $this->actingAs($civilian)
            ->get(route('settings.readiness'))
            ->assertOk()
            ->assertSee('Permission Readiness Check')
            ->assertSee('Run Full Check')
            ->assertSee('Camera access')
            ->assertSee('Location access')
            ->assertSee('Notifications')
            ->assertSee('Online and offline state')
            ->assertSee('App install mode')
            ->assertSee('Open Report Form')
            ->assertSee('data-permission-readiness-root', false)
            ->assertSee('data-readiness-action="camera"', false)
            ->assertSee('data-readiness-action="location"', false)
            ->assertSee('data-readiness-action="notifications"', false);

        $this->actingAs($civilian)
            ->get(route('settings.index'))
            ->assertOk()
            ->assertSee('Permission Readiness Check');
    }

    public function test_civilian_success_page_is_status_only_and_ai_page_is_blocked(): void
    {
        $civilian = User::factory()->create([
            'role' => 'civilian',
            'name' => 'Civilian Severity User',
        ]);

        $report = IncidentReport::query()->create([
            'report_code' => 'INC-CIV-FLOW-1',
            'reported_by' => $civilian->id,
            'reporter_name' => $civilian->name,
            'incident_type' => 'Fallen Tree',
            'severity' => 'Serious',
            'status' => 'received',
            'channel' => 'Internet',
            'transmission_type' => 'online',
            'location_text' => 'Mountain Slope',
            'description' => 'A tree fell and blocked the road after heavy rain.',
            'ai_summary' => 'AI triage marked this report as Serious.',
            'ai_confidence' => 84,
            'transmitted_at' => now(),
            'status_updated_at' => now(),
        ]);

        $this->actingAs($civilian)
            ->get(route('reports.success', $report))
            ->assertOk()
            ->assertSee('Emergency Report Sent')
            ->assertSee('Your emergency report was sent successfully.')
            ->assertSee('Open My Report')
            ->assertSee('Return to report history')
            ->assertDontSee('AI triage marked this report as Serious.')
            ->assertDontSee('Transmission')
            ->assertDontSee('Incident submitted successfully.');

        $this->actingAs($civilian)
            ->get(route('reports.severity', $report))
            ->assertRedirect(route('dashboard'));
    }

    public function test_ai_pages_show_friendly_fallback_note_without_raw_connection_error(): void
    {
        $civilian = User::factory()->create([
            'role' => 'civilian',
            'name' => 'Civilian AI Fallback User',
        ]);

        $report = IncidentReport::query()->create([
            'report_code' => 'INC-CIV-AI-FAIL-1',
            'reported_by' => $civilian->id,
            'reporter_name' => $civilian->name,
            'incident_type' => 'Vehicular Collision',
            'severity' => 'Minor',
            'status' => 'received',
            'channel' => 'Internet',
            'transmission_type' => 'online',
            'location_text' => 'Bontoc, Southern Leyte',
            'description' => 'A civilian submitted a report while the AI photo service was down.',
            'ai_summary' => 'AI triage marked this report as Minor based on limited immediate threat indicators in the submitted description.',
            'ai_confidence' => 72,
            'ai_source' => 'description_fallback',
            'ai_status' => 'fallback',
            'ai_model_name' => 'description_rules',
            'ai_model_version' => 'legacy',
            'ai_error_message' => 'cURL error 7: Failed to connect to 127.0.0.1 port 8100 after 2027 ms: Couldn\'t connect to server',
            'ai_processed_at' => now(),
            'transmitted_at' => now(),
            'status_updated_at' => now(),
        ]);

        $friendlyFallback = 'The AI image analysis service was temporarily unavailable, so the system used description-based severity fallback for this report.';

        $this->actingAs($civilian)
            ->get(route('reports.show', $report))
            ->assertOk()
            ->assertSee($report->report_code)
            ->assertSee('Report status')
            ->assertDontSee('Fallback note')
            ->assertDontSee($friendlyFallback)
            ->assertDontSee('Confidence 72%')
            ->assertDontSee('Historical description fallback')
            ->assertDontSee('cURL error 7');

        $this->actingAs($civilian)
            ->get(route('reports.severity', $report))
            ->assertRedirect(route('dashboard'));

        $responder = User::factory()->create([
            'role' => 'admin',
            'is_admin' => true,
        ]);

        $this->actingAs($responder)
            ->get(route('reports.show', $report))
            ->assertOk()
            ->assertSee('Fallback note')
            ->assertSee($friendlyFallback)
            ->assertSee('Confidence 72%')
            ->assertSee('Historical description fallback')
            ->assertSee('Fallback mode')
            ->assertDontSee('cURL error 7');

        $this->actingAs($responder)
            ->get(route('reports.severity', $report))
            ->assertOk()
            ->assertSee('AI quick read')
            ->assertSee('Fallback note')
            ->assertSee($friendlyFallback)
            ->assertSee('Confidence 72%')
            ->assertSee('Historical description fallback')
            ->assertSee('Fallback mode')
            ->assertDontSee('cURL error 7');
    }

    public function test_report_details_page_renders_clear_ai_summary_badges_for_responders(): void
    {
        $responder = User::factory()->create([
            'role' => 'admin',
            'is_admin' => true,
        ]);

        $report = IncidentReport::query()->create([
            'report_code' => 'INC-AI-BADGES-0001',
            'reported_by' => $responder->id,
            'reporter_name' => 'Civilian Reporter',
            'reporter_contact' => '09171230000',
            'incident_type' => 'Vehicular Collision',
            'severity' => 'Fatal',
            'status' => 'received',
            'channel' => 'Internet',
            'transmission_type' => 'online',
            'location_text' => 'San Vicente, Bontoc, Southern Leyte',
            'description' => 'Responder needs a clearer AI summary area for quick triage review.',
            'ai_summary' => 'AI image analysis predicted Fatal severity from the uploaded photo evidence.',
            'ai_confidence' => 100,
            'ai_source' => 'python_model',
            'ai_status' => 'complete',
            'ai_model_name' => 'bontoc_southern_leyte_production_candidate_external',
            'ai_model_version' => '0.2.0',
            'photo_relevance_label' => 'related',
            'photo_relevance_confidence' => 98,
            'ai_processed_at' => now(),
            'transmitted_at' => now(),
            'status_updated_at' => now(),
        ]);

        $this->actingAs($responder)
            ->get(route('reports.show', $report))
            ->assertOk()
            ->assertSee('Fatal output')
            ->assertSee('Confidence 100%')
            ->assertSee('Historical photo assessment')
            ->assertSee('Ready for triage')
            ->assertSee('Historical photo relevance: Related')
            ->assertSee('Saved relevance 98%')
            ->assertSee('AI model')
            ->assertSee('bontoc_southern_leyte_production_candidate_external v0.2.0');
    }

    public function test_civilian_cannot_open_transmission_operations(): void
    {
        $civilian = User::factory()->create([
            'role' => 'civilian',
            'name' => 'Civilian Transmission User',
        ]);

        $report = IncidentReport::query()->create([
            'report_code' => 'INC-CIV-TRANSMIT-1',
            'reported_by' => $civilian->id,
            'reporter_name' => $civilian->name,
            'incident_type' => 'Road Collision',
            'severity' => 'Serious',
            'status' => 'received',
            'channel' => 'Internet',
            'transmission_type' => 'online',
            'location_text' => 'Bontoc town proper',
            'latitude' => 17.089400,
            'longitude' => 120.977000,
            'description' => 'The vehicle collision happened near the public market.',
            'ai_summary' => 'AI triage marked this report as Serious.',
            'ai_confidence' => 84,
            'transmitted_at' => now(),
            'status_updated_at' => now(),
        ]);

        $this->actingAs($civilian)
            ->get(route('reports.transmissions', $report))
            ->assertRedirect(route('dashboard'));
    }

    public function test_civilian_cannot_delete_own_report_or_attached_files(): void
    {
        Storage::fake();

        $civilian = User::factory()->create([
            'role' => 'civilian',
            'name' => 'Deleting Civilian',
        ]);

        $evidencePath = UploadedFile::fake()->image('delete-photo.jpg')->store('incident-evidence');
        $selfiePath = UploadedFile::fake()->image('delete-selfie.jpg')->store('incident-selfies');

        $report = IncidentReport::query()->create([
            'report_code' => 'INC-CIV-DELETE-1',
            'reported_by' => $civilian->id,
            'reporter_name' => $civilian->name,
            'incident_type' => 'Minor Crash',
            'severity' => 'Minor',
            'status' => 'received',
            'channel' => 'Internet',
            'transmission_type' => 'online',
            'location_text' => 'Bontoc Crossing',
            'description' => 'Civilian wants to remove this test report.',
            'evidence_type' => 'photo',
            'evidence_path' => $evidencePath,
            'evidence_original_name' => 'delete-photo.jpg',
            'reporter_selfie_path' => $selfiePath,
            'reporter_selfie_original_name' => 'delete-selfie.jpg',
            'reporter_selfie_captured_at' => now(),
            'transmitted_at' => now(),
            'status_updated_at' => now(),
        ]);

        $this->actingAs($civilian)
            ->delete(route('reports.destroy', $report))
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseHas('incident_reports', ['id' => $report->id]);
        Storage::assertExists($evidencePath);
        Storage::assertExists($selfiePath);
    }

    public function test_civilian_cannot_delete_another_users_report(): void
    {
        $civilian = User::factory()->create([
            'role' => 'civilian',
        ]);
        $otherCivilian = User::factory()->create([
            'role' => 'civilian',
        ]);

        $report = IncidentReport::query()->create([
            'report_code' => 'INC-CIV-DELETE-2',
            'reported_by' => $otherCivilian->id,
            'reporter_name' => $otherCivilian->name,
            'incident_type' => 'Road Hazard',
            'severity' => 'Minor',
            'status' => 'received',
            'channel' => 'Internet',
            'transmission_type' => 'online',
            'location_text' => 'Town Proper',
            'description' => 'This report belongs to another civilian.',
            'transmitted_at' => now(),
            'status_updated_at' => now(),
        ]);

        $this->actingAs($civilian)
            ->delete(route('reports.destroy', $report))
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseHas('incident_reports', ['id' => $report->id]);
    }

    public function test_responder_can_delete_report_from_current_queue(): void
    {
        $responder = User::factory()->create([
            'role' => 'admin',
            'is_admin' => true,
        ]);

        $report = IncidentReport::query()->create([
            'report_code' => 'INC-RESP-DELETE-1',
            'reported_by' => $responder->id,
            'reporter_name' => 'Civilian Reporter',
            'incident_type' => 'Vehicular Collision',
            'severity' => 'Serious',
            'status' => 'received',
            'channel' => 'Internet',
            'transmission_type' => 'online',
            'location_text' => 'Main Highway',
            'description' => 'Responder queue report that will be deleted.',
            'transmitted_at' => now(),
            'status_updated_at' => now(),
        ]);

        $this->actingAs($responder)
            ->get(route('reports.show', $report))
            ->assertOk()
            ->assertSee('Delete Report');

        $this->actingAs($responder)
            ->delete(route('reports.destroy', $report))
            ->assertRedirect(route('reports.index'));

        $this->assertDatabaseMissing('incident_reports', ['id' => $report->id]);
    }

    public function test_responder_can_delete_all_reports_from_incident_feed(): void
    {
        Storage::fake();

        $responder = User::factory()->create([
            'role' => 'admin',
            'is_admin' => true,
        ]);

        $evidencePath = UploadedFile::fake()->image('bulk-delete-photo.jpg')->store('incident-evidence');
        $selfiePath = UploadedFile::fake()->image('bulk-delete-selfie.jpg')->store('incident-selfies');

        IncidentReport::query()->create([
            'report_code' => 'INC-RESP-BULK-1',
            'reported_by' => $responder->id,
            'reporter_name' => 'Civilian Reporter',
            'incident_type' => 'Vehicular Collision',
            'severity' => 'Serious',
            'status' => 'received',
            'channel' => 'Internet',
            'transmission_type' => 'online',
            'location_text' => 'Main Highway',
            'description' => 'Responder queue report with media that will be bulk deleted.',
            'evidence_type' => 'photo',
            'evidence_path' => $evidencePath,
            'evidence_original_name' => 'bulk-delete-photo.jpg',
            'reporter_selfie_path' => $selfiePath,
            'reporter_selfie_original_name' => 'bulk-delete-selfie.jpg',
            'reporter_selfie_captured_at' => now(),
            'transmitted_at' => now(),
            'status_updated_at' => now(),
        ]);

        IncidentReport::query()->create([
            'report_code' => 'INC-RESP-BULK-2',
            'reported_by' => $responder->id,
            'reporter_name' => 'Civilian Reporter',
            'incident_type' => 'Road Hazard',
            'severity' => 'Minor',
            'status' => 'received',
            'channel' => 'Internet',
            'transmission_type' => 'online',
            'location_text' => 'Town Proper',
            'description' => 'Second responder queue report that will be bulk deleted.',
            'transmitted_at' => now(),
            'status_updated_at' => now(),
        ]);

        $this->actingAs($responder)
            ->get(route('reports.index'))
            ->assertOk()
            ->assertSee('Delete All Reports');

        $this->actingAs($responder)
            ->delete(route('reports.destroy-all'))
            ->assertRedirect(route('reports.index'))
            ->assertSessionHas('status', '2 reports deleted successfully.');

        $this->assertDatabaseCount('incident_reports', 0);
        Storage::assertMissing($evidencePath);
        Storage::assertMissing($selfiePath);
    }

    public function test_civilian_cannot_delete_all_reports_from_incident_feed(): void
    {
        $civilian = User::factory()->create([
            'role' => 'civilian',
        ]);

        IncidentReport::query()->create([
            'report_code' => 'INC-CIV-BULK-BLOCKED',
            'reported_by' => $civilian->id,
            'reporter_name' => $civilian->name,
            'incident_type' => 'Road Hazard',
            'severity' => 'Minor',
            'status' => 'received',
            'channel' => 'Internet',
            'transmission_type' => 'online',
            'location_text' => 'Town Proper',
            'description' => 'Civilian should not be able to clear the incident feed.',
            'transmitted_at' => now(),
            'status_updated_at' => now(),
        ]);

        $this->actingAs($civilian)
            ->get(route('reports.index'))
            ->assertOk()
            ->assertDontSee('Delete All Reports');

        $this->actingAs($civilian)
            ->delete(route('reports.destroy-all'))
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseCount('incident_reports', 1);
    }

    public function test_responder_can_login_submit_a_report_and_open_operational_pages(): void
    {
        Event::fake([IncidentFeedUpdated::class]);

        $user = User::factory()->create([
            'name' => 'Christian Rescue',
            'email' => 'christian@example.com',
            'password' => 'securepass123',
            'role' => 'admin',
            'is_admin' => true,
        ]);

        $this->post(route('responder.login.store'), [
            'identity' => 'christian@example.com',
            'password' => 'securepass123',
        ])->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertSame('admin', $user->role);

        $this->post('/reports', [
            'incident_type' => 'Vehicular Collision',
            'severity' => 'Serious',
            'transmission_type' => 'online',
            'location_text' => 'Bontoc Public Market',
            'latitude' => '17.0894',
            'longitude' => '120.9770',
            'description' => 'Two vehicles collided near the market entrance with injuries reported.',
        ])->assertRedirect();

        $report = IncidentReport::query()->firstOrFail();

        $this->assertSame('received', $report->status);
        $this->assertSame('Serious', $report->severity);
        $this->assertSame('online', $report->transmission_type);
        $this->assertNotNull($report->transmitted_at);
        Event::assertDispatched(IncidentFeedUpdated::class);

        $this->get(route('reports.success', $report))
            ->assertOk()
            ->assertSee($report->report_code);

        $this->get(route('reports.show', $report))
            ->assertOk()
            ->assertSee($report->location_text)
            ->assertSee('Assign admin receiver and update status');

        $this->get(route('reports.severity', $report))
            ->assertOk()
            ->assertSee('AI Severity Analysis');

        $this->get(route('reports.transmissions', $report))
            ->assertOk()
            ->assertSee('Transmission Status');
    }

    public function test_report_details_page_renders_photo_evidence_preview_for_responders(): void
    {
        Storage::fake();

        $responder = User::factory()->create([
            'role' => 'admin',
            'is_admin' => true,
        ]);
        $photoPath = UploadedFile::fake()->image('scene-photo.jpg')->store('incident-evidence');
        $selfiePath = UploadedFile::fake()->image('verification-selfie.jpg')->store('incident-selfies');
        $report = IncidentReport::query()->create([
            'report_code' => 'INC-PHOTO-0001',
            'reported_by' => $responder->id,
            'reporter_name' => 'Civilian Reporter',
            'reporter_contact' => '09171234567',
            'incident_type' => 'Vehicular Collision',
            'severity' => 'Serious',
            'status' => 'received',
            'channel' => 'Internet',
            'transmission_type' => 'online',
            'location_text' => 'Bontoc Public Market',
            'description' => 'Responder review requires the uploaded scene image.',
            'evidence_type' => 'photo',
            'evidence_path' => $photoPath,
            'evidence_original_name' => 'scene-photo.jpg',
            'reporter_selfie_path' => $selfiePath,
            'reporter_selfie_original_name' => 'verification-selfie.jpg',
            'reporter_selfie_captured_at' => now(),
            'transmitted_at' => now(),
            'status_updated_at' => now(),
        ]);

        $this->actingAs($responder)
            ->get(route('reports.show', $report))
            ->assertOk()
            ->assertSee('<img', false)
            ->assertSee('data-media-viewer-trigger', false)
            ->assertSee('Click to enlarge photo')
            ->assertSee('scene-photo.jpg')
            ->assertSee('Download File')
            ->assertSee('Realtime selfie capture')
            ->assertSee('Click to enlarge verification selfie')
            ->assertSee('Download Selfie')
            ->assertSee(route('reports.evidence', $report), false);

        $this->actingAs($responder)
            ->get(route('reports.evidence', $report))
            ->assertOk();

        $this->actingAs($responder)
            ->get(route('reports.selfie', $report))
            ->assertOk();
    }

    public function test_report_details_page_renders_video_evidence_preview_for_responders(): void
    {
        Storage::fake();

        $responder = User::factory()->create([
            'role' => 'admin',
            'is_admin' => true,
        ]);
        $videoPath = UploadedFile::fake()->create('scene-video.mp4', 2048, 'video/mp4')->store('incident-evidence');
        $report = IncidentReport::query()->create([
            'report_code' => 'INC-VIDEO-0001',
            'reported_by' => $responder->id,
            'reporter_name' => 'Civilian Reporter',
            'reporter_contact' => '09171234567',
            'incident_type' => 'Road Crash',
            'severity' => 'Fatal',
            'status' => 'received',
            'channel' => 'Internet',
            'transmission_type' => 'online',
            'location_text' => 'Bontoc Highway',
            'description' => 'Responder review requires the uploaded scene video.',
            'evidence_type' => 'video',
            'evidence_path' => $videoPath,
            'evidence_original_name' => 'scene-video.mp4',
            'transmitted_at' => now(),
            'status_updated_at' => now(),
        ]);

        $this->actingAs($responder)
            ->get(route('reports.show', $report))
            ->assertOk()
            ->assertSee('<video', false)
            ->assertSee('data-media-viewer-trigger', false)
            ->assertSee('Click to enlarge video')
            ->assertSee('scene-video.mp4')
            ->assertSee('Download File')
            ->assertSee(route('reports.evidence', $report), false);

        $this->actingAs($responder)
            ->get(route('reports.evidence', ['incidentReport' => $report, 'download' => 1]))
            ->assertOk();
    }

    public function test_report_details_page_shows_distance_readable_location_and_google_maps_actions(): void
    {
        $responder = User::factory()->create([
            'role' => 'admin',
            'is_admin' => true,
        ]);

        $report = IncidentReport::query()->create([
            'report_code' => 'INC-LOCATION-0001',
            'reported_by' => $responder->id,
            'reporter_name' => 'Civilian Reporter',
            'reporter_contact' => '09171234567',
            'incident_type' => 'Vehicular Collision',
            'severity' => 'Serious',
            'status' => 'received',
            'channel' => 'Internet',
            'transmission_type' => 'online',
            'location_text' => 'Poblacion, Bontoc | GPS 17.08629, 120.97695',
            'latitude' => 17.086290,
            'longitude' => 120.976948,
            'description' => 'Responder location review needs readable address details and map routing actions.',
            'transmitted_at' => now(),
            'status_updated_at' => now(),
        ]);

        $this->actingAs($responder)
            ->get(route('reports.show', $report))
            ->assertOk()
            ->assertSee('Incident location')
            ->assertSee('Poblacion, Bontoc')
            ->assertSee('Barangay / town:')
            ->assertSee('Poblacion, Bontoc')
            ->assertSee('Command center route')
            ->assertSee('Travel time:')
            ->assertSee('Open in Google Maps')
            ->assertSee('Open Route Directions')
            ->assertSee('Open Command Center')
            ->assertSee('Red pin:')
            ->assertSee('Civilian sender location')
            ->assertSee('Bontoc Command Center')
            ->assertSee('Pending admin response')
            ->assertSee('data-latitude="17.08629"', false)
            ->assertSee('data-longitude="120.976948"', false)
            ->assertSee('map_role', false);
    }

    public function test_report_details_page_renders_live_route_and_turn_by_turn_guidance_when_router_is_available(): void
    {
        Http::fake([
            'https://router.project-osrm.org/route/v1/driving/*' => Http::response([
                'code' => 'Ok',
                'routes' => [[
                    'distance' => 12480.4,
                    'duration' => 1380.0,
                    'geometry' => [
                        'type' => 'LineString',
                        'coordinates' => [
                            [124.97039989612004, 10.354270414923162],
                            [124.9738, 10.3492],
                            [124.9811, 10.3416],
                            [124.9885, 10.3334],
                        ],
                    ],
                    'legs' => [[
                        'steps' => [
                            [
                                'distance' => 850.0,
                                'duration' => 120.0,
                                'name' => 'Bontoc Main Road',
                                'mode' => 'driving',
                                'maneuver' => [
                                    'type' => 'depart',
                                    'modifier' => 'straight',
                                ],
                            ],
                            [
                                'distance' => 6200.0,
                                'duration' => 720.0,
                                'name' => 'Southern Leyte National Road',
                                'mode' => 'driving',
                                'maneuver' => [
                                    'type' => 'turn',
                                    'modifier' => 'right',
                                ],
                            ],
                            [
                                'distance' => 0.0,
                                'duration' => 0.0,
                                'name' => '',
                                'mode' => 'driving',
                                'maneuver' => [
                                    'type' => 'arrive',
                                ],
                            ],
                        ],
                    ]],
                ]],
            ], 200),
        ]);

        config()->set('services.routing.enabled', true);
        config()->set('services.routing.url', 'https://router.project-osrm.org');
        config()->set('services.routing.profile', 'driving');
        config()->set('services.routing.provider', 'OSRM Public Routing');

        $responder = User::factory()->create([
            'role' => 'admin',
            'is_admin' => true,
        ]);

        $report = IncidentReport::query()->create([
            'report_code' => 'INC-ROUTE-LIVE-0001',
            'reported_by' => $responder->id,
            'reporter_name' => 'Civilian Reporter',
            'reporter_contact' => '09176667777',
            'incident_type' => 'Road Crash',
            'severity' => 'Serious',
            'status' => 'received',
            'channel' => 'Internet',
            'transmission_type' => 'online',
            'location_text' => 'Poblacion, Bontoc, Southern Leyte | GPS 10.3334, 124.9885',
            'latitude' => 10.3334,
            'longitude' => 124.9885,
            'description' => 'Responder needs a live road route and turn-by-turn dispatch guidance.',
            'transmitted_at' => now(),
            'status_updated_at' => now(),
        ]);

        $this->actingAs($responder)
            ->get(route('reports.show', $report))
            ->assertOk()
            ->assertSee('Command center route')
            ->assertSee('Live road route is ready from Bontoc Command Center to the civilian sender location.')
            ->assertSee('Travel time:')
            ->assertSee('data-route', false)
            ->assertSee('Open Route Directions');

        Http::assertSentCount(1);
    }

    public function test_report_feed_renders_inline_evidence_previews_for_responders(): void
    {
        Storage::fake();

        $responder = User::factory()->create([
            'role' => 'admin',
            'is_admin' => true,
        ]);
        $photoPath = UploadedFile::fake()->image('feed-photo.jpg')->store('incident-evidence');
        $videoPath = UploadedFile::fake()->create('feed-video.mp4', 2048, 'video/mp4')->store('incident-evidence');
        $selfiePath = UploadedFile::fake()->image('feed-selfie.jpg')->store('incident-selfies');

        IncidentReport::query()->create([
            'report_code' => 'INC-FEED-PHOTO',
            'reported_by' => $responder->id,
            'reporter_name' => 'Civilian Reporter',
            'incident_type' => 'Road Crash',
            'severity' => 'Serious',
            'status' => 'received',
            'channel' => 'Internet',
            'transmission_type' => 'online',
            'location_text' => 'Main Highway',
            'description' => 'Photo preview should appear in the incident feed.',
            'evidence_type' => 'photo',
            'evidence_path' => $photoPath,
            'evidence_original_name' => 'feed-photo.jpg',
            'reporter_selfie_path' => $selfiePath,
            'reporter_selfie_original_name' => 'feed-selfie.jpg',
            'reporter_selfie_captured_at' => now(),
            'transmitted_at' => now(),
            'status_updated_at' => now(),
        ]);

        IncidentReport::query()->create([
            'report_code' => 'INC-FEED-VIDEO',
            'reported_by' => $responder->id,
            'reporter_name' => 'Civilian Reporter',
            'incident_type' => 'Vehicular Accident',
            'severity' => 'Fatal',
            'status' => 'received',
            'channel' => 'Internet',
            'transmission_type' => 'online',
            'location_text' => 'Bridge Approach',
            'description' => 'Video preview should appear in the incident feed.',
            'evidence_type' => 'video',
            'evidence_path' => $videoPath,
            'evidence_original_name' => 'feed-video.mp4',
            'transmitted_at' => now(),
            'status_updated_at' => now(),
        ]);

        $this->actingAs($responder)
            ->get(route('reports.index'))
            ->assertOk()
            ->assertSee('Evidence')
            ->assertSee('Verification Selfie')
            ->assertDontSee('Total reports')
            ->assertDontSee('Active queue')
            ->assertDontSee('Transport mix')
            ->assertSee('<img', false)
            ->assertSee('<video', false)
            ->assertSee('data-media-viewer-trigger', false)
            ->assertSee('Click to enlarge photo')
            ->assertSee('Click to enlarge video')
            ->assertSee('Selfie Verified')
            ->assertSee('Click to enlarge selfie')
            ->assertSee('feed-selfie.jpg')
            ->assertSee('feed-photo.jpg')
            ->assertSee('feed-video.mp4');
    }

    public function test_report_feed_renders_ai_quick_read_badges_for_responders(): void
    {
        $responder = User::factory()->create([
            'role' => 'admin',
            'is_admin' => true,
        ]);

        IncidentReport::query()->create([
            'report_code' => 'INC-FEED-AI-0001',
            'reported_by' => $responder->id,
            'reporter_name' => 'Civilian Reporter',
            'incident_type' => 'Major Collision',
            'severity' => 'Fatal',
            'status' => 'received',
            'channel' => 'Internet',
            'transmission_type' => 'online',
            'location_text' => 'Bontoc Town Proper',
            'description' => 'Responder incident feed should show AI confidence and review state without opening details.',
            'ai_summary' => 'AI image analysis predicted Fatal severity from the uploaded photo evidence.',
            'ai_confidence' => 100,
            'ai_source' => 'python_model',
            'ai_status' => 'complete',
            'ai_model_name' => 'bontoc_southern_leyte_production_candidate_external',
            'ai_model_version' => '0.2.0',
            'photo_relevance_label' => 'related',
            'photo_relevance_confidence' => 98,
            'transmitted_at' => now(),
            'status_updated_at' => now(),
        ]);

        $this->actingAs($responder)
            ->get(route('reports.index'))
            ->assertOk()
            ->assertSee('AI quick read')
            ->assertSee('Fatal output')
            ->assertSee('Confidence 100%')
            ->assertSee('Historical photo assessment')
            ->assertSee('Ready for triage')
            ->assertSee('Historical photo relevance: Related')
            ->assertSee('Saved relevance 98%')
            ->assertSee('bontoc_southern_leyte_production_candidate_external v0.2.0');
    }

    public function test_report_feed_can_expand_review_and_update_status_without_assignment_controls(): void
    {
        $coordinator = User::factory()->create([
            'role' => 'admin',
            'is_admin' => true,
        ]);
        $assignee = User::factory()->create([
            'role' => 'admin',
            'is_admin' => true,
            'name' => 'Mountain Admin',
        ]);
        $report = IncidentReport::query()->create([
            'report_code' => 'INC-FEED-QUEUE-1',
            'reported_by' => $coordinator->id,
            'reporter_name' => 'Civilian Reporter',
            'incident_type' => 'Flooded Road',
            'severity' => 'Serious',
            'status' => 'received',
            'channel' => 'Internet',
            'transmission_type' => 'online',
            'location_text' => 'Riverside Crossing',
            'latitude' => 10.160522,
            'longitude' => 124.999870,
            'description' => 'Quick queue actions should update assignment and status from the feed.',
            'transmitted_at' => now(),
            'status_updated_at' => now(),
        ]);
        $civilian = User::factory()->create([
            'role' => 'civilian',
            'name' => 'Civilian Dropdown User',
        ]);

        $this->actingAs($coordinator)
            ->get(route('reports.index'))
            ->assertOk()
            ->assertSee('Admin process')
            ->assertSee('Admin decision')
            ->assertSee('Respond')
            ->assertSee('Set Done')
            ->assertSee('Cancel')
            ->assertSee('Review')
            ->assertSee('Close')
            ->assertSee('incident-feed-row', false)
            ->assertSee('incident-feed-mini-map', false)
            ->assertSee('data-report-review-label', false)
            ->assertSee('data-report-row-close', false)
            ->assertSee('data-latitude="10.160522"', false)
            ->assertSee('data-longitude="124.99987"', false)
            ->assertDontSee('Split View')
            ->assertDontSee('List View')
            ->assertDontSee('Map View')
            ->assertDontSee('Save Assign')
            ->assertDontSee('Unassigned')
            ->assertDontSee($assignee->name.' - Admin')
            ->assertDontSee($civilian->name);

        $this->actingAs($coordinator)
            ->post(route('reports.coordination', $report), [
                'status' => 'responding',
                'response_notes' => 'Admin is responding to this incident report.',
            ])
            ->assertRedirect();

        $report->refresh();
        $this->assertNull($report->assigned_responder_id);
        $this->assertSame('responding', $report->status);

        $this->actingAs($coordinator)
            ->get(route('reports.show', $report))
            ->assertOk()
            ->assertSee('Current saved coordination status: Admin responded.')
            ->assertSee('Responded')
            ->assertDontSee('Admin responding');

        $this->actingAs($coordinator)
            ->post(route('reports.coordination', $report), [
                'status' => 'resolved',
            ])
            ->assertRedirect();

        $report->refresh();
        $this->assertNull($report->assigned_responder_id);
        $this->assertSame('resolved', $report->status);

        $this->actingAs($coordinator)
            ->get(route('reports.show', $report))
            ->assertOk()
            ->assertSee('Resolved')
            ->assertDontSee('Admin responding');

        $this->actingAs($coordinator)
            ->post(route('reports.coordination', $report), [
                'status' => 'rejected',
                'response_notes' => 'Admin cancelled this report after review.',
            ])
            ->assertRedirect();

        $report->refresh();
        $this->assertSame('rejected', $report->status);

        $this->actingAs($coordinator)
            ->post(route('reports.coordination', $report), [
                'status' => 'received',
            ])
            ->assertRedirect();

        $report->refresh();
        $this->assertNull($report->assigned_responder_id);
        $this->assertSame('received', $report->status);
    }

    public function test_report_can_be_assigned_and_status_updated_from_web_dashboard(): void
    {
        $coordinator = User::factory()->create([
            'role' => 'admin',
            'is_admin' => true,
        ]);
        $assignee = User::factory()->create([
            'role' => 'admin',
            'is_admin' => true,
            'name' => 'Field Admin',
        ]);
        $report = IncidentReport::query()->create([
            'report_code' => 'INC-TEST-0001',
            'reported_by' => $coordinator->id,
            'reporter_name' => 'Civilian Reporter',
            'incident_type' => 'Motorcycle Crash',
            'severity' => 'Serious',
            'status' => 'received',
            'channel' => 'Internet',
            'transmission_type' => 'online',
            'location_text' => 'Bontoc Highway',
            'description' => 'Crash near the highway shoulder.',
            'transmitted_at' => now(),
            'status_updated_at' => now(),
        ]);

        $this->actingAs($coordinator)
            ->post(route('reports.coordination', $report), [
                'assigned_responder_id' => $assignee->id,
                'status' => 'responding',
                'response_notes' => 'Unit dispatched and en route.',
            ])
            ->assertRedirect();

        $report->refresh();

        $this->assertSame($assignee->id, $report->assigned_responder_id);
        $this->assertSame('responding', $report->status);
        $this->assertSame('Unit dispatched and en route.', $report->response_notes);
        $this->assertNotNull($report->status_updated_at);
    }

    public function test_authenticated_user_can_save_settings_preferences(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/settings/preferences', [
                'critical_alerts' => '1',
                'push_notifications' => '1',
                'sms_backup' => '0',
                'connectivity_mode' => 'lora_fallback',
            ], [
                'Accept' => 'application/json',
                'X-Requested-With' => 'XMLHttpRequest',
            ])
            ->assertOk()
            ->assertJson([
                'saved' => true,
                'connectivity_mode' => 'lora_fallback',
            ]);

        $profile = $user->fresh()->responderProfile;

        $this->assertNotNull($profile);
        $this->assertSame('lora_fallback', $profile->connectivity_mode);
        $this->assertSame('critical_alerts,push_notifications', $profile->notification_profile);
    }

    public function test_database_seeder_creates_admin_responder_account(): void
    {
        config()->set([
            'admin.seed.email' => 'admin.responder@stitch.local',
            'admin.seed.password' => 'Test-Only-Admin-Password-2026!',
        ]);

        $this->seed();

        $admin = User::query()->where('email', 'admin.responder@stitch.local')->first();

        $this->assertNotNull($admin);
        $this->assertTrue((bool) $admin->is_admin);
        $this->assertSame('admin', $admin->role);
        $this->assertTrue(Hash::check('Test-Only-Admin-Password-2026!', $admin->password));
        $this->assertNotNull($admin->responderProfile);
        $this->assertSame('Bontoc Command Center', $admin->responderProfile->assigned_station);
    }

    public function test_database_seeder_skips_admin_without_explicit_credentials(): void
    {
        config()->set([
            'admin.seed.email' => null,
            'admin.seed.password' => null,
        ]);

        $this->seed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_admin_user_is_redirected_to_admin_dashboard_and_sees_admin_ui(): void
    {
        $admin = User::factory()->create([
            'name' => 'Admin Responder',
            'email' => 'admin.dashboard@example.com',
            'is_admin' => true,
            'role' => 'admin',
        ]);
        $admin->responderProfile()->create([
            'phone' => '09175550123',
            'assigned_station' => 'Bontoc Command Center',
            'connectivity_mode' => 'auto_select',
            'notification_profile' => 'critical_alerts,push_notifications,sms_backup',
        ]);
        User::factory()->create([
            'name' => 'Old Civilian Account',
            'role' => 'civilian',
            'created_at' => now()->subMinutes(4),
            'updated_at' => now()->subMinutes(4),
        ]);
        User::factory()->create([
            'name' => 'Recent Civilian One',
            'role' => 'civilian',
            'created_at' => now()->subMinutes(3),
            'updated_at' => now()->subMinutes(3),
        ]);
        User::factory()->create([
            'name' => 'Recent Civilian Two',
            'role' => 'civilian',
            'created_at' => now()->subMinutes(2),
            'updated_at' => now()->subMinutes(2),
        ]);
        User::factory()->create([
            'name' => 'Newest Civilian Three',
            'role' => 'civilian',
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);

        $this->actingAs($admin)
            ->get('/dashboard')
            ->assertRedirect(route('admin.dashboard'));

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Admin Responder')
            ->assertSee('Admin Response')
            ->assertSee('Overview')
            ->assertSee('Manage Accounts')
            ->assertSee('Civilian Registry')
            ->assertSee('Recent Civilian One')
            ->assertSee('Recent Civilian Two')
            ->assertSee('Newest Civilian Three')
            ->assertDontSee('Old Civilian Account')
            ->assertDontSee('Admin Receiver Workload')
            ->assertDontSee('Who is receiving work?')
            ->assertSee('Latest Incident')
            ->assertSee('Latest incidents')
            ->assertDontSee('Dispatch recommendation')
            ->assertDontSee('Open Priority Incident')
            ->assertSee('Incident Action History')
            ->assertSee('Latest incident actions')
            ->assertSee('Action')
            ->assertSee('Incident')
            ->assertSee('Actor')
            ->assertSee('Time')
            ->assertSee('Live Monitoring')
            ->assertDontSee('Map and feed monitoring')
            ->assertDontSee('Split View');
    }

    public function test_admin_can_open_separate_monitoring_page_distinct_from_admin_board(): void
    {
        $admin = User::factory()->create([
            'name' => 'Separate Admin',
            'email' => 'separate.admin@example.com',
            'is_admin' => true,
            'role' => 'admin',
        ]);
        $civilian = User::factory()->create([
            'name' => 'Civilian Reporter',
            'role' => 'civilian',
        ]);

        IncidentReport::query()->create([
            'report_code' => 'INC-ADMIN-MONITOR-1',
            'reported_by' => $civilian->id,
            'reporter_name' => $civilian->name,
            'incident_type' => 'Slope Collapse',
            'severity' => 'Serious',
            'status' => 'received',
            'channel' => 'Internet',
            'transmission_type' => 'online',
            'location_text' => 'Mountain road',
            'latitude' => 10.35001,
            'longitude' => 124.97002,
            'ai_summary' => 'AI analysis detected serious terrain collapse indicators.',
            'ai_confidence' => 88,
            'description' => 'Monitoring page should still show tactical incident content.',
            'transmitted_at' => now(),
            'status_updated_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(route('monitoring'))
            ->assertOk()
            ->assertSee('Dashboard')
            ->assertSee('Latest Incident')
            ->assertSee('Live incident monitoring')
            ->assertSee('ID')
            ->assertSee('Name')
            ->assertSee('Severity assessment')
            ->assertSee('Location')
            ->assertSee('Type')
            ->assertSee('Review')
            ->assertSee('Delete')
            ->assertSee('Open Map Pin')
            ->assertSee('Pending admin review')
            ->assertSee('data-admin-process-state="Pending admin review"', false)
            ->assertSee('data-latitude="10.35001"', false)
            ->assertSee('data-longitude="124.97002"', false)
            ->assertSee('INC-ADMIN-MONITOR-1')
            ->assertSee('Civilian Reporter')
            ->assertSee('AI analysis detected serious terrain collapse indicators.')
            ->assertSee('latest-inline-map')
            ->assertSee('Alert Queue')
            ->assertDontSee('Admin Receiver Deck')
            ->assertDontSee('Open Full Incident Feed')
            ->assertDontSee('Create Manual Report')
            ->assertDontSee('Enable Live Alerts')
            ->assertDontSee('Open Incident Feed')
            ->assertDontSee('Split View')
            ->assertDontSee('List View')
            ->assertDontSee('Map View')
            ->assertDontSee('LoRa Status')
            ->assertDontSee('Active incidents')
            ->assertDontSee('Assigned to you')
            ->assertDontSee('Admin Control Center')
            ->assertDontSee('Manage Civilian Accounts');
    }

    public function test_admin_board_renders_ai_quick_read_badges_in_moderation_cards(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'is_admin' => true,
        ]);

        IncidentReport::query()->create([
            'report_code' => 'INC-ADMIN-AI-0001',
            'reported_by' => $admin->id,
            'reporter_name' => 'Civilian Reporter',
            'incident_type' => 'Moderation Collision',
            'severity' => 'Fatal',
            'status' => 'received',
            'channel' => 'Internet',
            'transmission_type' => 'online',
            'location_text' => 'Bontoc Center',
            'description' => 'Admin moderation cards should surface AI confidence and review state.',
            'ai_summary' => 'AI image analysis predicted Fatal severity from the uploaded photo evidence.',
            'ai_confidence' => 100,
            'ai_source' => 'python_model',
            'ai_status' => 'complete',
            'ai_model_name' => 'bontoc_southern_leyte_production_candidate_external',
            'ai_model_version' => '0.2.0',
            'photo_relevance_label' => 'related',
            'photo_relevance_confidence' => 98,
            'transmitted_at' => now(),
            'status_updated_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Confidence 100%')
            ->assertSee('Historical photo assessment')
            ->assertSee('Ready for triage')
            ->assertSee('Historical photo relevance: Related')
            ->assertSee('Saved relevance 98%')
            ->assertSee('AI image analysis predicted Fatal severity');
    }

    public function test_responder_monitoring_page_renders_ai_quick_read_badges(): void
    {
        $responder = User::factory()->create([
            'role' => 'admin',
            'is_admin' => true,
        ]);
        $civilian = User::factory()->create([
            'role' => 'civilian',
            'name' => 'Civilian AI Sender',
        ]);

        IncidentReport::query()->create([
            'report_code' => 'INC-MON-AI-0001',
            'reported_by' => $civilian->id,
            'reporter_name' => $civilian->name,
            'incident_type' => 'Road Traffic Collision',
            'severity' => 'Fatal',
            'status' => 'received',
            'channel' => 'Internet',
            'transmission_type' => 'online',
            'location_text' => 'Poblacion, Bontoc, Southern Leyte',
            'description' => 'Monitoring view should show AI quick read without opening the full incident feed.',
            'ai_summary' => 'AI image analysis predicted Fatal severity from the uploaded photo evidence.',
            'ai_confidence' => 100,
            'ai_source' => 'python_model',
            'ai_status' => 'complete',
            'ai_model_name' => 'bontoc_southern_leyte_production_candidate_external',
            'ai_model_version' => '0.2.0',
            'photo_relevance_label' => 'related',
            'photo_relevance_confidence' => 98,
            'transmitted_at' => now(),
            'status_updated_at' => now(),
        ]);

        $this->actingAs($responder)
            ->get(route('monitoring'))
            ->assertOk()
            ->assertSee('Severity assessment')
            ->assertSee('Civilian AI Sender')
            ->assertSee('Road Traffic Collision')
            ->assertSee('Confidence 100%')
            ->assertSee('AI image analysis predicted Fatal severity from the uploaded photo evidence.')
            ->assertSee('Review')
            ->assertSee('Open Map Pin');
    }

    public function test_non_admin_user_cannot_open_admin_dashboard(): void
    {
        $user = User::factory()->create([
            'is_admin' => false,
        ]);

        $this->actingAs($user)
            ->get(route('admin.dashboard'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_responder_can_open_civilian_account_management_in_list_review_mode(): void
    {
        Storage::fake();

        $responder = User::factory()->create([
            'role' => 'admin',
            'is_admin' => true,
        ]);
        $verificationPath = UploadedFile::fake()
            ->image('account-face.jpg', 720, 960)
            ->store('account-verifications');
        $civilian = User::factory()->create([
            'name' => 'Civilian Account',
            'email' => 'civilian.support@example.com',
            'password' => 'OldCivilianPass123!',
            'role' => 'civilian',
            'account_verified_at' => now(),
            'account_verification_selfie_path' => $verificationPath,
            'account_verification_method' => 'live_front_camera_selfie',
        ]);
        $civilian->responderProfile()->create([
            'phone' => '09171239999',
            'assigned_station' => 'Civilian Mobile',
            'connectivity_mode' => 'auto_select',
            'notification_profile' => 'critical_alerts',
        ]);
        $civilian->forceFill([
            'last_login_at' => now()->subMinutes(15),
            'last_login_ip_address' => '192.168.1.77',
            'last_login_user_agent' => 'Mozilla/5.0 (Linux; Android 14; Mobile) AppleWebKit/537.36 Chrome/123.0 Mobile Safari/537.36',
        ])->save();
        User::factory()->create([
            'name' => 'Pending Verification',
            'email' => 'pending.verification@example.com',
            'role' => 'civilian',
            'account_verified_at' => null,
            'account_verification_selfie_path' => null,
        ]);

        $this->actingAs($responder)
            ->get(route('civilian-accounts.index'))
            ->assertOk()
            ->assertSee('Civilian Accounts')
            ->assertSee('Civilian directory')
            ->assertSee('Civilian Account')
            ->assertSee('Pending Verification')
            ->assertSee('View')
            ->assertSee('Verified')
            ->assertSee('Not Verified')
            ->assertSee('Offline')
            ->assertSee('This account is saved in the database and remains visible even without an active session.')
            ->assertSee('No verification image yet')
            ->assertDontSee('Filtered View')
            ->assertDontSee('Audit Today')
            ->assertDontSee('Save Account Update')
            ->assertDontSee('New Password')
            ->assertDontSee('Confirm New Password')
            ->assertSee('civilian.support@example.com')
            ->assertSee('Facial Verification Image')
            ->assertSee('Captured verification selfie')
            ->assertSee('Last Device Used')
            ->assertSee('Last IP Address')
            ->assertSee('Block Account')
            ->assertSee('Delete Account')
            ->assertSee(route('civilian-accounts.verification-image', $civilian), false)
            ->assertSee(route('civilian-accounts.block', $civilian), false)
            ->assertSee(route('civilian-accounts.destroy', $civilian), false);

        $this->actingAs($responder)
            ->get(route('civilian-accounts.verification-image', $civilian))
            ->assertOk();

        $this->actingAs($responder)
            ->put('/civilian-accounts/'.$civilian->id, [
                'name' => 'Updated Civilian Account',
                'email' => 'civilian.updated@example.com',
                'phone' => '09170000000',
                'password' => 'UpdatedCivilianPass123!',
                'password_confirmation' => 'UpdatedCivilianPass123!',
            ])
            ->assertStatus(405);

        $civilian->refresh();

        $this->assertSame('Civilian Account', $civilian->name);
        $this->assertSame('civilian.support@example.com', $civilian->email);
        $this->assertSame('09171239999', $civilian->responderProfile->phone);
        $this->assertTrue(Hash::check('OldCivilianPass123!', $civilian->password));
    }

    public function test_responder_can_block_and_unblock_civilian_account(): void
    {
        $responder = User::factory()->create([
            'role' => 'admin',
            'is_admin' => true,
        ]);
        $civilian = User::factory()->create([
            'email' => 'blocked.civilian@example.com',
            'password' => 'CivilianPass123!',
            'role' => 'civilian',
        ]);

        $civilian->forceFill([
            'api_token' => hash('sha256', 'existing-token'),
        ])->save();

        $this->actingAs($responder)
            ->patch(route('civilian-accounts.block', $civilian), [
                'block_reason' => 'Repeated false emergency submissions.',
            ])
            ->assertRedirect(route('civilian-accounts.index').'#civilian-account-'.$civilian->id);

        $civilian->refresh();

        $this->assertTrue($civilian->isBlocked());
        $this->assertSame('Repeated false emergency submissions.', $civilian->blocked_reason);
        $this->assertSame($responder->id, $civilian->blocked_by);
        $this->assertNull($civilian->api_token);

        $this->post(route('logout'));

        $this->from(route('login'))
            ->post(route('login'), [
                'identity' => 'blocked.civilian@example.com',
                'password' => 'CivilianPass123!',
            ])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['identity']);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'blocked.civilian@example.com',
            'password' => 'CivilianPass123!',
        ])->assertStatus(423);

        $this->actingAs($responder)
            ->patch(route('civilian-accounts.block', $civilian), [
                'block_reason' => '',
            ])
            ->assertRedirect(route('civilian-accounts.index').'#civilian-account-'.$civilian->id);

        $civilian->refresh();

        $this->assertFalse($civilian->isBlocked());
        $this->assertNull($civilian->blocked_reason);
        $this->assertNull($civilian->blocked_by);
    }

    public function test_responder_can_search_and_filter_civilian_accounts(): void
    {
        $responder = User::factory()->create([
            'role' => 'admin',
            'is_admin' => true,
        ]);

        $mobileBlocked = User::factory()->create([
            'name' => 'Field Mobile Civilian',
            'email' => 'field.mobile@example.com',
            'role' => 'civilian',
            'blocked_at' => now(),
            'blocked_by' => $responder->id,
            'blocked_reason' => 'Admin review pending.',
            'last_login_at' => now()->subMinutes(7),
            'last_login_ip_address' => '192.168.1.90',
            'last_login_user_agent' => 'Mozilla/5.0 (Linux; Android 14; Mobile) AppleWebKit/537.36 Chrome/123.0 Mobile Safari/537.36',
        ]);
        $mobileBlocked->responderProfile()->create([
            'phone' => '09175550001',
            'assigned_station' => 'Civilian Mobile',
            'connectivity_mode' => 'auto_select',
            'notification_profile' => 'critical_alerts',
        ]);

        $desktopActive = User::factory()->create([
            'name' => 'Desktop Civilian',
            'email' => 'desktop.civilian@example.com',
            'role' => 'civilian',
            'last_login_at' => now()->subMinutes(10),
            'last_login_ip_address' => '192.168.1.25',
            'last_login_user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/123.0 Safari/537.36',
        ]);
        $desktopActive->responderProfile()->create([
            'phone' => '09175550002',
            'assigned_station' => 'Civilian Mobile',
            'connectivity_mode' => 'auto_select',
            'notification_profile' => 'critical_alerts',
        ]);

        $this->actingAs($responder)
            ->get(route('civilian-accounts.index', [
                'search' => '192.168.1.90',
                'status' => 'blocked',
                'device' => 'mobile',
            ]))
            ->assertOk()
            ->assertSee('Search & Filters')
            ->assertSee('Search')
            ->assertSee('Account access history')
            ->assertSee('Field Mobile Civilian')
            ->assertSee('Admin review pending.')
            ->assertDontSee('Desktop Civilian')
            ->assertDontSee('desktop.civilian@example.com');
    }

    public function test_civilian_account_audit_log_records_responder_blocks_and_deletes(): void
    {
        $responder = User::factory()->create([
            'name' => 'Admin Auditor',
            'email' => 'admin.auditor@example.com',
            'role' => 'admin',
            'is_admin' => true,
        ]);

        $civilian = User::factory()->create([
            'name' => 'Audit Civilian',
            'email' => 'audit.civilian@example.com',
            'password' => 'AuditCivilianPass123!',
            'role' => 'civilian',
        ]);
        $civilian->responderProfile()->create([
            'phone' => '09179991111',
            'assigned_station' => 'Civilian Mobile',
            'connectivity_mode' => 'auto_select',
            'notification_profile' => 'critical_alerts',
        ]);

        $deleteCandidate = User::factory()->create([
            'name' => 'Delete Candidate',
            'email' => 'delete.candidate@example.com',
            'role' => 'civilian',
        ]);
        $deleteCandidate->responderProfile()->create([
            'phone' => '09179992222',
            'assigned_station' => 'Civilian Mobile',
            'connectivity_mode' => 'auto_select',
            'notification_profile' => 'critical_alerts',
        ]);

        $this->actingAs($responder)
            ->patch(route('civilian-accounts.block', $civilian), [
                'block_reason' => 'Escalated for admin review.',
            ])
            ->assertRedirect(route('civilian-accounts.index').'#civilian-account-'.$civilian->id);

        $this->actingAs($responder)
            ->delete(route('civilian-accounts.destroy', $deleteCandidate))
            ->assertRedirect(route('civilian-accounts.index'));

        $this->assertDatabaseHas('civilian_account_audits', [
            'action' => 'blocked',
            'target_name' => 'Audit Civilian',
            'target_email' => 'audit.civilian@example.com',
            'responder_id' => $responder->id,
            'ip_address' => '127.0.0.1',
        ]);

        $this->assertDatabaseHas('civilian_account_audits', [
            'action' => 'deleted',
            'target_name' => 'Delete Candidate',
            'target_email' => 'delete.candidate@example.com',
            'responder_id' => $responder->id,
        ]);

        $this->actingAs($responder)
            ->get(route('civilian-accounts.index'))
            ->assertOk()
            ->assertSee('Account access history')
            ->assertSee('Blocked account access')
            ->assertSee('Deleted civilian account')
            ->assertSee('Admin Auditor')
            ->assertSee('Reason: Escalated for admin review.')
            ->assertSee('Linked report count at delete time: 0');
    }

    public function test_responder_can_delete_civilian_account_without_deleting_report_history(): void
    {
        $responder = User::factory()->create([
            'role' => 'admin',
            'is_admin' => true,
        ]);
        $civilian = User::factory()->create([
            'name' => 'Delete Me',
            'role' => 'civilian',
        ]);
        $civilian->responderProfile()->create([
            'phone' => '09175555555',
            'assigned_station' => 'Civilian Mobile',
            'connectivity_mode' => 'auto_select',
            'notification_profile' => 'push_notifications',
        ]);

        $report = IncidentReport::query()->create([
            'report_code' => 'INC-CIV-DELETE-1',
            'reported_by' => $civilian->id,
            'reporter_name' => $civilian->name,
            'reporter_contact' => '09175555555',
            'incident_type' => 'Road Crash',
            'severity' => 'Serious',
            'status' => 'received',
            'channel' => 'Internet',
            'transmission_type' => 'online',
            'location_text' => 'Bontoc Junction',
            'description' => 'Report history should remain even if the civilian account is deleted.',
            'transmitted_at' => now(),
            'status_updated_at' => now(),
        ]);

        $this->actingAs($responder)
            ->delete(route('civilian-accounts.destroy', $civilian))
            ->assertRedirect(route('civilian-accounts.index'));

        $this->assertDatabaseMissing('users', [
            'id' => $civilian->id,
        ]);

        $report->refresh();

        $this->assertNull($report->reported_by);
        $this->assertSame('Delete Me', $report->reporter_name);
    }

    public function test_blocked_civilian_is_logged_out_from_protected_web_routes(): void
    {
        $civilian = User::factory()->create([
            'role' => 'civilian',
            'blocked_at' => now(),
            'blocked_reason' => 'Blocked for security review.',
        ]);

        $this->actingAs($civilian)
            ->get(route('dashboard'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_civilian_cannot_see_or_open_responder_only_civilian_account_management(): void
    {
        $civilian = User::factory()->create([
            'role' => 'civilian',
        ]);

        $this->actingAs($civilian)
            ->get(route('reports.create'))
            ->assertOk()
            ->assertDontSee('Civilian Accounts');

        $this->actingAs($civilian)
            ->get(route('civilian-accounts.index'))
            ->assertRedirect(route('dashboard'));
    }
}
