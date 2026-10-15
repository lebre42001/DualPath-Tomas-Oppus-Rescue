<?php

namespace Tests\Feature;

use App\Models\IncidentReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ApiMobileFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.ai_text.mode' => 'MODEL_ADVISORY', 'services.ai_text.enabled' => false]);
        Http::preventStrayRequests();
    }

    public function test_mobile_client_can_register_authenticate_submit_report_and_update_settings(): void
    {
        Storage::fake();
        config()->set('services.ai_severity.enabled', false);

        $registerResponse = $this->postJson('/api/v1/auth/register', [
            'name' => 'Field Reporter',
            'phone' => '09170001111',
            'email' => 'operator@example.com',
            'password' => 'password12345',
            'password_confirmation' => 'password12345',
            'role' => 'civilian',
        ]);

        $registerResponse->assertCreated();
        $token = $registerResponse->json('token');
        $this->verifyCivilianAccount('operator@example.com');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.email', 'operator@example.com')
            ->assertJsonPath('user.is_admin', false)
            ->assertJsonMissingPath('user.station')
            ->assertJsonMissingPath('user.connectivity_mode');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/summary')
            ->assertOk()
            ->assertJsonStructure([
                'application',
                'user' => ['id', 'name', 'email', 'role', 'is_admin'],
                'summary' => ['active_alerts', 'my_reports', 'transmitted_today', 'resolved_reports'],
                'recent_reports',
                'map_points',
                'notifications',
            ])
            ->assertJsonCount(0, 'map_points');

        $reportResponse = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->post('/api/v1/reports', [
            'incident_type' => 'Landslide',
            'severity' => 'Fatal',
            'location_text' => 'Mountain Road Section 4',
            'latitude' => 17.1021,
            'longitude' => 120.9855,
            'description' => 'Road blocked by landslide and one vehicle is trapped.',
            'transmission_type' => 'lora',
            'evidence_type' => 'photo',
            'evidence' => UploadedFile::fake()->image('landslide-scene.jpg'),
            'selfie' => UploadedFile::fake()->image('field-reporter-selfie.jpg'),
        ]);

        $reportResponse->assertCreated()
            ->assertJsonPath('data.location_text', 'Mountain Road Section 4')
            ->assertJsonPath('data.status', 'received')
            ->assertJsonPath('data.evidence_available', true)
            ->assertJsonPath('data.selfie_available', true)
            ->assertJsonMissingPath('data.severity')
            ->assertJsonMissingPath('data.transmission_type')
            ->assertJsonMissingPath('data.latitude')
            ->assertJsonMissingPath('data.longitude')
            ->assertJsonMissingPath('data.ai_summary');

        $report = IncidentReport::query()->latest('id')->firstOrFail();

        $this->assertSame('Road Accident', $report->incident_type);
        $this->assertSame('online', $report->transmission_type);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/reports')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $settingsResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/settings', [
                'critical_alerts' => true,
                'push_notifications' => true,
                'sms_backup' => false,
                'connectivity_mode' => 'lora_fallback',
            ])
            ->assertOk()
            ->assertJsonMissingPath('data.connectivity_mode');

        $this->assertSame('auto_select', $report->reporter->responderProfile->fresh()->connectivity_mode);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();
    }

    public function test_public_api_registration_rejects_responder_role_selection(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Unauthorized Responder',
            'phone' => '09170002222',
            'email' => 'unauthorized.responder@example.com',
            'password' => 'password12345',
            'password_confirmation' => 'password12345',
            'role' => 'responder',
        ])->assertUnprocessable()
            ->assertInvalid(['role']);

        $this->assertDatabaseMissing('users', [
            'email' => 'unauthorized.responder@example.com',
        ]);
    }

    public function test_responder_api_login_retains_operational_report_data(): void
    {
        $responder = User::factory()->create([
            'email' => 'responder.portal@example.com',
            'password' => 'ResponderPass123!',
            'role' => 'admin',
            'is_admin' => true,
        ]);

        $report = IncidentReport::query()->create([
            'report_code' => 'INC-RESP-API-1',
            'reported_by' => $responder->id,
            'reporter_name' => 'Civilian Reporter',
            'incident_type' => 'Vehicular Collision',
            'severity' => 'Fatal',
            'status' => 'received',
            'channel' => 'Internet',
            'transmission_type' => 'online',
            'location_text' => 'Bontoc National Road',
            'latitude' => 10.354270,
            'longitude' => 124.970400,
            'description' => 'Operational report visible to authorized admins.',
            'ai_summary' => 'AI image analysis predicted Fatal severity.',
            'ai_confidence' => 91,
            'ai_source' => 'python_model',
            'ai_status' => 'complete',
            'transmitted_at' => now(),
            'status_updated_at' => now(),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $responder->email,
            'password' => 'ResponderPass123!',
        ])->assertForbidden();

        $loginResponse = $this->postJson('/api/v1/admin/auth/login', [
            'email' => $responder->email,
            'password' => 'ResponderPass123!',
        ])->assertOk()
            ->assertJsonPath('user.role', 'admin');

        $token = $loginResponse->json('token');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/reports/'.$report->id)
            ->assertOk()
            ->assertJsonPath('data.severity', 'Fatal')
            ->assertJsonPath('data.latitude', '10.354270')
            ->assertJsonPath('data.longitude', '124.970400')
            ->assertJsonPath('data.ai_source', 'python_model')
            ->assertJsonPath('data.ai_confidence', 91);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/summary')
            ->assertOk()
            ->assertJsonCount(1, 'map_points')
            ->assertJsonPath('map_points.0.severity', 'Fatal');
    }

    public function test_mobile_client_can_upload_photo_evidence_with_online_report(): void
    {
        Storage::fake();
        config()->set('services.ai_severity.enabled', false);

        $registerResponse = $this->postJson('/api/v1/auth/register', [
            'name' => 'Civilian Reporter',
            'phone' => '09179991111',
            'email' => 'civilian.evidence@example.com',
            'password' => 'CivilianPass123!',
            'password_confirmation' => 'CivilianPass123!',
            'role' => 'civilian',
        ]);

        $registerResponse->assertCreated();
        $token = $registerResponse->json('token');
        $this->verifyCivilianAccount('civilian.evidence@example.com');
        $evidence = UploadedFile::fake()->image('crash-scene.jpg');
        $selfie = UploadedFile::fake()->image('verification-selfie.jpg');

        $reportResponse = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->post('/api/v1/reports', [
            'incident_type' => 'Vehicular Collision',
            'location_text' => 'National Road Junction',
            'latitude' => 10.354270,
            'longitude' => 124.970400,
            'description' => 'Two vehicles collided and responders need the attached evidence for scene review.',
            'transmission_type' => 'online',
            'evidence_type' => 'photo',
            'evidence' => $evidence,
            'selfie' => $selfie,
        ]);

        $reportResponse->assertCreated()
            ->assertJsonPath('data.evidence_available', true)
            ->assertJsonPath('data.selfie_available', true)
            ->assertJsonMissingPath('data.evidence_type')
            ->assertJsonMissingPath('data.evidence_original_name')
            ->assertJsonMissingPath('data.evidence_url')
            ->assertJsonMissingPath('data.selfie_original_name')
            ->assertJsonMissingPath('data.selfie_url');

        $report = IncidentReport::query()->latest('id')->firstOrFail();

        $this->assertSame('photo', $report->evidence_type);
        $this->assertNotNull($report->evidence_path);
        $this->assertNotNull($report->reporter_selfie_path);
        Storage::assertExists($report->evidence_path);
        Storage::assertExists($report->reporter_selfie_path);

        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->get('/api/v1/reports/'.$report->id.'/evidence')->assertOk();

        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->get('/api/v1/reports/'.$report->id.'/selfie')->assertOk();
    }

    public function test_mobile_client_rejects_civilian_report_without_verification_selfie(): void
    {
        Storage::fake();

        $registerResponse = $this->postJson('/api/v1/auth/register', [
            'name' => 'Civilian Reporter',
            'phone' => '09179992222',
            'email' => 'civilian.selfie@example.com',
            'password' => 'CivilianPass123!',
            'password_confirmation' => 'CivilianPass123!',
            'role' => 'civilian',
        ]);

        $registerResponse->assertCreated();
        $token = $registerResponse->json('token');
        $this->verifyCivilianAccount('civilian.selfie@example.com');

        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->post('/api/v1/reports', [
            'incident_type' => 'Vehicular Collision',
            'location_text' => 'National Road Junction',
            'description' => 'Attempted emergency submission without the required live verification selfie.',
            'transmission_type' => 'online',
            'evidence_type' => 'none',
        ])->assertInvalid(['selfie']);
    }

    public function test_mobile_client_requires_scene_photo_and_location_for_civilian_reports(): void
    {
        Storage::fake();

        $registerResponse = $this->postJson('/api/v1/auth/register', [
            'name' => 'Civilian Missing Evidence',
            'phone' => '09179992223',
            'email' => 'civilian.missing.evidence@example.com',
            'password' => 'CivilianPass123!',
            'password_confirmation' => 'CivilianPass123!',
            'role' => 'civilian',
        ]);

        $token = $registerResponse->json('token');
        $this->verifyCivilianAccount('civilian.missing.evidence@example.com');

        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->post('/api/v1/reports', [
            'incident_type' => 'Vehicular Collision',
            'location_text' => 'National Road Junction',
            'description' => 'Attempted emergency submission without GPS and scene photo.',
            'transmission_type' => 'online',
            'selfie' => UploadedFile::fake()->image('verification-selfie.jpg'),
        ])->assertStatus(422)
            ->assertInvalid(['latitude', 'longitude', 'evidence']);

        $this->assertDatabaseCount('incident_reports', 0);
        $this->assertCount(0, Storage::allFiles('incident-selfies'));
        $this->assertCount(0, Storage::allFiles('incident-evidence'));
    }

    public function test_mobile_api_rejects_blocked_civilian_login_and_existing_token_access(): void
    {
        $registerResponse = $this->postJson('/api/v1/auth/register', [
            'name' => 'Blocked Civilian',
            'phone' => '09179994444',
            'email' => 'blocked.api@example.com',
            'password' => 'CivilianPass123!',
            'password_confirmation' => 'CivilianPass123!',
            'role' => 'civilian',
        ]);

        $token = $registerResponse->json('token');
        $this->verifyCivilianAccount('blocked.api@example.com');

        $user = User::query()->where('email', 'blocked.api@example.com')->firstOrFail();
        $user->forceFill([
            'blocked_at' => now(),
            'blocked_reason' => 'Blocked from mobile access.',
        ])->save();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'blocked.api@example.com',
            'password' => 'CivilianPass123!',
        ])->assertStatus(423);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/auth/me')
            ->assertStatus(423);

        $user->refresh();

        $this->assertNull($user->api_token);
    }

    public function test_mobile_client_sends_only_description_to_ai_and_retains_photo_evidence(): void
    {
        Storage::fake();
        Http::fake([
            'http://text-ai.test/predict/text' => Http::response($this->textPrediction()),
        ]);

        config()->set('services.ai_text.enabled', true);
        config()->set('services.ai_text.url', 'http://text-ai.test');
        config()->set('services.ai_text.allow_experimental', false);
        config()->set('services.ai_severity.enabled', true);
        config()->set('services.ai_severity.dispatch', 'sync');
        config()->set('services.ai_severity.url', 'http://127.0.0.1:8100');
        config()->set('services.ai_severity.require_civilian_photo_gate', true);

        $registerResponse = $this->postJson('/api/v1/auth/register', [
            'name' => 'Civilian Reporter',
            'phone' => '09179993333',
            'email' => 'civilian.ai.photo@example.com',
            'password' => 'CivilianPass123!',
            'password_confirmation' => 'CivilianPass123!',
            'role' => 'civilian',
        ]);

        $token = $registerResponse->json('token');
        $this->verifyCivilianAccount('civilian.ai.photo@example.com');
        $description = 'Vehicle collision; a passenger is unresponsive and needs help.';

        $reportResponse = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->post('/api/v1/reports', [
            'incident_type' => 'Vehicular Collision',
            'location_text' => 'National Road Junction',
            'latitude' => 10.354270,
            'longitude' => 124.970400,
            'description' => $description,
            'transmission_type' => 'online',
            'evidence_type' => 'photo',
            'evidence' => UploadedFile::fake()->image('crash-scene.jpg', 640, 480),
            'selfie' => UploadedFile::fake()->image('verification-selfie.jpg'),
        ]);

        $reportResponse->assertCreated()
            ->assertJsonPath('data.status', 'received')
            ->assertJsonMissingPath('data.severity')
            ->assertJsonMissingPath('data.ai_source')
            ->assertJsonMissingPath('data.ai_status')
            ->assertJsonMissingPath('data.ai_confidence')
            ->assertJsonMissingPath('data.ai_model_name')
            ->assertJsonMissingPath('data.ai_review_required')
            ->assertJsonMissingPath('data.photo_relevance_label')
            ->assertJsonMissingPath('data.photo_relevance_confidence');

        $report = IncidentReport::query()->latest('id')->firstOrFail();

        $this->assertSame('Critical', $report->severity);
        $this->assertSame('Critical', $report->severity_display);
        $this->assertSame('text_model', $report->ai_source);
        $this->assertSame('complete', $report->ai_status);
        $this->assertSame(90, $report->ai_confidence);
        $this->assertSame('Model score 90% (uncalibrated)', $report->ai_score_display);
        $this->assertSame('reviewed-road-text-test-model', $report->ai_model_name);
        $this->assertSame('test-1', $report->ai_model_version);
        $this->assertTrue($report->ai_review_required);
        $this->assertNull($report->photo_relevance_label);
        $this->assertNull($report->photo_relevance_confidence);
        $this->assertNull($report->photo_relevance_probabilities);
        $this->assertNotNull($report->ai_processed_at);
        Storage::assertExists($report->evidence_path);
        Storage::assertExists($report->reporter_selfie_path);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'http://text-ai.test/predict/text'
            && $request->method() === 'POST'
            && $request->data() === ['description' => $description]
            && $request->hasHeader('Content-Type', 'application/json'));
    }

    public function test_disabled_text_ai_does_not_call_legacy_photo_gate_or_invent_severity(): void
    {
        Storage::fake();
        Http::fake([
            'http://127.0.0.1:8100/predict' => Http::response([
                'filename' => 'dummy-scene.jpg',
                'accepted' => false,
                'photo_relevance_label' => 'unrelated',
                'photo_relevance_confidence' => 0.94,
                'photo_relevance_probabilities' => [
                    'related' => 0.06,
                    'unrelated' => 0.94,
                ],
                'rejection_message' => 'Upload a real accident or emergency scene photo. Dummy, UI, selfie-only, or unrelated images are not accepted as evidence.',
                'responder_review_required' => false,
                'responder_review_action' => 'needs_responder_review',
                'severity' => null,
                'confidence' => 0,
                'probabilities' => [],
            ], 200),
        ]);

        config()->set('services.ai_severity.enabled', true);
        config()->set('services.ai_severity.dispatch', 'sync');
        config()->set('services.ai_severity.url', 'http://127.0.0.1:8100');
        config()->set('services.ai_severity.require_civilian_photo_gate', true);
        config()->set('services.ai_severity.retry_attempts', 1);

        $registerResponse = $this->postJson('/api/v1/auth/register', [
            'name' => 'Civilian Reporter',
            'phone' => '09179995555',
            'email' => 'civilian.ai.reject@example.com',
            'password' => 'CivilianPass123!',
            'password_confirmation' => 'CivilianPass123!',
            'role' => 'civilian',
        ]);

        $token = $registerResponse->json('token');
        $this->verifyCivilianAccount('civilian.ai.reject@example.com');

        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->post('/api/v1/reports', [
            'incident_type' => 'Vehicular Collision',
            'severity' => 'Fatal',
            'location_text' => 'National Road Junction',
            'latitude' => 10.354270,
            'longitude' => 124.970400,
            'description' => 'A vehicle is stopped on the road; details need responder review.',
            'transmission_type' => 'online',
            'evidence_type' => 'photo',
            'evidence' => UploadedFile::fake()->image('road-scene.jpg', 640, 480),
            'selfie' => UploadedFile::fake()->image('verification-selfie.jpg'),
        ])->assertCreated()
            ->assertJsonPath('data.evidence_available', true)
            ->assertJsonPath('data.selfie_available', true)
            ->assertJsonMissingPath('data.ai_confidence');

        $report = IncidentReport::query()->sole();
        $this->assertSame('Serious', $report->severity);
        $this->assertSame('Needs review', $report->severity_display);
        $this->assertSame('text_review', $report->ai_source);
        $this->assertSame('needs_review', $report->ai_status);
        $this->assertTrue($report->ai_review_required);
        $this->assertNull($report->ai_confidence);
        $this->assertNull($report->ai_probabilities);
        $this->assertNull($report->photo_relevance_label);
        $this->assertNull($report->photo_relevance_confidence);
        $this->assertSame('Model score unavailable', $report->ai_score_display);
        Storage::assertExists($report->evidence_path);
        Storage::assertExists($report->reporter_selfie_path);
        Http::assertNothingSent();
    }

    public function test_mobile_client_keeps_local_dummy_photo_prescreen_without_image_ai(): void
    {
        Storage::fake();
        Http::fake();

        $registerResponse = $this->postJson('/api/v1/auth/register', [
            'name' => 'Civilian Reporter',
            'phone' => '09179996666',
            'email' => 'civilian.api.prescreen@example.com',
            'password' => 'CivilianPass123!',
            'password_confirmation' => 'CivilianPass123!',
            'role' => 'civilian',
        ]);

        $token = $registerResponse->json('token');
        $this->verifyCivilianAccount('civilian.api.prescreen@example.com');

        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->post('/api/v1/reports', [
            'incident_type' => 'Vehicular Collision',
            'location_text' => 'National Road Junction',
            'latitude' => 10.354270,
            'longitude' => 124.970400,
            'description' => 'Attempted upload with app UI screenshot instead of a scene photo.',
            'transmission_type' => 'online',
            'evidence_type' => 'photo',
            'evidence' => UploadedFile::fake()->image('profile-screenshot.png', 576, 1280),
            'selfie' => UploadedFile::fake()->image('verification-selfie.jpg'),
        ])->assertStatus(422)
            ->assertInvalid(['evidence']);

        $this->assertDatabaseCount('incident_reports', 0);
        $this->assertCount(0, Storage::allFiles('incident-selfies'));
        $this->assertCount(0, Storage::allFiles('incident-evidence'));
        Http::assertNothingSent();
    }

    public function test_unavailable_legacy_photo_service_does_not_block_mobile_report(): void
    {
        Storage::fake();
        Http::fake([
            'http://127.0.0.1:8100/predict' => Http::response(['message' => 'Service unavailable'], 503),
        ]);

        config()->set('services.ai_severity.enabled', true);
        config()->set('services.ai_severity.dispatch', 'sync');
        config()->set('services.ai_severity.url', 'http://127.0.0.1:8100');
        config()->set('services.ai_severity.require_civilian_photo_gate', true);
        config()->set('services.ai_severity.retry_attempts', 1);

        $registerResponse = $this->postJson('/api/v1/auth/register', [
            'name' => 'Strict AI Civilian',
            'phone' => '09179997777',
            'email' => 'civilian.ai.strict@example.com',
            'password' => 'CivilianPass123!',
            'password_confirmation' => 'CivilianPass123!',
            'role' => 'civilian',
        ]);

        $token = $registerResponse->json('token');
        $this->verifyCivilianAccount('civilian.ai.strict@example.com');

        $reportResponse = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->post('/api/v1/reports', [
            'incident_type' => 'Vehicular Collision',
            'location_text' => 'National Road Junction',
            'latitude' => 10.354270,
            'longitude' => 124.970400,
            'description' => 'Vehicle crash with a real-looking uploaded scene photo.',
            'transmission_type' => 'online',
            'evidence_type' => 'photo',
            'evidence' => UploadedFile::fake()->image('road-scene.jpg', 640, 480),
            'selfie' => UploadedFile::fake()->image('verification-selfie.jpg'),
        ]);

        $reportResponse->assertCreated()
            ->assertJsonPath('data.status', 'received')
            ->assertJsonMissingPath('data.severity')
            ->assertJsonMissingPath('data.ai_source')
            ->assertJsonMissingPath('data.ai_status')
            ->assertJsonMissingPath('data.ai_review_required');

        $report = IncidentReport::query()->latest('id')->firstOrFail();

        $this->assertDatabaseCount('incident_reports', 1);
        $this->assertSame('Serious', $report->severity);
        $this->assertSame('Needs review', $report->severity_display);
        $this->assertSame('text_review', $report->ai_source);
        $this->assertSame('needs_review', $report->ai_status);
        $this->assertTrue($report->ai_review_required);
        $this->assertNull($report->ai_confidence);
        $this->assertNull($report->ai_probabilities);
        $this->assertNull($report->ai_error_message);
        $this->assertCount(1, Storage::allFiles('incident-selfies'));
        $this->assertCount(1, Storage::allFiles('incident-evidence'));
        Http::assertNothingSent();
    }

    public function test_unavailable_text_ai_accepts_mobile_photo_report_without_image_or_keyword_fallback(): void
    {
        Storage::fake();
        Http::fake([
            'http://text-ai.test/predict/text' => Http::response(['message' => 'Service unavailable'], 503),
        ]);

        config()->set('services.ai_text.enabled', true);
        config()->set('services.ai_text.url', 'http://text-ai.test');
        config()->set('services.ai_severity.enabled', true);
        config()->set('services.ai_severity.dispatch', 'sync');
        config()->set('services.ai_severity.url', 'http://127.0.0.1:8100');
        config()->set('services.ai_severity.require_civilian_photo_gate', false);
        config()->set('services.ai_severity.retry_attempts', 1);

        $registerResponse = $this->postJson('/api/v1/auth/register', [
            'name' => 'Fallback Civilian',
            'phone' => '09179994444',
            'email' => 'civilian.ai.fallback@example.com',
            'password' => 'CivilianPass123!',
            'password_confirmation' => 'CivilianPass123!',
            'role' => 'civilian',
        ]);

        $token = $registerResponse->json('token');
        $this->verifyCivilianAccount('civilian.ai.fallback@example.com');

        $reportResponse = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->post('/api/v1/reports', [
            'incident_type' => 'Vehicular Collision',
            'location_text' => 'National Road Junction',
            'latitude' => 10.354270,
            'longitude' => 124.970400,
            'description' => 'Vehicle crash with bleeding and multiple injuries.',
            'transmission_type' => 'online',
            'evidence_type' => 'photo',
            'evidence' => UploadedFile::fake()->image('fallback-scene.jpg'),
            'selfie' => UploadedFile::fake()->image('verification-selfie.jpg'),
        ]);

        $reportResponse->assertCreated()
            ->assertJsonPath('data.status', 'received')
            ->assertJsonMissingPath('data.severity')
            ->assertJsonMissingPath('data.ai_source')
            ->assertJsonMissingPath('data.ai_status')
            ->assertJsonMissingPath('data.ai_review_required');

        $report = IncidentReport::query()->latest('id')->firstOrFail();

        $this->assertSame('Serious', $report->severity);
        $this->assertSame('Needs review', $report->severity_display);
        $this->assertSame('text_review', $report->ai_source);
        $this->assertSame('needs_review', $report->ai_status);
        $this->assertTrue($report->ai_review_required);
        $this->assertNull($report->ai_confidence);
        $this->assertNull($report->ai_probabilities);
        $this->assertNull($report->ai_error_message);
        $this->assertNotNull($report->ai_processed_at);
        $this->assertStringContainsString('Severity is unassessed; responder review required.', $report->ai_summary);
        Storage::assertExists($report->evidence_path);
        Storage::assertExists($report->reporter_selfie_path);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'http://text-ai.test/predict/text'
            && $request->data() === ['description' => 'Vehicle crash with bleeding and multiple injuries.']);
    }

    public function test_responder_api_severity_is_preserved_when_description_model_disagrees(): void
    {
        config([
            'services.ai_text.enabled' => true,
            'services.ai_text.url' => 'http://text-ai.test',
            'services.ai_text.allow_experimental' => false,
            'services.ai_severity.enabled' => true,
        ]);
        Http::fake(['http://text-ai.test/predict/text' => Http::response($this->textPrediction())]);
        User::factory()->create([
            'role' => 'admin',
            'is_admin' => true,
            'api_token' => hash('sha256', 'responder-assessment-test-token'),
        ]);
        $description = 'Road collision reported by the responding team.';

        $this->withHeader('Authorization', 'Bearer responder-assessment-test-token')
            ->postJson('/api/v1/reports', [
                'incident_type' => 'Road Accident',
                'severity' => 'Serious',
                'location_text' => 'National Road Junction',
                'description' => $description,
                'transmission_type' => 'online',
            ])->assertCreated()
            ->assertJsonPath('data.severity', 'Serious')
            ->assertJsonPath('data.severity_display', 'Serious')
            ->assertJsonPath('data.severity_is_provisional', false)
            ->assertJsonPath('data.ai_source', 'text_human_override')
            ->assertJsonPath('data.ai_confidence', 90)
            ->assertJsonPath('data.ai_score_label', 'Model score 90% (uncalibrated)');

        $report = IncidentReport::query()->sole();
        $this->assertStringContainsString('Responder-assigned severity: Serious.', $report->ai_summary);
        $this->assertStringContainsString('suggests Critical', $report->ai_summary);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'http://text-ai.test/predict/text'
            && $request->data() === ['description' => $description]);
    }

    public function test_mobile_civilian_cannot_open_another_civilian_report_detail(): void
    {
        $viewer = User::factory()->create([
            'role' => 'civilian',
        ]);
        $viewer->forceFill(['api_token' => hash('sha256', 'viewer-token')])->save();
        $owner = User::factory()->create([
            'role' => 'civilian',
        ]);

        $report = IncidentReport::query()->create([
            'report_code' => 'INC-PRIVATE-0001',
            'reported_by' => $owner->id,
            'reporter_name' => $owner->name,
            'incident_type' => 'Road Emergency',
            'severity' => 'Serious',
            'status' => 'received',
            'channel' => 'Internet',
            'transmission_type' => 'online',
            'location_text' => 'Private Barangay Location',
            'latitude' => 10.354270,
            'longitude' => 124.970400,
            'description' => 'Private report should not be visible to another civilian API token.',
            'ai_summary' => 'Description-based fallback summary.',
            'ai_confidence' => 84,
            'ai_source' => 'description_fallback',
            'ai_status' => 'fallback',
            'ai_model_name' => 'description_keyword_rules',
            'ai_model_version' => '1.0.0',
            'ai_review_required' => false,
            'ai_probabilities' => ['Minor' => 0.1, 'Serious' => 0.8, 'Fatal' => 0.1],
            'evidence_type' => 'none',
            'transmitted_at' => now(),
            'status_updated_at' => now(),
        ]);

        $this->withHeader('Authorization', 'Bearer viewer-token')
            ->getJson('/api/v1/reports/'.$report->id)
            ->assertNotFound();

        $this->assertNotSame($viewer->id, $report->reported_by);
    }

    public function test_mobile_api_blocks_unverified_civilian_report_submission(): void
    {
        Storage::fake();

        $registerResponse = $this->postJson('/api/v1/auth/register', [
            'name' => 'Locked API Civilian',
            'phone' => '09179998888',
            'email' => 'civilian.locked.api@example.com',
            'password' => 'CivilianPass123!',
            'password_confirmation' => 'CivilianPass123!',
            'role' => 'civilian',
        ]);

        $token = $registerResponse->json('token');

        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->post('/api/v1/reports', [
            'incident_type' => 'Vehicular Collision',
            'location_text' => 'National Road Junction',
            'latitude' => 10.354270,
            'longitude' => 124.970400,
            'description' => 'Attempted report before civilian account verification.',
            'transmission_type' => 'online',
            'evidence_type' => 'photo',
            'evidence' => UploadedFile::fake()->image('crash-scene.jpg'),
            'selfie' => UploadedFile::fake()->image('verification-selfie.jpg'),
        ])->assertForbidden()
            ->assertJsonPath('verification_required', true);

        $this->assertDatabaseCount('incident_reports', 0);
    }

    public function test_mobile_api_requires_verified_civilian_report_description(): void
    {
        Storage::fake();
        config()->set('services.ai_severity.enabled', false);

        $registerResponse = $this->postJson('/api/v1/auth/register', [
            'name' => 'Required Description Civilian',
            'phone' => '09179997770',
            'email' => 'civilian.no.description@example.com',
            'password' => 'CivilianPass123!',
            'password_confirmation' => 'CivilianPass123!',
            'role' => 'civilian',
        ]);

        $token = $registerResponse->json('token');
        $this->verifyCivilianAccount('civilian.no.description@example.com');

        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->post('/api/v1/reports', [
            'incident_type' => 'Medical Emergency',
            'location_text' => 'National Road Junction',
            'latitude' => 10.354270,
            'longitude' => 124.970400,
            'description' => '',
            'transmission_type' => 'online',
            'evidence_type' => 'photo',
            'evidence' => UploadedFile::fake()->image('crash-scene.jpg'),
            'selfie' => UploadedFile::fake()->image('verification-selfie.jpg'),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('description');

        $this->assertDatabaseCount('incident_reports', 0);
    }

    private function textPrediction(): array
    {
        return [
            'severity' => 'critical',
            'predicted_label' => 'critical',
            'confidence' => 0.9,
            'probabilities' => ['minor' => 0.02, 'serious' => 0.05, 'critical' => 0.9, 'needs_review' => 0.03],
            'responder_review_required' => true,
            'model_name' => 'reviewed-road-text-test-model',
            'model_version' => 'test-1',
            'status' => 'complete',
            'summary' => 'Suggested text severity requires responder verification.',
            'deployment_status' => 'validated',
        ];
    }

    private function verifyCivilianAccount(string $email): void
    {
        User::query()
            ->where('email', $email)
            ->firstOrFail()
            ->forceFill([
                'account_verified_at' => now(),
                'account_verification_method' => 'live_front_camera_selfie',
            ])
            ->save();
    }
}
