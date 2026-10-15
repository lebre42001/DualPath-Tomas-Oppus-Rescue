<?php

namespace App\Support;

use Illuminate\Support\Str;

class AiSeverityMapper
{
    public static function fallbackFromDescription(string $description, ?string $preferredSeverity = null): array
    {
        $severity = self::normalizeSeverity($preferredSeverity ?: self::guessSeverityFromDescription($description));

        return [
            'severity' => $severity,
            'confidence' => self::confidenceScore($severity),
            'summary' => self::buildDescriptionSummary($severity),
            'source' => 'description_fallback',
            'status' => 'complete',
            'model_name' => 'description_rules',
            'model_version' => 'legacy',
            'review_required' => false,
            'probabilities' => self::probabilitiesForSeverity($severity),
            'photo_relevance_label' => null,
            'photo_relevance_confidence' => null,
            'photo_relevance_probabilities' => null,
            'photo_relevance_overridden' => false,
        ];
    }

    public static function fromAiServiceResponse(array $payload, string $modelName, string $modelVersion): array
    {
        $severity = self::normalizeSeverity((string) ($payload['severity'] ?? 'Minor'));
        $confidence = self::normalizeConfidence($payload['confidence'] ?? self::confidenceScore($severity));
        $reviewRequired = (bool) ($payload['responder_review_required'] ?? false);
        $probabilities = self::normalizeProbabilities($payload['probabilities'] ?? [], $severity, $confidence);
        $photoRelevance = self::photoRelevanceFromPayload($payload);

        return [
            'severity' => $severity,
            'confidence' => $confidence,
            'summary' => self::buildImageSummary($severity, $confidence, $reviewRequired),
            'source' => 'python_model',
            'status' => 'complete',
            'model_name' => $modelName,
            'model_version' => $modelVersion,
            'review_required' => $reviewRequired,
            'probabilities' => $probabilities,
            'photo_relevance_label' => $photoRelevance['photo_relevance_label'],
            'photo_relevance_confidence' => $photoRelevance['photo_relevance_confidence'],
            'photo_relevance_probabilities' => $photoRelevance['photo_relevance_probabilities'],
            'photo_relevance_overridden' => $photoRelevance['photo_relevance_overridden'],
        ];
    }

    public static function normalizeSeverity(string $severity): string
    {
        return match (Str::lower(trim($severity))) {
            'fatal' => 'Fatal',
            'critical' => 'Critical',
            'serious', 'high' => 'Serious',
            default => 'Minor',
        };
    }

    public static function photoRelevanceFromPayload(array $payload): array
    {
        return [
            'photo_relevance_label' => self::normalizePhotoRelevanceLabel($payload['photo_relevance_label'] ?? null),
            'photo_relevance_confidence' => array_key_exists('photo_relevance_confidence', $payload)
                ? self::normalizeConfidence($payload['photo_relevance_confidence'])
                : null,
            'photo_relevance_probabilities' => self::normalizePhotoRelevanceProbabilities($payload['photo_relevance_probabilities'] ?? null),
            'photo_relevance_overridden' => (bool) ($payload['photo_relevance_overridden'] ?? false),
        ];
    }

    private static function guessSeverityFromDescription(string $description): string
    {
        $normalized = Str::lower($description);

        if (Str::contains($normalized, ['fatal', 'dead', 'deceased', 'trapped', 'unconscious', 'not breathing'])) {
            return 'Fatal';
        }

        if (Str::contains($normalized, ['collision', 'crash', 'bleeding', 'injured', 'serious', 'fire', 'rollover', 'multiple vehicle'])) {
            return 'Serious';
        }

        return 'Minor';
    }

    private static function buildDescriptionSummary(string $severity): string
    {
        $label = match ($severity) {
            'Fatal' => 'life-threatening indicators',
            'Serious' => 'major impact or urgent injury indicators',
            default => 'limited immediate threat indicators',
        };

        return 'AI triage marked this report as '.$severity.' based on '.$label.' in the submitted description.';
    }

