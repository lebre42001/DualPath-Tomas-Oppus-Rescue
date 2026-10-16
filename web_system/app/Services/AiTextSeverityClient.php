<?php

namespace App\Services;

use App\Support\ReportedFatalityKeyword;
use Illuminate\Support\Facades\Http;

/**
 * Description-only triage with opt-in model inference. Scores are outputs, not calibrated
 * probabilities of clinical safety. No description or remote error is logged.
 *
 * Legacy Fatal rows remain supported, but new Critical model labels are stored
 * as Critical and unassessed reports have a null severity.
 */
class AiTextSeverityClient
{
    public function enabled(): bool
    {
        return (bool) config('services.ai_text.enabled', false);
    }

    public function analyzeForReport(string $description, ?string $preferredSeverity = null): array
    {
        $analysis = $this->analyze($description);
        $analysis['reported_fatality_flag'] = ReportedFatalityKeyword::matches($description);
        $humanSeverity = match (strtolower(trim($preferredSeverity ?? ''))) {
            'minor', 'low', 'medium' => 'Minor',
            'serious', 'high' => 'Serious',
            'fatal' => 'Fatal',
            'critical' => 'Critical',
            default => null,
        };

        if ($humanSeverity !== null && $analysis['source'] === 'manual_only') {
            return [
                ...$analysis,
                'severity' => $humanSeverity,
                'summary' => 'Responder-assigned severity: '.$humanSeverity.'. No automated severity inference was used.',
                'source' => 'human_responder',
                'status' => 'complete',
                'review_required' => false,
            ];
        }

        if ($humanSeverity !== null) {
            $advisory = $analysis['source'] === 'text_review'
                ? 'No usable text AI assessment was available.'
                : ($analysis['source'] === 'text_model_experimental' ? 'Experimental text AI' : 'Text AI')
                    .' suggests '.$analysis['severity']
                    .' from the description. Any retained model score is uncalibrated and describes the AI suggestion.';
            $analysis['severity'] = $humanSeverity;
            $analysis['source'] = 'text_human_override';
            $analysis['status'] = 'complete';
            $analysis['review_required'] = false;
            $analysis['summary'] = 'Responder-assigned severity: '.$humanSeverity.'. '.$advisory
                .' The recorded severity is the responder assessment.';
        }

        return $analysis;
    }

    public function analyze(string $description): array
    {
        if (strtoupper((string) config('services.ai_text.mode', 'MANUAL_ONLY')) === 'MANUAL_ONLY') {
            return $this->manualOnly();
        }

        if (! $this->enabled() || trim($description) === '') {
            return $this->needsReview();
        }

        try {
            $response = Http::acceptJson()
                ->connectTimeout(max(1, (int) config('services.ai_text.connect_timeout', 3)))
                ->timeout(max(1, (int) config('services.ai_text.timeout', 10)))
                ->post(rtrim((string) config('services.ai_text.url', 'http://127.0.0.1:8100'), '/').'/predict/text', [
                    'description' => $description,
                ]);

            if (! $response->successful()) {
                return $this->needsReview();
            }

            $payload = $response->json();

            if (! $this->validPayload($payload)) {
                return $this->needsReview();
            }

            if ($payload['deployment_status'] !== 'validated'
                && ! (bool) config('services.ai_text.allow_experimental', false)) {
                return $this->needsReview();
            }

            $rules = ($payload['assessment_method'] ?? 'trained_model') === 'text_rules';
            $modelScores = $rules ? ($payload['model_assessment']['probabilities'] ?? null) : $payload['probabilities'];
            $modelScores = is_array($modelScores) && count($modelScores) === 4 ? $modelScores : null;
            $ruleSummary = $payload['summary'];
            if ($rules && isset($payload['model_assessment'])) {
                $ruleSummary .= ' Classifier scores are from '.$payload['model_assessment']['model_name']
                    .' ('.$payload['model_assessment']['model_version'].').';
            }

            if ($payload['status'] === 'needs_review') {
                $review = $this->needsReview($payload['model_name'], $payload['model_version']);
                $review['probabilities'] = $modelScores;
                if ($rules) {
                    $review['summary'] = $ruleSummary;
                    $review['severity'] = null;
                }
                return $review;
            }

            $label = ucfirst($payload['severity']);
            $experimental = $payload['deployment_status'] === 'experimental';

            return [
                'severity' => $label,
                'confidence' => $rules ? null : (int) round($payload['confidence'] * 100),
                'summary' => $rules ? $ruleSummary : ($experimental ? 'Experimental text AI' : 'Text AI')
                    .' suggests '.$label.' based only on the submitted description. '
                    .'The model score is not a calibrated safety probability. Responder verification is required.',
                'source' => $rules ? 'text_rule_advisory' : ($experimental ? 'text_model_experimental' : 'text_model'),
                'status' => 'complete',
                'model_name' => $payload['model_name'],
                'model_version' => $payload['model_version'],
                'review_required' => true,
                'probabilities' => $modelScores,
            ];
        } catch (\Throwable) {
            // Receiving the emergency report must not depend on the text service.
            return $this->needsReview();
        }
    }

