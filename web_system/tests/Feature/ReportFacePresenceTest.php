<?php

namespace Tests\Feature;

use App\Events\IncidentFeedUpdated;
use App\Models\IncidentReport;
use App\Models\User;
use App\Support\IncidentReportIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReportFacePresenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();
        Event::fake([IncidentFeedUpdated::class]);
        Http::preventStrayRequests();
        config(['services.ai_text.mode' => 'MANUAL_ONLY', 'services.face_liveness.url' => 'http://face.test']);
    }

    private function civilian(): User
    {
        return User::factory()->create(['role' => 'civilian', 'account_verified_at' => now(), 'api_token' => hash('sha256', 'report-face-test')]);
    }

    private function checkedPayload(User $user): array
    {
        $id = (string) Str::uuid();
        $file = UploadedFile::fake()->image('face.jpg', 480, 640);
        $challenge = $this->actingAs($user)->postJson(route('face-liveness.challenge'), ['purpose' => 'report', 'submission_id' => $id])
            ->assertOk()->assertJsonPath('blink_count', 0)->assertJsonPath('verification_mode', 'face')->json('challenge_id');
        Http::fake(['face.test/liveness/verify' => Http::response([
            'accepted' => true, 'blink_count' => 0, 'selfie_sha256' => hash_file('sha256', $file->getRealPath()),
        ])]);
        $proof = $this->postJson(route('face-liveness.verify'), [
            'challenge_id' => $challenge, 'frames' => [['ms' => 0, 'jpeg' => base64_encode(file_get_contents($file->getRealPath()))]],
            // Client-supplied mode/count cannot change the stored challenge.
            'purpose' => 'account', 'required_blinks' => 2,
        ])->assertOk()->assertJsonPath('expires_in', 86400)->json('proof');
        Http::assertSent(fn ($request) => $request['purpose'] === 'report' && $request['required_blinks'] === 0
            && count($request['frames']) === 1
            && hash_equals(hash_hmac('sha256', $request->body(), config('app.key')), $request->header('X-Liveness-Signature')[0]));

        return ['submission_id' => $id, 'liveness_proof' => $proof, 'selfie' => $file,
            'description' => 'A vehicle collided near the bridge.', 'incident_type' => 'Road Accident',
            'location_text' => 'Bridge entrance', 'latitude' => 10.35, 'longitude' => 124.97,
            'evidence' => UploadedFile::fake()->image('scene.jpg', 640, 480)];
    }

    public function test_report_face_check_requires_a_draft_and_only_issues_a_draft_bound_face_proof(): void
    {
        $user = $this->civilian();
        $this->actingAs($user)->postJson(route('face-liveness.challenge'), ['purpose' => 'report'])
            ->assertUnprocessable()->assertInvalid('submission_id');
        $payload = $this->checkedPayload($user);
        $row = DB::table('face_liveness_challenges')->sole();
        $this->assertSame($payload['submission_id'], $row->submission_id);
        $this->assertSame(0, $row->blink_count);
        $this->assertNotSame($payload['liveness_proof'], $row->proof_hash);

        $wrongDraft = [...$payload, 'submission_id' => (string) Str::uuid()];
        $this->postJson(route('reports.store'), $wrongDraft)->assertUnprocessable()->assertInvalid('liveness_proof');
        $other = User::factory()->create(['role' => 'civilian', 'account_verified_at' => now()]);
        $this->actingAs($other)->postJson(route('reports.store'), $payload)->assertUnprocessable()->assertInvalid('liveness_proof');
        $this->actingAs($user)->postJson(route('reports.store'), [...$payload, 'selfie' => UploadedFile::fake()->image('other.jpg', 200, 200)])
            ->assertUnprocessable()->assertInvalid('liveness_proof');
        $this->assertDatabaseCount('incident_reports', 0);
        $this->assertSame([], Storage::allFiles());
        $this->assertNull(DB::table('face_liveness_challenges')->value('consumed_at'));
    }

    public function test_report_check_does_not_downgrade_account_blink_verification(): void
    {
        $user = $this->civilian();
        $payload = $this->checkedPayload($user);
        $user->forceFill(['account_verified_at' => null])->save();
        $this->postJson(route('account.verify.store'), [
            'verification_selfie' => $payload['selfie'], 'liveness_proof' => $payload['liveness_proof'],
        ])->assertUnprocessable()->assertInvalid('liveness_proof');
        $account = $this->postJson(route('face-liveness.challenge'), ['purpose' => 'account'])
            ->assertOk()->assertJsonPath('verification_mode', 'face_blink');
        $this->assertContains($account->json('blink_count'), [1, 2]);
        $this->postJson(route('face-liveness.verify'), [
            'challenge_id' => $account->json('challenge_id'), 'purpose' => 'report',
            'frames' => [['ms' => 0, 'jpeg' => base64_encode(file_get_contents($payload['selfie']->getRealPath()))]],
        ])->assertUnprocessable()->assertInvalid('liveness_proof');
        Http::assertSentCount(1);
    }

    public static function channels(): array
    {
        return [['web'], ['api']];
    }

    private function send(string $channel, array $payload)
    {
        return $channel === 'web' ? $this->postJson(route('reports.store'), $payload)
            : $this->withHeader('Authorization', 'Bearer report-face-test')->postJson('/api/v1/reports', $payload);
    }

    #[DataProvider('channels')]
    public function test_failed_validation_keeps_face_check_for_edited_retry_and_one_receipt(string $channel): void
    {
        $user = $this->civilian();
        $payload = $this->checkedPayload($user);
        $this->send($channel, [...$payload, 'location_text' => ''])->assertUnprocessable()->assertInvalid('location_text');
        $this->assertNull(DB::table('face_liveness_challenges')->value('consumed_at'));
        $this->travel(10)->minutes();
        $payload['description'] = 'Updated: a passenger is injured beside the bridge.';
        $payload['evidence'] = UploadedFile::fake()->image('new-scene.jpg', 640, 480);
        $this->send($channel, $payload)->assertCreated();
        $report = IncidentReport::query()->sole();
        $this->assertSame($payload['description'], $report->description);
        $this->assertSame('new-scene.jpg', $report->evidence_original_name);
        $this->assertNotNull(DB::table('face_liveness_challenges')->value('consumed_at'));
        $this->send($channel, $payload)->assertOk()->assertJsonPath('duplicate', true);
        $this->send($channel, [...$payload, 'submission_id' => (string) Str::uuid()])
            ->assertUnprocessable()->assertInvalid('liveness_proof');
        $this->assertDatabaseCount('incident_reports', 1);
        Event::assertDispatchedTimes(IncidentFeedUpdated::class, 1);
        Http::assertSentCount(1);
    }

    #[DataProvider('channels')]
    public function test_database_failure_preserves_proof_and_cleans_files_for_retry(string $channel): void
    {
        $user = $this->civilian();
        $payload = $this->checkedPayload($user);
        $ingestor = Mockery::mock(IncidentReportIngestor::class)->makePartial();
        $ingestor->shouldReceive('persist')->once()->andThrow(new \RuntimeException('Simulated persistence failure'));
        $this->app->instance(IncidentReportIngestor::class, $ingestor);
        $this->send($channel, $payload)->assertStatus(500);
        $this->assertNull(DB::table('face_liveness_challenges')->value('consumed_at'));
        $this->assertDatabaseCount('incident_reports', 0);
        $this->assertSame([], Storage::allFiles());
        $this->app->instance(IncidentReportIngestor::class, new IncidentReportIngestor);
        $this->send($channel, [...$payload, 'description' => 'Corrected report after failed delivery.'])->assertCreated();
        $this->assertDatabaseCount('incident_reports', 1);
        Http::assertSentCount(1);
    }

    #[DataProvider('channels')]
    public function test_storage_failure_does_not_consume_report_face_check(string $channel): void
    {
        $user = $this->civilian();
        $payload = $this->checkedPayload($user);
        $disk = Storage::disk();
        $failedDisk = Mockery::mock($disk)->makePartial();
        $failedDisk->shouldReceive('putFileAs')->once()->andReturn(false);
        Storage::set(config('filesystems.default'), $failedDisk);
        $this->send($channel, $payload)->assertStatus(500);
        $this->assertNull(DB::table('face_liveness_challenges')->value('consumed_at'));
        $this->assertDatabaseCount('incident_reports', 0);
        Storage::set(config('filesystems.default'), $disk);
        $this->send($channel, $payload)->assertCreated();
        Http::assertSentCount(1);
    }

    public function test_expired_report_proof_never_creates_a_report(): void
    {
        $payload = $this->checkedPayload($this->civilian());
        $this->travel(25)->hours();
        $this->postJson(route('reports.store'), $payload)->assertUnprocessable()->assertInvalid('liveness_proof');
        $this->assertDatabaseCount('incident_reports', 0);
    }
}
