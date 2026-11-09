<?php

namespace App\Services;

use App\Models\AiTrainingFeedback;
use Illuminate\Support\Facades\Schema;

/** A deliberately small admin projection; never returns paths, notes, or report text. */
class AiLearningStatus
{
    public function summary(): array
    {
        $captured = null;
        $approved = null;
        try {
            if (Schema::hasTable('ai_training_feedback')) {
                $captured = AiTrainingFeedback::query()->count();
                $approved = AiTrainingFeedback::query()->where('approved_for_training', true)
                    ->whereNull('superseded_at')->whereNotNull('incident_report_id')
                    ->whereNotNull('reviewer_id')->count();
            }
        } catch (\Throwable) {
            // Missing migrations/storage must not prevent the isolated testing form.
        }

        $latest = $this->latest();
        $status = $latest['status'] ?? 'waiting_for_reviewed_examples';
        [$heading, $message] = match ($status) {
            'waiting_for_reviewed_examples' => ['Waiting for reviewed examples', 'Approved descriptions are prepared automatically. Candidate training waits for enough new reviewed examples.'],
            'prepared', 'prepared_training_not_requested' => ['Reviewed examples prepared', 'Data preparation completed. Candidate training has not been requested for this run.'],
            'candidate_trained_waiting_for_independent_holdout', 'no_new_snapshot' => ['Candidate pending independent testing', 'A candidate has been prepared. A separately reviewed independent test set and evaluation are still required before release review.'],
            'candidate_ready_for_release_review' => ['Candidate requires release review', 'The recorded candidate run is ready for human release review. This does not establish approval or accuracy of the active model.'],
            'candidate_failed_acceptance', 'candidate_blocked', 'waiting_for_changed_snapshot' => ['Candidate requires review', 'The candidate did not complete its requirements. Review data coverage and independent evaluation before trying again.'],
            'already_running' => ['Learning workflow is running', 'Another candidate workflow is already in progress.'],
            'stopping' => ['Learning workflow is stopping', 'The current worker is stopping; the active model is unchanged.'],
            default => ['Learning status unavailable', 'A readable learning status is not available yet. The active model is unchanged.'],
        };
        $target = config('ai_learning.target_accuracy', 0.8);
        $target = is_numeric($target) && $target >= 0 && $target <= 1 ? (float) $target : 0.8;

        return [
            'training_enabled' => (bool) config('ai_learning.enabled', false),
            'captured' => $captured,
            'approved' => $approved,
            'minimum_new' => max(1, (int) config('ai_learning.min_new', 50)),
            'target_percent' => round($target * 100, 1),
            'eligible' => $this->count($latest['conversion']['eligible'] ?? null),
            'new_examples' => $this->count($latest['new_examples'] ?? null),
            'heading' => $heading,
            'message' => $message,
        ];
    }

    protected function statusFile(): string
    {
        return storage_path('app/private/ai-learning/candidate-state/latest_status.json');
    }

    private function latest(): array
    {
        $handle = @fopen($this->statusFile(), 'rb');
        if ($handle === false) {
            return [];
        }
        try {
            // Read at most 64 KiB + one byte, even if the file changes concurrently.
            $body = fread($handle, 65537);
        } finally {
            fclose($handle);
        }
        if (! is_string($body) || strlen($body) > 65536) {
            return ['status' => 'unavailable'];
        }
        try {
            $parsed = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['status' => 'unavailable'];
        }

        if (! is_array($parsed) || ($parsed['schema_version'] ?? null) !== 1) {
            return ['status' => 'unavailable'];
        }
        $status = is_string($parsed['status'] ?? null) ? $parsed['status'] : 'unavailable';
        if ($status === 'candidate_ready_for_release_review'
            && ($parsed['acceptance_gate']['meets_configured_targets'] ?? null) !== true) {
            $status = 'candidate_blocked';
        }

        return [
            'status' => $status,
            'new_examples' => $parsed['new_examples'] ?? null,
            'conversion' => ['eligible' => is_array($parsed['conversion'] ?? null) ? ($parsed['conversion']['eligible'] ?? null) : null],
        ];
    }

    private function count(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 && $value <= 100000000 ? $value : null;
    }
}
