<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\IncidentReport;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AdminDashboardController extends Controller
{
    public function __invoke(): View
    {
        $activeStatuses = ['received', 'acknowledged', 'dispatched', 'responding'];
        $assessedReports = IncidentReport::query()->operationallyAssessed();
        $recentReports = IncidentReport::query()
            ->with(['assignedResponder', 'reporter'])
            ->latest('status_updated_at')
            ->latest('created_at')
            ->take(12)
            ->get();

        $recentCivilians = User::query()
            ->where('role', 'civilian')
            ->with('responderProfile')
            ->latest()
            ->take(3)
            ->get();

        $moderationQueue = IncidentReport::query()
            ->with(['assignedResponder', 'reporter'])
            ->where(function ($query) use ($activeStatuses) {
                $query
                    ->where(function ($activeQuery) use ($activeStatuses) {
                        $activeQuery
                            ->whereIn('status', $activeStatuses)
                            ->whereNull('assigned_responder_id');
                    })
                    ->orWhere('status', 'rejected')
                    ->orWhere(function ($fatalityQuery) use ($activeStatuses) {
                        $fatalityQuery->where('reported_fatality_flag', true)
                            ->whereIn('status', $activeStatuses);
                    });
            })
            ->orderByRaw("case when status = 'rejected' then 5 when reported_fatality_flag = 1 then 0 when severity in ('Critical', 'Fatal') then 1 when severity = 'Serious' then 2 when (ai_source in ('manual_only', 'text_review') or (".(IncidentReport::manualOnlyMode() ? "ai_source in ('text_model', 'text_model_experimental') and ai_review_required = 1" : '0 = 1').")) then 4 else 3 end")
            ->latest('status_updated_at')
            ->latest('created_at')
            ->take(8)
            ->get();

        return view('admin.dashboard', [
            'user' => Auth::user(),
            'stats' => [
                'civilians' => User::query()->where('role', 'civilian')->count(),
                'responders' => User::query()->where('is_admin', true)->count(),
                'admins' => User::query()->where('is_admin', true)->count(),
                'active_reports' => IncidentReport::query()->whereIn('status', $activeStatuses)->count(),
                'unassigned_reports' => IncidentReport::query()->whereIn('status', $activeStatuses)->whereNull('assigned_responder_id')->count(),
                'reports_today' => IncidentReport::query()->whereDate('created_at', now()->toDateString())->count(),
                'fatal_alerts' => (clone $assessedReports)->whereIn('severity', ['Critical', 'Fatal'])->whereIn('status', $activeStatuses)->count(),
                'needs_review' => IncidentReport::query()->needsResponderReview()->whereIn('status', $activeStatuses)->count(),
                'resolved_today' => IncidentReport::query()->whereDate('resolved_at', now()->toDateString())->where('status', 'resolved')->count(),
                'rejected_today' => IncidentReport::query()->whereDate('status_updated_at', now()->toDateString())->where('status', 'rejected')->count(),
            ],
            'severityBreakdown' => [
                'Minor' => (clone $assessedReports)->where('severity', 'Minor')->count(),
                'Serious' => (clone $assessedReports)->where('severity', 'Serious')->count(),
                'Critical / Fatal' => (clone $assessedReports)->whereIn('severity', ['Critical', 'Fatal'])->count(),
                'needs_review' => IncidentReport::query()->needsResponderReview()->count(),
            ],
            'statusBreakdown' => [
                'Unassigned' => IncidentReport::query()->whereIn('status', $activeStatuses)->whereNull('assigned_responder_id')->count(),
                'Active Dispatch' => IncidentReport::query()->whereIn('status', ['acknowledged', 'dispatched', 'responding'])->count(),
                'Resolved' => IncidentReport::query()->where('status', 'resolved')->count(),
                'Rejected' => IncidentReport::query()->where('status', 'rejected')->count(),
            ],
            'transmissionBreakdown' => [
                'online' => IncidentReport::query()->where('transmission_type', 'online')->count(),
                'lora' => IncidentReport::query()->where('transmission_type', 'lora')->count(),
            ],
            'recentReports' => $recentReports,
            'fatalAlerts' => (clone $assessedReports)
                ->whereIn('severity', ['Critical', 'Fatal'])
                ->whereIn('status', $activeStatuses)
                ->latest()
                ->take(5)
                ->get(),
            'connectivity' => $this->connectivityIntelligence(),
            'mapPoints' => $recentReports->filter(
                fn (IncidentReport $report): bool => $report->latitude !== null && $report->longitude !== null
            ),
            'recentCivilians' => $recentCivilians,
            'moderationQueue' => $moderationQueue,
            'auditTrail' => $this->adminAuditTrail(),
        ]);
    }

    private function connectivityIntelligence(): array
    {
        $recentReports = IncidentReport::query()
            ->latest()
            ->take(12)
            ->get();

        $loraRecent = $recentReports->where('transmission_type', 'lora')->count();
        $onlineRecent = $recentReports->where('transmission_type', 'online')->count();

        return [
            'online_status' => $onlineRecent > 0 ? 'Online gateway stable' : 'Online standby',
            'lora_status' => $loraRecent > 0 ? 'LoRa fallback active' : 'LoRa standby',
            'internet_status' => $loraRecent > $onlineRecent ? 'Internet degraded - fallback preferred' : 'Internet uplink healthy',
            'gateway_status' => $loraRecent > 0 || $onlineRecent > 0 ? 'Gateway synchronized' : 'Awaiting field traffic',
            'websocket_status' => 'Reverb live sync online',
            'queue_status' => 'Immediate broadcast channel',
            'api_status' => 'Laravel API healthy',
        ];
    }

    private function adminAuditTrail(int $limit = 18): array
    {
        return IncidentReport::query()
            ->with(['assignedResponder', 'reporter'])
            ->latest('status_updated_at')
            ->latest('created_at')
            ->take(18)
            ->get()
            ->flatMap(function (IncidentReport $report) {
                $entries = collect($report->coordination_log ?? [])
                    ->filter(fn ($entry): bool => is_array($entry))
                    ->map(function (array $entry) use ($report) {
                        return [
                            'report_id' => $report->id,
                            'report_code' => $report->report_code,
                            'incident_type' => $report->incident_type,
                            'severity' => $report->operational_severity,
                            'severity_display' => $report->severity_display,
                            'severity_is_provisional' => $report->operational_severity === null || $report->ai_source === 'text_review',
                            'ai_score_label' => $report->ai_score_display,
                            'transmission_type' => $report->transmission_type,
                            'reporter_name' => $report->reporter_name ?: ($report->reporter?->name ?? 'Reporter'),
                            'location_text' => $report->location_text ?: ($report->readable_location ?? null),
                            'coordinates' => $report->latitude !== null && $report->longitude !== null
                                ? $report->latitude.', '.$report->longitude
                                : null,
                            'description' => $report->description,
                            'actor_name' => $entry['actor_name'] ?? 'System',
                            'actor_role' => $entry['actor_role'] ?? 'Admin',
                            'label' => $entry['label'] ?? 'Updated incident coordination',
                            'status' => $entry['status'] ?? $report->status,
                            'assigned_responder_name' => $entry['assigned_responder_name'] ?? $report->assignedResponder?->name,
                            'occurred_at' => $entry['occurred_at'] ?? optional($report->status_updated_at)->toIso8601String(),
                            'response_notes' => $entry['response_notes'] ?? $report->response_notes,
                            'detail_url' => route('reports.show', $report),
                        ];
                    });

                if ($entries->isNotEmpty()) {
                    return $entries;
                }

                return [[
                    'report_id' => $report->id,
                    'report_code' => $report->report_code,
                    'incident_type' => $report->incident_type,
                    'severity' => $report->operational_severity,
                    'severity_display' => $report->severity_display,
                    'severity_is_provisional' => $report->operational_severity === null || $report->ai_source === 'text_review',
                    'ai_score_label' => $report->ai_score_display,
                    'transmission_type' => $report->transmission_type,
                    'reporter_name' => $report->reporter_name ?: ($report->reporter?->name ?? 'Reporter'),
                    'location_text' => $report->location_text ?: ($report->readable_location ?? null),
                    'coordinates' => $report->latitude !== null && $report->longitude !== null
                        ? $report->latitude.', '.$report->longitude
                        : null,
                    'description' => $report->description,
                    'actor_name' => $report->reporter_name ?: ($report->reporter?->name ?? 'Reporter'),
                    'actor_role' => 'Civilian',
                    'label' => 'Submitted incident report',
                    'status' => $report->status,
                    'assigned_responder_name' => $report->assignedResponder?->name,
                    'occurred_at' => optional($report->created_at)->toIso8601String(),
                    'response_notes' => null,
                    'detail_url' => route('reports.show', $report),
                ]];
            })
            ->sortByDesc(fn (array $entry): string => (string) ($entry['occurred_at'] ?? ''))
            ->take($limit)
            ->values()
            ->all();
    }
}
