<?php

namespace App\Jobs;

use App\Models\IncidentReport;
use App\Services\AiTextSeverityClient;
use App\Support\IncidentFeedBroadcaster;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;

/** Runs after the HTTP response under PHP-FPM; no separate queue worker needed. */
class AnalyzeLoraDescription
{
    use Dispatchable;

    public function __construct(public int $reportId) {}

    public function handle(AiTextSeverityClient $client): void
    {
        $report = IncidentReport::query()->find($this->reportId);
        if (! $this->awaitingAnalysis($report)) {
            return;
        }

        $description = (string) $report->description;
        // Exactly the description-only path used for civilian online reports.
        $analysis = $client->analyzeForReport($description);
        $updated = DB::transaction(function () use ($description, $analysis): ?IncidentReport {
            $report = IncidentReport::query()->lockForUpdate()->find($this->reportId);
            // A slow analysis must never replace an admin decision, a manual
            // re-analysis, or an edited/deleted report.
            if (! $this->awaitingAnalysis($report) || (string) $report->description !== $description) {
                return null;
            }

            $report->update([
                'severity' => $analysis['severity'],
                'reported_fatality_flag' => $analysis['reported_fatality_flag'],
                'ai_summary' => $analysis['summary'],
                'ai_confidence' => $analysis['confidence'],
                'ai_source' => $analysis['source'],
                'ai_status' => $analysis['status'],
                'ai_model_name' => $analysis['model_name'],
                'ai_model_version' => $analysis['model_version'],
                'ai_review_required' => true,
                'ai_probabilities' => $analysis['probabilities'],
                'ai_processed_at' => now(),
                'ai_error_message' => null,
                'coordination_log' => [
                    ...($report->coordination_log ?? []),
                    [
                        'label' => 'Text AI suggestion requested',
                        'actor_name' => 'System',
                        'actor_role' => 'System',
                        'trigger' => 'lora_description_received',
                        'status' => $report->status,
                        'description_sha256' => hash('sha256', $description),
                        'model_name' => $analysis['model_name'],
                        'model_version' => $analysis['model_version'],
                        'suggested_severity' => $analysis['severity'],
                        'occurred_at' => now()->toIso8601String(),
                    ],
                ],
            ]);

            return $report;
        });

        if ($updated) {
            // An assessment update is not a new report/alarm arrival.
            IncidentFeedBroadcaster::dispatch($updated, 'ai_suggestion');
        }
    }

    private function awaitingAnalysis(?IncidentReport $report): bool
    {
        return $report && $report->transmission_type === 'lora'
            && $report->ai_source === 'text_review' && $report->ai_status === 'pending';
    }
}
