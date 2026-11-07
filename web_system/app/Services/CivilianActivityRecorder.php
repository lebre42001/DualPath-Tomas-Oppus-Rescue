<?php

namespace App\Services;

use App\Models\CivilianActivity;
use App\Models\IncidentReport;
use App\Models\IncidentReportAudit;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

class CivilianActivityRecorder
{
    public function record(User $user, string $kind, string $title, string $message, array $extra = []): ?CivilianActivity
    {
        // Older migrations import report audits before this feature's table exists.
        if (! $user->isCivilian() || ! Schema::hasTable('civilian_activities')) {
            return null;
        }

        $attributes = [
            'user_id' => $user->id, 'kind' => $kind, 'title' => $title,
            'message' => $message, 'occurred_at' => now(), 'notifiable' => false,
            ...$extra,
        ];

        return isset($attributes['source_key'])
            ? CivilianActivity::query()->firstOrCreate(['source_key' => $attributes['source_key']], $attributes)
            : CivilianActivity::query()->create($attributes);
    }

    public function submitted(IncidentReport $report): void
    {
        if ($report->reporter && $report->created_at) {
            $this->record($report->reporter, 'report_submitted', 'Report received by the server',
                'Your emergency report '.$report->report_code.' was received.', [
                    'incident_report_id' => $report->id, 'report_code' => $report->report_code,
                    'source_key' => hash('sha256', 'civilian:submitted:'.$report->id),
                    'occurred_at' => $report->created_at,
                ]);
        }
    }

    public function reportAction(IncidentReportAudit $audit, bool $historical = false): void
    {
        $report = $audit->report;
        if (! $report?->reporter || ! $audit->occurred_at) {
            return;
        }

        [$kind, $title] = match ($audit->action) {
            'deletion' => ['report_deleted', 'Report removed by an administrator'],
            'severity_review' => ['report_review', 'Report severity reviewed'],
            'ai_analysis' => ['report_review', 'Description review requested'],
            default => ['report_status', 'Report update from an administrator'],
        };
        $status = match ($audit->status_after) {
            'received' => 'received', 'acknowledged' => 'acknowledged', 'dispatched' => 'response started',
            'responding' => 'responded', 'resolved' => 'resolved', 'rejected' => 'cancelled',
            'deleted' => 'removed', default => null,
        };
        $actor = $audit->actor_name ?: 'An administrator';
        $message = match ($audit->action) {
            'severity_review' => $actor.' reviewed the severity of report '.$audit->report_code.'.',
            'ai_analysis' => $actor.' requested a description assessment for report '.$audit->report_code.'.',
            'deletion' => $actor.' removed report '.$audit->report_code.'.',
            default => $actor.' updated response coordination for report '.$audit->report_code.'.',
        };
        if ($status && $audit->status_before !== $audit->status_after) {
            $title = 'Report '.$status;
            $message = match ($audit->status_after) {
                'acknowledged' => $actor.' acknowledged receiving report '.$audit->report_code.'. Open the report to follow its status.',
                'responding' => $actor.' responded to report '.$audit->report_code.'. Open the report to read the response.',
                default => $actor.' changed report '.$audit->report_code.' to '.$status.'.',
            };
        }
        $assignment = $audit->context['assigned_responder_name'] ?? null;
        $assignmentChanged = $audit->context['assignment_changed'] ?? ($assignment !== ($audit->context['previous_assigned_responder_name'] ?? null));
        if (is_string($assignment) && $assignment !== '' && $assignmentChanged) {
            $message .= ' Assigned responder: '.$assignment.'.';
        }
        $notifiable = $audit->action !== 'coordination'
            || $audit->status_before !== $audit->status_after
            || ($audit->context['response_changed'] ?? false)
            || $assignmentChanged;

        // Deliberately project only the action/status, never internal notes, AI
        // inputs, other accounts, contact information or private audit context.
        $this->record($report->reporter, $kind, $title, $message, [
            'incident_report_id' => $report->id, 'report_code' => $audit->report_code,
            'source_key' => hash('sha256', 'civilian:audit:'.$audit->id),
            'occurred_at' => $audit->occurred_at, 'notifiable' => $notifiable,
            'read_at' => $historical || $audit->imported ? now() : null,
        ]);
    }

    public function backfill(): void
    {
        IncidentReport::query()->with('reporter')->whereHas('reporter', fn ($query) => $query->where('role', 'civilian'))
            ->chunkById(100, function ($reports): void {
                foreach ($reports as $report) {
                    $this->submitted($report);
                }
            });
        IncidentReportAudit::query()->with('report.reporter')->whereNotNull('occurred_at')->whereHas('report.reporter', fn ($query) => $query->where('role', 'civilian'))
            ->chunkById(100, function ($audits): void {
                foreach ($audits as $audit) {
                    $this->reportAction($audit, true);
                }
            });
    }
}
