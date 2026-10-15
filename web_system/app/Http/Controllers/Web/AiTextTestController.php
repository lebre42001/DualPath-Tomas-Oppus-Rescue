<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\ReportedFatalityKeyword;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\View\View;

class AiTextTestController extends Controller
{
    public function index(): View
    {
        return view('admin.ai-text-test', ['reportedFatalityKeyword' => false]);
    }

    public function predict(Request $request): View
    {
        $validated = $request->validate([
            'description' => ['required', 'string', 'min:8', 'max:2000'],
        ]);

        $result = null;
        $error = null;

        try {
            $response = Http::acceptJson()
                ->connectTimeout(3)
                ->timeout(max(1, (int) config('services.ai_text.test_timeout', 20)))
                ->post(rtrim((string) config('services.ai_text.test_url', 'http://127.0.0.1:8101'), '/').'/predict/text', [
                    'description' => trim($validated['description']),
                ]);

            $payload = $response->json();
            if (! $response->successful() || ! is_array($payload) || ! $this->isUsablePrediction($payload)) {
                $error = 'The local test model is unavailable or returned an unexpected response. Start/check the isolated AI service and try again.';
            } else {
                $result = [
                    'severity' => $payload['severity'],
                    'predicted_label' => $payload['predicted_label'],
                    'confidence' => $payload['confidence'],
                    'probabilities' => $payload['probabilities'],
                    'status' => $payload['status'],
                    'deployment_status' => $payload['deployment_status'],
                    'model_name' => $payload['model_name'],
                    'model_version' => $payload['model_version'],
                    'summary' => $payload['summary'],
                    'responder_review_required' => $payload['responder_review_required'],
                ];
            }
        } catch (\Throwable) {
            $error = 'Could not connect to the local experimental AI service at '.config('services.ai_text.test_url', 'http://127.0.0.1:8101').'. The description was not saved.';
        }

        return view('admin.ai-text-test', [
            'description' => trim($validated['description']),
            'reportedFatalityKeyword' => ReportedFatalityKeyword::matches($validated['description']),
            'result' => $result,
            'error' => $error,
        ]);
    }

    private function isUsablePrediction(array $payload): bool
    {
        $labels = ['minor', 'serious', 'critical', 'needs_review'];
        if (! in_array($payload['predicted_label'] ?? null, $labels, true)
            || ! in_array($payload['status'] ?? null, ['complete', 'needs_review'], true)
            || ! in_array($payload['deployment_status'] ?? null, ['experimental', 'validated'], true)
            || ! is_bool($payload['responder_review_required'] ?? null)
            || ! is_string($payload['model_name'] ?? null)
            || ! is_string($payload['model_version'] ?? null)
            || ! is_string($payload['summary'] ?? null)) {
            return false;
        }

        if ($payload['status'] === 'needs_review') {
            if (($payload['severity'] ?? null) !== null
                || ($payload['responder_review_required'] ?? false) !== true) {
                return false;
            }

            // Low-confidence responses keep their top class and scores for
            // evaluation, while severity remains null and responder review is mandatory.
            if ($payload['predicted_label'] === 'needs_review'
                && ($payload['confidence'] ?? null) === null
                && ($payload['probabilities'] ?? null) === []) {
                return true;
            }

            return $this->hasValidScores($payload, $labels);
        }

        return in_array($payload['severity'] ?? null, ['minor', 'serious', 'critical'], true)
            && ($payload['severity'] ?? null) === $payload['predicted_label']
            && $this->hasValidScores($payload, $labels);
    }

    private function hasValidScores(array $payload, array $labels): bool
    {
        if (! is_numeric($payload['confidence'] ?? null)
            || ! is_finite((float) $payload['confidence'])
            || $payload['confidence'] < 0 || $payload['confidence'] > 1
            || ! is_array($payload['probabilities'] ?? null)) {
            return false;
        }

        $scores = [];
        foreach ($labels as $label) {
            $score = $payload['probabilities'][$label] ?? null;
            if (! is_numeric($score) || ! is_finite((float) $score) || $score < 0 || $score > 1) {
                return false;
            }
            $scores[$label] = (float) $score;
        }

        return is_finite((float) $payload['confidence'])
            && abs(array_sum($scores) - 1) <= 0.01
            && abs($scores[$payload['predicted_label']] - (float) $payload['confidence']) < 0.01
            && max($scores) <= (float) $payload['confidence'] + 0.00001;
    }
}
