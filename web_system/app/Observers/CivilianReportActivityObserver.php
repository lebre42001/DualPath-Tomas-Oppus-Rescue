<?php

namespace App\Observers;

use App\Models\IncidentReport;
use App\Models\IncidentReportAudit;
use App\Services\CivilianActivityRecorder;

class CivilianReportActivityObserver
{
    public function created(IncidentReport|IncidentReportAudit $record): void
    {
        $recorder = app(CivilianActivityRecorder::class);
        if ($record instanceof IncidentReport) {
            $recorder->submitted($record);
        } else {
            $recorder->reportAction($record);
        }
    }
}
