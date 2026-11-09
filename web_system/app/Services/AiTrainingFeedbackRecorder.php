<?php

namespace App\Services;

use App\Models\AiTrainingFeedback;
use App\Models\IncidentReport;
use App\Models\User;
use App\Support\IncidentCategory;

class AiTrainingFeedbackRecorder
{
    /** Called inside the report's row-lock transaction, before its AI fields change. */
    public function record(IncidentReport $report, User $reviewer, array $review, bool $approved, ?string $language = null): AiTrainingFeedback
    {
        $snapshot = [
            'incident_report_id' => $report->id,
            'reviewer_id' => $reviewer->id,
            'description_sha256' => hash('sha256', (string) $report->description),
            'description_snapshot' => (string) $report->description,
            'incident_type' => IncidentCategory::normalize($report->incident_type),
            'reviewed_label' => $review['reviewed_label'],
            'review_scope' => 'description_only',
            'review_method' => $review['review_method'],
            'review_reason' => $review['review_notes'],
            'original_ai_snapshot' => $review['ai_suggestion'],
            'approved_for_training' => $approved,
            'language' => $approved ? $language : null,
        ];
        $fingerprint = hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
        $current = AiTrainingFeedback::query()->where('incident_report_id', $report->id)
            ->whereNull('superseded_at')->first();
        if ($current?->fingerprint === $fingerprint) {
            return $current; // Browser retries must not create duplicate examples.
        }

        AiTrainingFeedback::query()->where('incident_report_id', $report->id)
            ->whereNull('superseded_at')->update(['superseded_at' => now()]);

        // Repeating an older decision is a new review, not reactivation of its audit row.
        if (AiTrainingFeedback::query()->where('fingerprint', $fingerprint)->exists()) {
            $fingerprint = hash('sha256', $fingerprint.'|'.now()->format('U.u').'|'.bin2hex(random_bytes(8)));
        }

        return AiTrainingFeedback::query()->create([
            ...$snapshot, 'fingerprint' => $fingerprint, 'reviewed_at' => now(),
        ]);
    }

    public function supersede(IncidentReport $report): void
    {
        AiTrainingFeedback::query()->where('incident_report_id', $report->id)
            ->whereNull('superseded_at')->update(['superseded_at' => now()]);
    }
}
