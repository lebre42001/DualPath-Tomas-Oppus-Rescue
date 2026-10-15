<?php

namespace App\Support;

use App\Events\IncidentFeedUpdated;
use App\Models\IncidentReport;
use Illuminate\Support\Facades\Log;
use Throwable;

class IncidentFeedBroadcaster
{
    public static function dispatch(IncidentReport $report, string $action = 'created'): void
    {
        try {
            event(new IncidentFeedUpdated($report->fresh('assignedResponder'), $action));
        } catch (Throwable $exception) {
            Log::warning('Incident feed broadcast skipped because realtime sync is unavailable.', [
                'report_id' => $report->id,
                'report_code' => $report->report_code,
                'action' => $action,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
