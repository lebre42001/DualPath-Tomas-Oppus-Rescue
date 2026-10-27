<?php

namespace Tests\Feature;

use App\Events\IncidentFeedUpdated;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FaceLivenessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();
        Event::fake([IncidentFeedUpdated::class]);
        config(['services.ai_text.mode' => 'MANUAL_ONLY', 'services.face_liveness.url' => 'http://face.test']);
        Http::preventStrayRequests();
    }

    private function capture(UploadedFile $file): array
    {
        return array_map(fn ($i) => ['ms' => $i*100, 'jpeg' => base64_encode(file_get_contents($file->getRealPath()))], range(0, 59));
    }

    private function challenge(User $user): string
    {
        $id = $this->actingAs($user)->postJson(route('face-liveness.challenge'), ['purpose' => 'account'])
            ->assertOk()->json('challenge_id');
        DB::table('face_liveness_challenges')->where('id', $id)->update(['created_at' => now()->subSeconds(8)]);
        return $id;
    }

    public function test_uploaded_photo_alone_cannot_verify_account_or_send_a_civilian_report(): void
    {
        Http::fake();
        $user = User::factory()->create(['role' => 'civilian', 'account_verified_at' => null]);
        $selfie = UploadedFile::fake()->image('selfie.jpg');
        $this->actingAs($user)->postJson(route('account.verify.store'), ['verification_selfie' => $selfie])
            ->assertUnprocessable()->assertInvalid('liveness_proof');
        $this->assertNull($user->fresh()->account_verified_at);
        $user->forceFill(['account_verified_at' => now(), 'api_token' => hash('sha256', 'live-test')])->save();
        $payload = ['description' => 'Road collision.', 'location_text' => 'Market entrance', 'latitude' => 10.35, 'longitude' => 124.97,
            'incident_type' => 'Road Accident', 'evidence' => UploadedFile::fake()->image('scene.jpg'), 'selfie' => $selfie];
        $this->postJson(route('reports.store'), $payload)->assertUnprocessable()->assertInvalid('liveness_proof');
        $this->withHeader('Authorization', 'Bearer live-test')->postJson('/api/v1/reports', $payload)
            ->assertUnprocessable()->assertInvalid('liveness_proof');
        $this->assertDatabaseCount('incident_reports', 0);
        $this->assertSame([], Storage::allFiles());
        Http::assertNothingSent();
        Event::assertNotDispatched(IncidentFeedUpdated::class);
    }

    public function test_account_proof_is_bound_to_its_user_purpose_and_exact_selfie(): void
    {
        $user = User::factory()->create(['role' => 'civilian', 'account_verified_at' => null]);
        $other = User::factory()->create(['role' => 'civilian', 'account_verified_at' => null]);
        $file = UploadedFile::fake()->image('face.jpg', 240, 320);
        $payload = $this->verifiedSelfie($file, $user, 'account', 'verification_selfie');
        $this->actingAs($other)->postJson(route('account.verify.store'), $payload)->assertUnprocessable()->assertInvalid('liveness_proof');
        $wrongFile = [...$payload, 'verification_selfie' => UploadedFile::fake()->image('different.jpg', 320, 240)];
        $this->actingAs($user)->postJson(route('account.verify.store'), $wrongFile)->assertUnprocessable()->assertInvalid('liveness_proof');
        $wrongPurpose = $this->verifiedSelfie($file, $user, 'report', 'verification_selfie');
        $this->postJson(route('account.verify.store'), $wrongPurpose)->assertUnprocessable()->assertInvalid('liveness_proof');
        $this->post(route('account.verify.store'), $payload)->assertRedirect(route('dashboard'));
        $this->assertSame('face_blink_challenge_v1', $user->fresh()->account_verification_method);
        $this->assertNotNull(DB::table('face_liveness_challenges')->where('proof_hash', hash('sha256', $payload['liveness_proof']))->value('consumed_at'));
    }

    public function test_expired_account_proof_is_rejected(): void
    {
        $user = User::factory()->create(['role' => 'civilian', 'account_verified_at' => null]);
        $payload = $this->verifiedSelfie(UploadedFile::fake()->image('face.jpg'), $user, 'account', 'verification_selfie');
        DB::table('face_liveness_challenges')->update(['expires_at' => now()->subSecond()]);
        $this->actingAs($user)->postJson(route('account.verify.store'), $payload)->assertUnprocessable()->assertInvalid('liveness_proof');
        $this->assertNull($user->fresh()->account_verified_at);
        $this->assertSame([], Storage::allFiles());
        $this->from(route('account.verify'))->post(route('account.verify.store'), $payload)
            ->assertSessionMissing('_old_input.liveness_proof');
        $this->from(route('account.verify'))->post(route('face-liveness.verify'), ['challenge_id' => 'invalid', 'frames' => [['jpeg' => 'private-capture']]])
            ->assertSessionMissing('_old_input.frames');
    }

    public function test_a_report_proof_can_only_create_one_report(): void
    {
        $user = User::factory()->create(['role' => 'civilian', 'account_verified_at' => now(), 'api_token' => hash('sha256', 'live-test')]);
        $payload = [...$this->verifiedSelfie(UploadedFile::fake()->image('face.jpg'), $user),
            'description' => 'Vehicle collision beside the market.', 'location_text' => 'Market entrance', 'latitude' => 10.35, 'longitude' => 124.97,
            'incident_type' => 'Road Accident', 'evidence' => UploadedFile::fake()->image('scene.jpg'), 'submission_id' => (string) Str::uuid()];
        $this->actingAs($user)->post(route('reports.store'), $payload)->assertSessionHasNoErrors()->assertRedirect();
        $payload['submission_id'] = (string) Str::uuid();
        $this->postJson(route('reports.store'), $payload)->assertUnprocessable()->assertInvalid('liveness_proof');
        $this->withHeader('Authorization', 'Bearer live-test')->postJson('/api/v1/reports', $payload)
            ->assertUnprocessable()->assertInvalid('liveness_proof');
        $this->assertDatabaseCount('incident_reports', 1);
        $this->assertCount(1, Storage::allFiles('incident-selfies'));
    }

    public function test_server_check_is_signed_and_a_challenge_cannot_be_verified_twice(): void
    {
        $user = User::factory()->create(['role' => 'civilian', 'account_verified_at' => null]);
        $file = UploadedFile::fake()->image('face.jpg');
        $id = $this->challenge($user);
        Http::fake(['face.test/liveness/verify' => Http::response(['accepted' => true, 'blink_count' => 3, 'selfie_sha256' => hash_file('sha256', $file->getRealPath())])]);
        $result = $this->postJson(route('face-liveness.verify'), ['challenge_id' => $id, 'required_blinks' => 0, 'frames' => $this->capture($file)])
            ->assertOk();
        $this->assertSame(64, strlen($result->json('proof')));
        Http::assertSent(fn ($request) => hash_equals(hash_hmac('sha256', $request->body(), config('app.key')), $request->header('X-Liveness-Signature')[0])
            && $request['required_blinks'] === DB::table('face_liveness_challenges')->where('id', $id)->value('blink_count'));
        $this->postJson(route('face-liveness.verify'), ['challenge_id' => $id, 'frames' => $this->capture($file)])
            ->assertUnprocessable()->assertInvalid('liveness_proof');
        Http::assertSentCount(1);
        $this->post(route('account.verify.store'), ['verification_selfie' => $file, 'liveness_proof' => $result->json('proof')])
            ->assertRedirect(route('dashboard'));
    }

    public static function failedChecks(): array
    {
        return [['no_face'], ['multiple_faces'], ['blink_missing'], ['service_unavailable']];
    }

    #[DataProvider('failedChecks')]
    public function test_no_face_no_blink_or_unavailable_engine_cannot_issue_a_proof(string $code): void
    {
        $user = User::factory()->create(['role' => 'civilian', 'account_verified_at' => null]);
        $id = $this->challenge($user);
        Http::fake(['face.test/liveness/verify' => Http::response(['accepted' => false, 'code' => $code], $code === 'service_unavailable' ? 503 : 200)]);
        $this->postJson(route('face-liveness.verify'), ['challenge_id' => $id, 'frames' => $this->capture(UploadedFile::fake()->image('face.jpg'))])
            ->assertUnprocessable()->assertInvalid('liveness_proof');
        $row = DB::table('face_liveness_challenges')->where('id', $id)->first();
        $this->assertNull($row->proof_hash);
        $this->assertNull($row->verified_at);
        $this->assertNull($user->fresh()->account_verified_at);
    }

    public function test_guest_other_user_and_instant_capture_cannot_bypass_the_challenge(): void
    {
        $this->postJson(route('face-liveness.challenge'), ['purpose' => 'account'])->assertUnauthorized();
        $user = User::factory()->create(['role' => 'civilian', 'account_verified_at' => null]);
        $other = User::factory()->create(['role' => 'civilian']);
        $id = $this->challenge($user);
        $capture = ['challenge_id' => $id, 'frames' => $this->capture(UploadedFile::fake()->image('face.jpg'))];
        $this->actingAs($other)->postJson(route('face-liveness.verify'), $capture)->assertUnprocessable();
        DB::table('face_liveness_challenges')->where('id', $id)->update(['created_at' => now()]);
        $this->actingAs($user)->postJson(route('face-liveness.verify'), $capture)->assertUnprocessable();
        $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
        $this->actingAs($admin)->postJson(route('face-liveness.challenge'), ['purpose' => 'account'])->assertForbidden();
        Http::assertNothingSent();
    }
}
