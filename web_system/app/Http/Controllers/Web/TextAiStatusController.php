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
            'deployment_status' => null, 'admin_confirmation_required' => true,
            'connection_status' => 'disabled'];
        if (! IncidentReport::manualOnlyMode() && config('services.ai_text.enabled')) {
            $status['status'] = 'unavailable';
            $status['connection_status'] = 'unreachable';
            $host = parse_url((string) config('services.ai_text.url'), PHP_URL_HOST);
            $status['service_target'] = $host === 'text-ai' ? 'compose_internal'
                : (in_array($host, ['localhost', '127.0.0.1', '::1'], true) ? 'localhost' : 'other');
            try {
                $response = Http::acceptJson()->connectTimeout(2)->timeout(5)
                    ->get(rtrim((string) config('services.ai_text.url'), '/').'/ready/text');
                $status['connection_status'] = ! $response->successful() ? 'service_not_ready'
                    : ($response->json('deployment_status') === 'experimental' && ! config('services.ai_text.allow_experimental')
                        ? 'experimental_not_allowed' : 'invalid_response');
                if ($response->successful() && $response->json('status') === 'ready'
                    && in_array($response->json('deployment_status'), ['experimental', 'validated'], true)
                    && ($response->json('deployment_status') === 'validated' || config('services.ai_text.allow_experimental'))) {
                    $status = [...$status, 'status' => 'ready', 'connection_status' => 'ready',
                        'model_name' => $response->json('model_name'),
                        'model_version' => $response->json('model_version'),
                        'text_rules_enabled' => $response->json('text_rules_enabled') === true,
                        'text_rules_version' => $response->json('text_rules_enabled') === true
                            ? $response->json('text_rules_version') : null,
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
