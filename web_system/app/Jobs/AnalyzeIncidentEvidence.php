<?php

namespace App\Jobs;

use App\Services\AiSeverityClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Compatibility handler for jobs queued before description-only triage.
 * Keep the class and constructor so old queue payloads can be consumed safely.
 */
class AnalyzeIncidentEvidence implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(public int $reportId)
    {
        $this->onQueue('ai-severity');
    }

    public function handle(AiSeverityClient $client): void
    {
        // Intentionally do nothing: no image request, fallback, record mutation,
        // evidence deletion, or broadcast, including for historical pending jobs.
    }
}
