<?php

namespace Tests\Feature;

use App\Events\IncidentFeedUpdated;
use App\Models\AiTrainingFeedback;
use App\Models\IncidentReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiFeedbackOperationalReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_operational_review_withdraws_prior_text_training_approval_until_fresh_text_review(): void
    {
        $this->withoutVite();
        config(['services.ai_text.mode' => 'MODEL_ADVISORY', 'services.routing.enabled' => false]);
        Event::fake([IncidentFeedUpdated::class]);
        Http::preventStrayRequests();
        $admin = User::factory()->create(['is_admin' => true, 'role' => 'admin']);
        $report = IncidentReport::query()->create([
            'report_code' => 'INC-FEEDBACK-OPERATIONAL', 'reporter_name' => 'Fixture reporter',
            'incident_type' => 'Road Accident', 'description' => 'A rider has a small knee wound and is alert.',
            'location_text' => 'Fixture location', 'status' => 'received', 'channel' => 'Internet',
            'transmission_type' => 'online', 'ai_source' => 'manual_only',
            'ai_status' => 'needs_review', 'ai_review_required' => true,
        ]);
        $approval = [
            'severity_review_label' => 'minor', 'severity_review_notes' => 'Small knee wound and alert condition.',
            'approve_ai_training' => true, 'training_language' => 'en',
            'training_description_sha256' => hash('sha256', $report->description),
            'training_incident_type' => $report->incident_type,
        ];
        $this->actingAs($admin)->post(route('reports.severity-review', $report), $approval)
            ->assertSessionHasNoErrors()->assertRedirect();
        $original = AiTrainingFeedback::query()->sole();
        $this->assertTrue($original->approved_for_training);
        $this->assertNull($original->superseded_at);

        // Ordinary coordination updates do not revoke a reviewed text example.
        $this->post(route('reports.coordination', $report), ['status' => 'acknowledged'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertNull($original->fresh()->superseded_at);

        $this->post(route('reports.coordination', $report), [
            'status' => 'acknowledged', 'reviewed_severity' => 'Serious',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertNotNull($original->fresh()->superseded_at);
        $this->assertSame(0, AiTrainingFeedback::query()->whereNull('superseded_at')->count());

        $this->post(route('reports.severity-review', $report), $approval)
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(1, AiTrainingFeedback::query()->whereNull('superseded_at')
            ->where('approved_for_training', true)->count());
        $this->assertNotNull($original->fresh()->superseded_at);
    }
}
