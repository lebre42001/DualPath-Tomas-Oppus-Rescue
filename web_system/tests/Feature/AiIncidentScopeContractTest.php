<?php

namespace Tests\Feature;

use App\Services\AiTextSeverityClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiIncidentScopeContractTest extends TestCase
{
    public function test_unsupported_category_is_preserved_as_review_without_fabricating_scores(): void
    {
        config(['services.ai_text.mode' => 'MODEL_ADVISORY', 'services.ai_text.enabled' => true,
            'services.ai_text.allow_experimental' => true, 'services.ai_text.url' => 'http://scope.test']);
        Http::preventStrayRequests();
        $payload = $this->scopePayload();
        Http::fake(['scope.test/predict/text' => Http::response($payload)]);
        $result = app(AiTextSeverityClient::class)->analyze('Smoke is visible at a building.', 'Fire Incident');
        $this->assertNull($result['severity']);
        $this->assertNull($result['confidence']);
        $this->assertNull($result['probabilities']);
        $this->assertTrue($result['review_required']);
        $this->assertSame($payload['summary'], $result['summary']);
        $this->assertSame('legacy-road-fixture', $result['model_name']);
        Http::assertSent(fn ($request) => $request['incident_type'] === 'fire_incident');
    }

    public function test_scope_gate_cannot_smuggle_a_complete_severity(): void
    {
        config(['services.ai_text.mode' => 'MODEL_ADVISORY', 'services.ai_text.enabled' => true,
            'services.ai_text.allow_experimental' => true, 'services.ai_text.url' => 'http://scope.test']);
        Http::preventStrayRequests();
        Http::fake(['scope.test/predict/text' => Http::response([
            ...$this->scopePayload(), 'severity' => 'critical', 'status' => 'complete', 'predicted_label' => 'critical',
        ])]);
        $result = app(AiTextSeverityClient::class)->analyze('Smoke is visible at a building.', 'Fire Incident');
        $this->assertNull($result['severity']);
        $this->assertSame('needs_review', $result['status']);
        $this->assertNull($result['model_name']);
    }

    private function scopePayload(): array
    {
        return ['severity' => null, 'predicted_label' => 'needs_review', 'status' => 'needs_review',
            'confidence' => null, 'probabilities' => [], 'responder_review_required' => true,
            'incident_type' => 'fire_incident', 'reason' => 'unsupported_incident_type',
            'assessment_method' => 'category_scope_gate', 'model_name' => 'legacy-road-fixture',
            'model_version' => 'fixture-v1', 'deployment_status' => 'experimental',
            'summary' => 'This model has not been trained for the selected incident category. Responder review is required.'];
    }
}
