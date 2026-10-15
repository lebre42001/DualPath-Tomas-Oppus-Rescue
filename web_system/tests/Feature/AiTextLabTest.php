<?php

namespace Tests\Feature;

use App\Models\IncidentReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiTextLabTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        config(['services.ai_text.test_url' => 'http://127.0.0.1:8101']);
    }

    public function test_admin_can_open_and_use_the_non_persistent_text_ai_test_page(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
        Http::fake(['127.0.0.1:8101/predict/text' => Http::response($this->prediction())]);

        $this->actingAs($admin)->get(route('admin.text-ai-test'))
            ->assertOk()->assertSee('Experimental model — evaluation only');

        $this->actingAs($admin)->post(route('admin.text-ai-test.predict'), [
            'description' => 'Two motorcycles collided; one rider is unconscious.',
        ])->assertOk()
            ->assertSee('Critical')
            ->assertSee('Human responder review required')
            ->assertSee('uncalibrated');

        Http::assertSent(fn ($request): bool => $request->url() === 'http://127.0.0.1:8101/predict/text'
            && $request['description'] === 'Two motorcycles collided; one rider is unconscious.');
        $this->assertSame(0, IncidentReport::count());
    }

    public function test_text_ai_lab_is_limited_to_admin_receivers(): void
    {
        $civilian = User::factory()->create(['role' => 'civilian']);
        $this->actingAs($civilian)->get(route('admin.text-ai-test'))
            ->assertRedirect(route('dashboard'));

        auth()->logout();
        $this->post(route('admin.text-ai-test.predict'), ['description' => 'A collision happened.'])
            ->assertRedirect(route('login'));
    }

    public function test_unavailable_test_service_shows_safe_error_without_persisting_input(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
        Http::fake(['127.0.0.1:8101/predict/text' => Http::response(['detail' => 'internal path'], 503)]);

        $this->actingAs($admin)->post(route('admin.text-ai-test.predict'), [
            'description' => 'A vehicle struck a barrier and stopped.',
        ])->assertOk()->assertSee('Test unavailable')->assertDontSee('internal path');

        $this->assertSame(0, IncidentReport::count());
    }

    public function test_low_confidence_prediction_is_shown_as_a_top_class_but_keeps_severity_unassigned(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
        Http::fake(['127.0.0.1:8101/predict/text' => Http::response([
            ...$this->prediction(),
            'severity' => null,
            'predicted_label' => 'serious',
            'confidence' => 0.307191,
            'probabilities' => [
                'minor' => 0.240717,
                'serious' => 0.307191,
                'critical' => 0.288337,
                'needs_review' => 0.163755,
            ],
            'status' => 'needs_review',
            'summary' => 'Experimental model; responder review is required.',
        ])]);

        $this->actingAs($admin)->post(route('admin.text-ai-test.predict'), [
            'description' => 'naa na crash diri putol ang ulo',
        ])->assertOk()
            ->assertSee('Provisional top-class suggestion')
            ->assertSee('Decision status:')
            ->assertSee('This suggested class is not an assigned severity.')
            ->assertSee('Model scores (uncalibrated)')
            ->assertDontSee('Test unavailable');

        $this->assertSame(0, IncidentReport::count());
    }

    private function prediction(): array
    {
        return [
            'severity' => 'critical',
            'predicted_label' => 'critical',
            'confidence' => 0.7,
            'probabilities' => ['minor' => 0.1, 'serious' => 0.15, 'critical' => 0.7, 'needs_review' => 0.05],
            'responder_review_required' => true,
            'model_name' => 'local-experimental-xlmr',
            'model_version' => 'test-1',
            'status' => 'complete',
            'summary' => 'Experimental model suggestion. Human review required.',
            'deployment_status' => 'experimental',
        ];
    }
}
