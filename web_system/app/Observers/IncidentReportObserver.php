<?php

namespace App\Observers;

use App\Models\IncidentReport;
use App\Services\IncidentReportAuditRecorder;
use Illuminate\Support\Facades\Auth;

class IncidentReportObserver
{
    public function saved(IncidentReport $report): void
    {
        if ($report->wasChanged('coordination_log') || ($report->wasRecentlyCreated && $report->coordination_log)) {
            app(IncidentReportAuditRecorder::class)->sync($report);
        }
    }

    public function deleting(IncidentReport $report): void
    {
        if ($actor = Auth::user()) {
            if ($actor->is_admin) {
                app(IncidentReportAuditRecorder::class)->deleted($report, $actor);
            }
        }
    }
}