    public function needsReview(?string $modelName = null, ?string $modelVersion = null): array
    {
        return [
            'severity' => 'Serious',
            'confidence' => null,
            'summary' => 'Severity is unassessed; responder review required. The description-only AI did not provide a usable assessment. Serious is a provisional queue category, not an AI severity finding.',
            'source' => 'text_review',
            'status' => 'needs_review',
            'model_name' => $modelName,
            'model_version' => $modelVersion,
            'review_required' => true,
            'probabilities' => null,
        ];
    }

    private function manualOnly(): array
    {
        return [
            'severity' => null,
            'confidence' => null,
            'summary' => 'Severity is pending responder assessment. Automated severity inference is disabled by MANUAL_ONLY mode.',
            'source' => 'manual_only',
            'status' => 'needs_review',
            'model_name' => null,
            'model_version' => null,
            'review_required' => true,
            'probabilities' => null,
        ];
    }

    private function validPayload(mixed $payload): bool
    {
        if (! is_array($payload)) {
            return false;
        }

        foreach (['severity', 'predicted_label', 'confidence', 'probabilities', 'responder_review_required',
            'model_name', 'model_version', 'status', 'summary', 'deployment_status'] as $field) {
            if (! array_key_exists($field, $payload)) {
                return false;
            }
        }

        $labels = ['minor', 'serious', 'critical', 'needs_review'];
        $method = $payload['assessment_method'] ?? 'trained_model';
        if (! in_array($method, ['trained_model', 'text_rules'], true)) {
            return false;
        }

        if (! in_array($payload['severity'], ['minor', 'serious', 'critical', null], true)
            || ! in_array($payload['predicted_label'], $labels, true)
            || ! in_array($payload['status'], ['complete', 'needs_review'], true)
            || ! in_array($payload['deployment_status'], ['experimental', 'validated'], true)
            || ! is_bool($payload['responder_review_required'])
            || ! $this->boundedString($payload['model_name'], 255)
            || ! $this->boundedString($payload['model_version'], 255)
            || ! $this->boundedString($payload['summary'], 2000)) {
            return false;
        }

        if ($payload['confidence'] !== null && ! $this->score($payload['confidence'])) {
            return false;
        }

        if ($payload['probabilities'] !== null && ! ($payload['status'] === 'needs_review' && $payload['probabilities'] === [])) {
            if (! is_array($payload['probabilities']) || count($payload['probabilities']) !== count($labels)) {
                return false;
            }

            foreach ($labels as $label) {
                if (! array_key_exists($label, $payload['probabilities']) || ! $this->score($payload['probabilities'][$label])) {
                    return false;
                }
            }

            if (abs(array_sum($payload['probabilities']) - 1) > 0.01) {
                return false;
            }
        }

        if ($method === 'text_rules') {
            $cues = $payload['matched_cues'] ?? null;
            if ($payload['confidence'] !== null || $payload['probabilities'] !== null
                || $payload['deployment_status'] !== 'experimental' || ! $payload['responder_review_required']
                || ! is_array($cues) || count($cues) < 1 || count($cues) > 8) {
                return false;
            }
            foreach ($cues as $cue) {
                if (! $this->boundedString($cue, 80)) {
                    return false;
                }
            }
            if (array_key_exists('model_assessment', $payload)) {
                $model = $payload['model_assessment'];
                if (! is_array($model) || ($model['assessment_method'] ?? null) !== 'trained_model'
                    || ! $this->validPayload($model)) {
                    return false;
                }
            }
            return $payload['status'] === 'needs_review'
                ? $payload['severity'] === null && $payload['predicted_label'] === 'needs_review'
                : $payload['severity'] !== null && $payload['severity'] === $payload['predicted_label'];
        }

        if ($payload['status'] === 'needs_review') {
            return $payload['severity'] === null && $payload['responder_review_required'] === true;
        }

        return $payload['severity'] !== null
            && $payload['severity'] === $payload['predicted_label']
            && $payload['confidence'] !== null
            && $payload['probabilities'] !== null
            && abs($payload['probabilities'][$payload['predicted_label']] - $payload['confidence']) < 0.01
            && max($payload['probabilities']) <= $payload['confidence'] + 0.00001;
    }

    private function score(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= 0 && $value <= 1;
    }

    private function boundedString(mixed $value, int $max): bool
    {
        return is_string($value) && trim($value) !== '' && mb_strlen($value) <= $max;
    }
}
