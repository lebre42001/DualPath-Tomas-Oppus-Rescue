<?php

namespace App\Support;

use App\Models\IncidentReport;

class ReportAssessmentSummary
{
    /** Report-facing copy deliberately excludes free-form service diagnostics/model metadata. */
    public static function forReport(IncidentReport $report): string
    {
        if (in_array($report->ai_source, ['human_responder', 'manual_override', 'text_human_override'], true)) {
            return $report->operational_severity === null
                ? 'An administrator reviewed this report and requested more information.'
                : 'An administrator assessed the submitted incident description.';
        }
        if ($report->ai_suggested_severity) {
            return 'Text analysis suggests '.$report->ai_suggested_severity.'. Admin confirmation is required.';
        }
        if ($report->ai_top_category) {
            return 'Text analysis is available. An administrator must confirm or correct the severity after reviewing the report.';
        }

        return 'No confirmed automated assessment is available. Admin review is required.';
    }
}
