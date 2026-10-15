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

    public function modelName(): string
    {
        return (string) config('services.ai_severity.model_name', 'bontoc_southern_leyte_severity_baseline');
    }

    public function modelVersion(): string
    {
        return (string) config('services.ai_severity.model_version', '0.1.0');
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
