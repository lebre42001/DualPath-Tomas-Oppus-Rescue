<?php

namespace App\Support;

use App\Models\IncidentReport;
use Illuminate\Support\Carbon;
use Throwable;

class CivilianSeverityAssessment
{
    private const HUMAN_SOURCES = ['human_responder', 'text_human_override', 'manual_override'];
    private const AI_SOURCES = ['text_model', 'text_model_experimental', 'text_rule_advisory'];

    /** A deliberately small public projection, never the raw AI or review log. */
    public static function forReport(IncidentReport $report): array
    {
        $result = [
            'state' => 'pending', 'heading' => 'Severity under review', 'severity' => null,
            'message' => 'Your report was received. A reviewer has not yet confirmed its severity. Response status is shown separately.',
            'reviewedAt' => null, 'reviewedAtLabel' => null, 'reviewer' => null,
            'outcome' => null, 'explanation' => null, 'originalAi' => null,
            'aiMessage' => 'AI result unavailable. This is a human assessment.',
        ];
        $review = self::validReview($report);
        if ($review === null) {
            return $result;
        }
        if (self::label($review['reviewed_severity']) === null) {
            $publicNote = is_string($review['public_note'] ?? null) ? trim($review['public_note']) : '';
            return [...$result, 'state' => 'needs_information', 'heading' => 'More information needed',
                'message' => 'A reviewer needs more information before confirming severity. '
                    .($publicNote !== '' ? $publicNote : 'Check the response message for instructions.')];
        }
        if ($report->ai_review_required || $report->ai_status !== 'complete') {
            return $result;
        }

        $severity = self::label($review['reviewed_severity']);
        $original = $review['ai_suggestion'] ?? null;
        $ai = is_array($original) && in_array($original['source'] ?? null, self::AI_SOURCES, true)
            ? self::label($original['label'] ?? null) : null;
        $outcome = $ai === null ? 'Human assessment' : ($ai === $severity ? 'Confirmed AI suggestion' : 'Corrected AI suggestion');
        $explanation = match ($outcome) {
            'Confirmed AI suggestion' => 'The reviewer confirmed the original automated suggestion after reviewing your submitted report.',
            'Corrected AI suggestion' => 'The reviewer assigned a different severity after reviewing your submitted report.',
            default => 'The reviewer assessed your submitted report without an available AI severity suggestion.',
        };
        // Internal review_notes are never made public. Only an explicitly
        // civilian-facing note, entered separately by the reviewer, may replace it.
        $publicNote = $review['public_note'] ?? null;
        if (is_string($publicNote) && trim($publicNote) !== '') {
            $explanation = trim($publicNote);
        }
        $time = Carbon::parse($review['occurred_at']);

        return [...$result,
            'state' => 'reviewed', 'heading' => 'Human-reviewed Severity', 'severity' => $severity,
            'message' => 'A reviewer assessed your submitted report. The assessment may change if more information becomes available.',
            'reviewedAt' => $time->toIso8601String(),
            'reviewedAtLabel' => $time->timezone('Asia/Manila')->format('M d, Y h:i A'),
            'reviewer' => is_string($review['actor_name'] ?? null) ? $review['actor_name'] : null,
            'outcome' => $outcome, 'explanation' => $explanation, 'originalAi' => $ai,
            'aiMessage' => $ai === null ? $result['aiMessage']
                : 'The original AI suggestion is advisory. The human-reviewed severity above is the current assessment; neither confirms that assistance has arrived.',
        ];
    }

    /** Internal audit snapshot for review writers only; NEVER serialize to a civilian. */
    public static function aiSnapshotForReview(IncidentReport $report): ?array
    {
        $current = ! IncidentReport::manualOnlyMode() && $report->ai_status === 'complete'
            && in_array($report->ai_source, self::AI_SOURCES, true) && self::label($report->severity) !== null;
        $abstained = ! IncidentReport::manualOnlyMode() && $report->ai_source === 'text_review'
            && $report->ai_status === 'needs_review' && filled($report->ai_model_name);
        if ($current || $abstained) {
            return [
                'label' => $abstained ? 'needs_review' : strtolower(self::label($report->severity)),
                'model_name' => $report->ai_model_name, 'model_version' => $report->ai_model_version,
                'model_score' => $report->ai_confidence, 'model_probabilities' => $report->ai_probabilities,
                'source' => $report->ai_source, 'summary' => $report->ai_summary,
                'processed_at' => $report->ai_processed_at?->toIso8601String(),
            ];
        }

        $review = self::validReview($report);
        return is_array($review['ai_suggestion'] ?? null) ? $review['ai_suggestion'] : null;
    }

    private static function validReview(IncidentReport $report): ?array
    {
        if (! in_array($report->ai_source, self::HUMAN_SOURCES, true)) {
            return null;
        }
        foreach (array_reverse($report->coordination_log ?? []) as $entry) {
            if (! is_array($entry) || ! array_key_exists('reviewed_severity', $entry)) {
                continue;
            }
            // The latest severity review supersedes older decisions. Requiring
            // provenance prevents acknowledgements, stale text and legacy AI
            // categories from being presented as a current human assessment.
            if (! in_array($entry['review_scope'] ?? null, ['description_only', 'operational'], true)
                || ($entry['description_sha256'] ?? null) !== hash('sha256', (string) $report->description)
                || strtolower((string) ($entry['actor_role'] ?? '')) !== 'admin'
                || ! is_string($entry['occurred_at'] ?? null)
                || ! preg_match('/^\d{4}-\d{2}-\d{2}T/', $entry['occurred_at'])) {
                return null;
            }
            $stored = self::label($report->severity);
            $reviewed = self::label($entry['reviewed_severity']);
            if ($stored !== $reviewed || ($reviewed === null
                && ($report->severity !== null || ! in_array(strtolower((string) $entry['reviewed_severity']), ['needs review', 'needs_review'], true)))) {
                return null;
            }
            if (isset($entry['reviewed_label']) && self::label($entry['reviewed_label']) !== $reviewed) {
                return null;
            }
            try {
                $at = Carbon::parse($entry['occurred_at']);
                if ($at->isAfter(now()->addMinutes(5))) {
                    return null;
                }
            } catch (Throwable) {
                return null;
            }

            return $entry;
        }

        return null;
    }

    private static function label(mixed $value): ?string
    {
        return match (is_string($value) ? strtolower(trim($value)) : '') {
            'minor' => 'Minor', 'serious' => 'Serious', 'critical', 'fatal' => 'Critical', default => null,
        };
    }
}
