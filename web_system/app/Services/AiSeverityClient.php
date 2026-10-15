<?php

namespace App\Services;

use App\Models\IncidentReport;
use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * Retained for compatibility with historical jobs and integrations only.
 * Photo-based severity is retired, regardless of old environment flags.
 */
class AiSeverityClient
{
    public function enabled(): bool
    {
        return false;
    }

    public function dispatchMode(): string
    {
        return 'disabled';
    }

    public function shouldAnalyze(IncidentReport $report): bool
    {
        return false;
    }

    public function analyzeStoredEvidence(IncidentReport $report): array
    {
        throw new RuntimeException('Photo-based severity analysis has been retired. Photos are supporting evidence only.');
    }

    public function analyzeUploadedEvidence(UploadedFile $file): array
    {
        throw new RuntimeException('Photo-based severity analysis has been retired. Photos are supporting evidence only.');
    }
}
