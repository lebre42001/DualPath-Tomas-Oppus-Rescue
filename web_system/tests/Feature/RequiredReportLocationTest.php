<?php

namespace Tests\Feature;

use App\Events\IncidentFeedUpdated;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RequiredReportLocationTest extends TestCase
{
    use RefreshDatabase;

    public static function invalidLocations(): array
    {
        return [
            'missing GPS' => [[], ['latitude', 'longitude']],
            'retired manual fallback' => [['gps_fallback_used' => '1'], ['latitude', 'longitude']],
            'partial latitude' => [['latitude' => 10.354270], ['longitude']],
            'partial longitude' => [['longitude' => 124.970400], ['latitude']],
            'invalid coordinates' => [['latitude' => 'not-a-coordinate', 'longitude' => 'unknown'], ['latitude', 'longitude']],
            'outside bounds' => [['latitude' => 91, 'longitude' => 181], ['latitude', 'longitude']],
            'missing landmark with GPS' => [['latitude' => 10.354270, 'longitude' => 124.970400, 'location_text' => null], ['location_text']],
            'blank landmark with GPS' => [['latitude' => 10.354270, 'longitude' => 124.970400, 'location_text' => '   '], ['location_text']],
        ];
    }

    #[DataProvider('invalidLocations')]
    public function test_civilian_cannot_bypass_gps_on_web_or_api(array $coordinates, array $errors): void
    {
        Storage::fake();
        Event::fake([IncidentFeedUpdated::class]);
        Http::fake();
        $civilian = User::factory()->create([
            'role' => 'civilian',
            'account_verified_at' => now(),
            'api_token' => hash('sha256', 'required-location-test-token'),
        ]);
        $payload = $coordinates + [
            'incident_type' => 'Road Accident',
            'location_text' => 'Poblacion near the market',
            'description' => 'Vehicle crash near the market entrance.',
            'evidence' => UploadedFile::fake()->image('scene.jpg'),
            'selfie' => UploadedFile::fake()->image('selfie.jpg'),
        ];

        $this->actingAs($civilian)->from(route('reports.create'))
            ->post(route('reports.store'), $payload)
            ->assertRedirect(route('reports.create'))->assertSessionHasErrors($errors);

        $this->withHeader('Authorization', 'Bearer required-location-test-token')
            ->postJson('/api/v1/reports', $payload)->assertUnprocessable()->assertInvalid($errors);

        $this->assertDatabaseCount('incident_reports', 0);
        $this->assertCount(0, Storage::allFiles('incident-evidence'));
        $this->assertCount(0, Storage::allFiles('incident-selfies'));
        Event::assertNotDispatched(IncidentFeedUpdated::class);
        Http::assertNothingSent();
    }
}
