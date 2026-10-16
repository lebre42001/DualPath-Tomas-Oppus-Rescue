<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\IncidentReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;

class TextAiStatusController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $status = ['status' => 'disabled', 'model_name' => null, 'model_version' => null,
            'deployment_status' => null, 'admin_confirmation_required' => true];
        if (! IncidentReport::manualOnlyMode() && config('services.ai_text.enabled')) {
            $status['status'] = 'unavailable';
            try {
                $response = Http::acceptJson()->connectTimeout(2)->timeout(5)
                    ->get(rtrim((string) config('services.ai_text.url'), '/').'/ready/text');
                if ($response->successful() && $response->json('status') === 'ready'
                    && in_array($response->json('deployment_status'), ['experimental', 'validated'], true)
                    && ($response->json('deployment_status') === 'validated' || config('services.ai_text.allow_experimental'))) {
                    $status = [...$status, 'status' => 'ready',
                        'model_name' => $response->json('model_name'),
                        'model_version' => $response->json('model_version'),
                        'deployment_status' => $response->json('deployment_status')];
                }
            } catch (\Throwable) {
                // Only publish readiness, never service URLs, reports or remote errors.
            }
        }

        return response()->json($status, $status['status'] === 'unavailable' ? 503 : 200,
            ['Cache-Control' => 'no-store']);
    }
}
