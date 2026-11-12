<?php

namespace App\Observers;

use App\Models\IncidentReport;
use App\Services\AiTrainingFeedbackRecorder;
use App\Services\IncidentReportAuditRecorder;
use App\Support\IncidentReportIngestor;
use Illuminate\Support\Facades\Auth;

class IncidentReportObserver
{
    public function saved(IncidentReport $report): void
    {
        if ($report->wasChanged('coordination_log') || ($report->wasRecentlyCreated && $report->coordination_log)) {
            app(IncidentReportAuditRecorder::class)->sync($report);
        }
    }

    public function updated(IncidentReport $report): void
    {
        $latestEntry = collect($report->coordination_log ?? [])->last();
        $operationalReview = $report->wasChanged('coordination_log')
            && is_array($latestEntry)
            && ($latestEntry['review_scope'] ?? null) === 'operational'
            && array_key_exists('reviewed_severity', $latestEntry);
        // A later scene/operational assessment cannot silently retain an earlier
        // description-only training approval. A fresh text review is required.
        if ($report->wasChanged(['description', 'incident_type']) || $operationalReview) {
            app(AiTrainingFeedbackRecorder::class)->supersede($report);
        }
    }

    public function deleting(IncidentReport $report): void
    {
        app(IncidentReportIngestor::class)->rememberLoraDeletion($report);
        app(AiTrainingFeedbackRecorder::class)->supersede($report);
        if ($actor = Auth::user()) {
            if ($actor->is_admin) {
                app(IncidentReportAuditRecorder::class)->deleted($report, $actor);
            }
        }
    }
}
