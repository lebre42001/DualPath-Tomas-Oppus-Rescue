<?php

namespace Tests\Feature;

use App\Events\IncidentFeedUpdated;
use App\Models\IncidentReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TextAiDeploymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['services.ai_text.mode' => 'MODEL_ADVISORY', 'services.ai_text.enabled' => true,
            'services.ai_text.allow_experimental' => true, 'services.ai_text.url' => 'http://text-ai.test']);
        Event::fake([IncidentFeedUpdated::class]);
        Http::preventStrayRequests();
    }

    public function test_health_checks_the_connected_model_and_exposes_only_readiness(): void
    {
        Http::fake(['text-ai.test/ready/text' => Http::response(['status' => 'ready',
            'deployment_status' => 'experimental', 'model_name' => 'text-test', 'model_version' => 'v1', 'private_path' => 'hidden'])]);
        $this->getJson('/system/ai-status')->assertOk()->assertJsonPath('status', 'ready')
            ->assertJsonPath('admin_confirmation_required', true)->assertJsonMissingPath('private_path');
        config(['services.ai_text.allow_experimental' => false]);
        $this->getJson('/system/ai-status')->assertStatus(503)->assertJsonPath('status', 'unavailable');
    }

    public function test_disabled_and_unavailable_health_never_report_a_ready_model(): void
    {
        config(['services.ai_text.mode' => 'MANUAL_ONLY']);
        $this->getJson('/system/ai-status')->assertOk()->assertJsonPath('status', 'disabled');
        Http::assertNothingSent();
        config(['services.ai_text.mode' => 'MODEL_ADVISORY']);
        Http::fake(['text-ai.test/ready/text' => Http::response(['private_error' => 'secret'], 503)]);
        $this->getJson('/system/ai-status')->assertStatus(503)->assertDontSee('secret');
    }

    public function test_existing_pending_report_can_receive_a_suggestion_then_admin_confirms_it(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'role' => 'admin']);
        $report = $this->report();
        Http::fake(['text-ai.test/predict/text' => Http::response($this->prediction())]);
        $this->actingAs($admin)->get(route('reports.show', $report))->assertOk()->assertSee('Analyze description with AI');
        $this->post(route('reports.ai-suggestion', $report))->assertRedirect();
        $report->refresh();
        $this->assertSame('Critical', $report->ai_suggested_severity);
        $this->assertNull($report->operational_severity);
        $this->assertSame('pending', $report->status);
        $this->assertSame('Original message.', $report->response_notes);
        Http::assertSent(fn ($request) => $request->data() === ['description' => $report->description]);
        $this->post(route('reports.severity-review', $report), ['severity_review_label' => 'critical',
            'severity_review_notes' => 'The description reports immediate danger.'])->assertRedirect();
        $report->refresh();
        $this->assertSame('Critical', $report->operational_severity);
        $this->assertSame('confirmed_ai', $report->description_severity_review['review_method']);
    }

    public function test_failed_suggestion_preserves_the_existing_report(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'role' => 'admin']);
        $report = $this->report();
        Http::fake(['text-ai.test/predict/text' => Http::response([], 503)]);
        $this->actingAs($admin)->post(route('reports.ai-suggestion', $report))->assertRedirect();
        $this->assertSame('manual_only', $report->fresh()->ai_source);
        $this->assertNull($report->fresh()->severity);
    }

    public function test_model_abstention_records_analysis_without_inventing_a_severity(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'role' => 'admin']);
        $report = $this->report();
        $payload = $this->prediction();
        $payload['severity'] = null;
        $payload['predicted_label'] = 'needs_review';
        $payload['status'] = 'needs_review';
        $payload['probabilities'] = ['minor' => 0.02, 'serious' => 0.02, 'critical' => 0.02, 'needs_review' => 0.94];
        Http::fake(['text-ai.test/predict/text' => Http::response($payload)]);
        $this->actingAs($admin)->post(route('reports.ai-suggestion', $report))->assertRedirect();
        $report->refresh();
        $this->assertSame('text_review', $report->ai_source);
        $this->assertNull($report->severity);
        $this->assertNull($report->operational_severity);
        $this->assertTrue($report->ai_review_required);
        $this->assertSame('fixture-text-model', $report->ai_model_name);
    }

    public function test_suggestions_cannot_overwrite_human_decisions_or_run_for_non_admins(): void
    {
        $report = $this->report();
        foreach (['civilian', 'responder'] as $role) {
            $user = User::factory()->create(['is_admin' => false, 'role' => $role, 'account_verified_at' => now()]);
            $this->actingAs($user)->postJson(route('reports.ai-suggestion', $report))->assertForbidden();
        }
        $admin = User::factory()->create(['is_admin' => true, 'role' => 'admin']);
        $report->update(['ai_source' => 'human_responder', 'severity' => 'Serious']);
        $this->actingAs($admin)->post(route('reports.ai-suggestion', $report))->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_a_concurrent_human_review_wins_over_a_slow_model_request(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'role' => 'admin']);
        $report = $this->report();
        Http::fake(function () use ($report) {
            $report->update(['ai_source' => 'human_responder', 'severity' => 'Serious']);
            return Http::response($this->prediction());
        });
        $this->actingAs($admin)->post(route('reports.ai-suggestion', $report))->assertStatus(409);
        $this->assertSame('Serious', $report->fresh()->severity);
    }

    private function report(): IncidentReport
    {
        return IncidentReport::create(['report_code' => 'TEXT-'.uniqid(), 'reporter_name' => 'Test',
            'incident_type' => 'Road Accident', 'location_text' => 'Test location',
            'description' => 'A driver is unresponsive after a collision.', 'status' => 'pending',
            'response_notes' => 'Original message.', 'ai_source' => 'manual_only', 'severity' => null,
            'transmission_type' => 'Online', 'submitted_at' => now()]);
    }

    private function prediction(): array
    {
        return ['severity' => 'critical', 'predicted_label' => 'critical', 'confidence' => 0.94,
            'probabilities' => ['minor' => 0.02, 'serious' => 0.02, 'critical' => 0.94, 'needs_review' => 0.02],
            'responder_review_required' => true, 'model_name' => 'fixture-text-model', 'model_version' => 'v1',
            'status' => 'complete', 'summary' => 'Experimental suggestion.', 'deployment_status' => 'experimental'];
    }
}
