<?php

namespace App\Services;

use App\Models\IncidentReport;
use App\Models\IncidentReportAudit;
use App\Models\User;
use Illuminate\Support\Carbon;

class IncidentReportAuditRecorder
{
    /** Copy recorded admin actions; submissions and gateway packets are not admin actions. */
    public function sync(IncidentReport $report, bool $imported = false): void
    {
        $previousLog = json_decode($report->getRawOriginal('coordination_log') ?? '[]', true) ?: [];
        $previousLog = is_array($previousLog) ? $previousLog : [];
        $beforeCategory = (new IncidentReport)->setRawAttributes($report->getRawOriginal(), true)->current_severity_category;
        foreach (is_array($report->coordination_log) ? $report->coordination_log : [] as $index => $entry) {
            if (! is_array($entry) || ! in_array(strtolower((string) ($entry['actor_role'] ?? '')), ['admin', 'responder'], true)) {
                continue;
            }
            $historical = $imported || array_key_exists($index, $previousLog);
            $label = (string) ($entry['label'] ?? 'Updated report');
            $action = isset($entry['reviewed_severity']) ? 'severity_review'
                : ($label === 'Text AI suggestion requested' ? 'ai_analysis' : 'coordination');
            $time = $entry['occurred_at'] ?? $entry['created_at'] ?? $entry['at'] ?? null;
            try {
                $occurredAt = $time ? Carbon::parse($time)->utc() : null;
            } catch (\Throwable) {
                $occurredAt = null;
            }
            // Imported entries retain only recorded historical values, never inferred actors/times.
            $context = array_intersect_key($entry, array_flip([
                'previous_status', 'previous_severity', 'previous_stored_severity',
                'reviewed_severity', 'review_method', 'review_notes', 'assigned_responder_name',
                'previous_assigned_responder_name', 'model_name', 'model_version', 'suggested_severity',
            ]));
            IncidentReportAudit::query()->firstOrCreate([
                'entry_key' => hash('sha256', $report->id.':'.$index.':'.json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            ], [
                ...$this->reportSnapshot($report),
                'actor_id' => is_numeric($entry['actor_id'] ?? null) ? (int) $entry['actor_id'] : null,
                'actor_name' => mb_substr((string) ($entry['actor_name'] ?? 'Unknown admin'), 0, 255),
                'actor_role' => (string) ($entry['actor_role'] ?? 'Admin'),
                'action' => $action,
                'label' => mb_substr($label, 0, 255),
                'status_before' => $entry['previous_status'] ?? (! $historical ? $report->getOriginal('status') : null),
                'status_after' => $entry['status'] ?? null,
                'severity_before' => ! $historical ? $beforeCategory : ($entry['previous_severity'] ?? null),
                'severity_after' => $entry['reviewed_severity'] ?? $entry['suggested_severity'] ?? (! $historical ? $report->current_severity_category : null),
                'notes' => $entry['review_notes'] ?? $entry['response_notes'] ?? null,
                'context' => $context,
                'occurred_at' => $occurredAt,
                'imported' => $historical,
            ]);
        }
    }

    public function deleted(IncidentReport $report, User $actor): void
    {
        $this->sync($report, true);
        IncidentReportAudit::query()->create([
            ...$this->reportSnapshot($report),
            'entry_key' => hash('sha256', 'deleted:'.$report->id),
            'actor_id' => $actor->id, 'actor_name' => $actor->name, 'actor_role' => 'Admin',
            'action' => 'deletion', 'label' => 'Deleted report',
            'status_before' => $report->status, 'status_after' => 'deleted',
            'severity_before' => $report->current_severity_category, 'severity_after' => null,
            'occurred_at' => now(), 'imported' => false,
        ]);
    }

    private function reportSnapshot(IncidentReport $report): array
    {
        return [
            'incident_report_id' => $report->id,
            'report_code' => $report->report_code,
            'incident_type' => $report->incident_type,
            'transmission_type' => strtolower(trim((string) $report->transmission_type)),
            'node_id' => $report->lora_sender_id,
        ];
    }
}
