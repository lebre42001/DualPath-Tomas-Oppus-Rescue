<?php

namespace App\Observers;

use App\Models\IncidentReportAudit;
use App\Services\AdminActivityRecorder;

class AdminReportActivityObserver
{
    public function created(IncidentReportAudit $audit): void
    {
        app(AdminActivityRecorder::class)->reportAction($audit);
    }
}