    private static function buildImageSummary(string $severity, int $confidence, bool $reviewRequired): string
    {
        $summary = 'AI image analysis predicted '.$severity.' severity from the uploaded photo evidence with '.$confidence.'% confidence.';

        if ($reviewRequired) {
            return $summary.' Admin review is recommended before relying on this result.';
        }

        return $summary.' The model confidence is high enough for normal admin triage support.';
    }

    private static function normalizeConfidence(mixed $confidence): int
    {
        if (is_numeric($confidence)) {
            $value = (float) $confidence;

            if ($value <= 1) {
                return (int) round($value * 100);
            }

            return max(0, min(100, (int) round($value)));
        }

        return 0;
    }

    private static function normalizePhotoRelevanceLabel(mixed $label): ?string
    {
        if (! is_scalar($label)) {
            return null;
        }

        $normalized = Str::lower(trim((string) $label));

        if ($normalized === '') {
            return null;
        }

        return substr($normalized, 0, 40);
    }

    private static function normalizePhotoRelevanceProbabilities(mixed $probabilities): ?array
    {
        if (! is_array($probabilities) || $probabilities === []) {
            return null;
        }

        $normalized = [];

        foreach (['related', 'unrelated'] as $label) {
            if (! array_key_exists($label, $probabilities)) {
                continue;
            }

            $normalized[$label] = round(max(0, min(1, (float) $probabilities[$label])), 6);
        }

        return $normalized === [] ? null : $normalized;
    }

    private static function confidenceScore(string $severity): int
    {
        return match ($severity) {
            'Fatal' => 95,
            'Serious' => 84,
            default => 72,
        };
    }

    private static function probabilitiesForSeverity(string $severity): array
    {
        return match ($severity) {
            'Fatal' => ['minor' => 0.03, 'serious' => 0.10, 'fatal' => 0.87],
            'Serious' => ['minor' => 0.12, 'serious' => 0.78, 'fatal' => 0.10],
            default => ['minor' => 0.78, 'serious' => 0.18, 'fatal' => 0.04],
        };
    }

    private static function normalizeProbabilities(array $probabilities, string $severity, int $confidence): array
    {
        if ($probabilities === []) {
            return self::probabilitiesForSeverity($severity);
        }

        $normalized = [];
        foreach (['minor', 'serious', 'fatal'] as $label) {
            $value = $probabilities[$label] ?? 0;
            $normalized[$label] = round(max(0, min(1, (float) $value)), 6);
        }

        if (array_sum($normalized) == 0.0) {
            return self::probabilitiesForSeverity($severity);
        }

        return $normalized;
    }

    public static function confidenceLabel(?int $confidence): string
    {
        return ($confidence ?? 0).'%';
    }

    public static function humanizeErrorMessage(?string $message): ?string
    {
        if (blank($message)) {
            return null;
        }

        $normalized = Str::lower((string) $message);

        if (Str::contains($normalized, [
            'curl error 7',
            'failed to connect',
            'couldn\'t connect to server',
            'connection refused',
            'service unavailable',
            'connection reset by peer',
        ])) {
            return 'The AI image analysis service was temporarily unavailable, so the system used description-based severity fallback for this report.';
        }

        if (Str::contains($normalized, [
            'timed out',
            'timeout',
            'operation timed out',
        ])) {
            return 'The AI image analysis service took too long to respond, so the system used description-based severity fallback for this report.';
        }

        if (Str::contains($normalized, [
            'stored evidence file was not found',
            'not found for ai analysis',
            'file was not found',
        ])) {
            return 'The uploaded photo evidence could not be reopened for AI review, so the system used description-based severity fallback for this report.';
        }

        if (Str::contains($normalized, [
            'ai relevance gate rejected',
            'not accepted as evidence',
            'dummy, ui, selfie-only, or unrelated images',
            'real accident or emergency scene photo',
        ])) {
            return 'The uploaded photo was not recognized as a real accident or emergency scene, so the system did not rely on AI image severity for this report.';
        }

        if (Str::contains($normalized, [
            'invalid response payload',
            'unexpected response',
            'unprocessable entity',
            'bad gateway',
            'internal server error',
        ])) {
            return 'The AI image analysis response could not be completed cleanly, so the system used description-based severity fallback for this report.';
        }

        return 'The AI image analysis could not be completed for this report, so the system used description-based severity fallback.';
    }
}
