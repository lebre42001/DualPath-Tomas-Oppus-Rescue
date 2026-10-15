<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\IncidentReport;
use App\Models\User;
use App\Support\LoraMeshNodeDirectory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class MonitoringDashboardController extends Controller
{
    public function __invoke(Request $request, LoraMeshNodeDirectory $loraMeshNodeDirectory): View|RedirectResponse
    {
        $user = Auth::user();

        if ($user?->isCivilian()) {
            return redirect()->route('dashboard');
        }

        $activeStatuses = ['received', 'acknowledged', 'dispatched', 'responding'];
        $assessedReports = IncidentReport::query()->operationallyAssessed();

        $allReports = IncidentReport::query()
            ->with(['assignedResponder', 'reporter'])
            ->latest('created_at')
            ->latest('id')
            ->take(3)
            ->get();
        $reports = $allReports;

        $priorityIncident = IncidentReport::query()
            ->with(['assignedResponder'])
            ->whereIn('status', $activeStatuses)
            ->orderByRaw("case when reported_fatality_flag = 1 then 0 when severity is null or ai_source in ('manual_only', 'text_review', 'text_model', 'text_model_experimental') then 4 when severity in ('Critical', 'Fatal') then 1 when severity = 'Serious' then 2 when severity = 'Minor' then 3 else 5 end")
            ->oldest('created_at')
            ->first();

        $fatalAlerts = (clone $assessedReports)
            ->whereIn('severity', ['Critical', 'Fatal'])
            ->whereIn('status', $activeStatuses)
            ->latest()
            ->take(5)
            ->get();

        $availableResponders = User::query()
            ->where('is_admin', true)
            ->with('responderProfile')
            ->orderBy('name')
            ->take(6)
            ->get();

        $loraNodes = $loraMeshNodeDirectory->all();

        return view('dashboard', [
            'dashboardMode' => 'admin',
            'user' => $user,
            'stats' => [
                'active' => IncidentReport::query()->whereIn('status', $activeStatuses)->count(),
                'assigned_to_me' => IncidentReport::query()
                    ->where('assigned_responder_id', $user?->id)
                    ->whereIn('status', ['acknowledged', 'dispatched', 'responding'])
                    ->count(),
                'fatal' => (clone $assessedReports)->whereIn('severity', ['Critical', 'Fatal'])->whereIn('status', $activeStatuses)->count(),
                'needs_review' => IncidentReport::query()->needsResponderReview()->whereIn('status', $activeStatuses)->count(),
                'today' => IncidentReport::query()->whereDate('created_at', now()->toDateString())->count(),
                'lora_active' => IncidentReport::query()->where('transmission_type', 'lora')->whereDate('created_at', now()->toDateString())->count(),
            ],
            'severityBreakdown' => [
                'Minor' => (clone $assessedReports)->where('severity', 'Minor')->count(),
                'Serious' => (clone $assessedReports)->where('severity', 'Serious')->count(),
                'Critical / Fatal' => (clone $assessedReports)->whereIn('severity', ['Critical', 'Fatal'])->count(),
                'needs_review' => IncidentReport::query()->needsResponderReview()->count(),
            ],
            'transmissionBreakdown' => [
                'online' => IncidentReport::query()->where('transmission_type', 'online')->count(),
                'lora' => IncidentReport::query()->where('transmission_type', 'lora')->count(),
            ],
            'reports' => $reports,
            'fatalAlerts' => $fatalAlerts,
            'priorityIncident' => $priorityIncident,
            'dispatchRecommendation' => $priorityIncident ? $this->dispatchRecommendationFor($priorityIncident) : null,
            'availableResponders' => $availableResponders,
            'loraNodes' => $loraNodes,
            'connectivity' => $this->connectivityIntelligence(),
            'mapPoints' => $allReports->filter(
                fn (IncidentReport $report): bool => $report->latitude !== null && $report->longitude !== null
            ),
        ]);
    }

    public function loraMesh(LoraMeshNodeDirectory $loraMeshNodeDirectory): View|RedirectResponse
    {
        if (Auth::user()?->isCivilian()) {
            return redirect()->route('dashboard');
        }

        $nodes = $loraMeshNodeDirectory->all();

        return view('monitoring.lora-mesh', [
            'dashboardMode' => 'admin',
            'user' => Auth::user(),
            'loraNodes' => $nodes,
            'activeNodeCount' => $nodes->where('recently_heard', true)->count(),
            'latestNodeUpdate' => $nodes->pluck('last_seen')->filter()->sortDesc()->first(),
        ]);
    }

    public function loraMeshNodes(LoraMeshNodeDirectory $loraMeshNodeDirectory): View
    {
        $nodes = $loraMeshNodeDirectory->all();

        return view('monitoring.partials.lora-node-list', [
            'loraNodes' => $nodes,
            'activeNodeCount' => $nodes->where('recently_heard', true)->count(),
            'latestNodeUpdate' => $nodes->pluck('last_seen')->filter()->sortDesc()->first(),
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

    private function dispatchRecommendationFor(IncidentReport $report): array
    {
        if ($report->reported_fatality_flag) {
            return [
                'priority' => 'Critical priority — possible fatality reported',
                'response_type' => 'Urgent responder verification required; fatality is unconfirmed',
                'recommended_responder' => $report->assignedResponder?->name ?? 'Nearest available responder',
                'timer_label' => $this->priorityTimerLabel($report),
            ];
        }

        if ($report->operational_severity === null || $report->isOperationalSeveritySuppressed()) {
            return [
                'priority' => 'Needs responder review',
                'response_type' => 'Unassessed description; verify urgency',
                'recommended_responder' => $report->assignedResponder?->name ?? 'Nearest available responder',
                'timer_label' => $this->priorityTimerLabel($report),
            ];
        }

        $priority = match ($report->operational_severity) {
            'Critical', 'Fatal' => 'Priority 1',
            'Serious' => 'Priority 2',
            default => 'Priority 3',
        };

        $responseType = match ($report->operational_severity) {
            'Critical', 'Fatal' => 'Immediate medical and extraction support',
            'Serious' => 'Rapid structural or trauma assessment',
            default => 'Verification and scene control',
        };

        return [
            'priority' => $priority,
            'response_type' => $responseType,
            'recommended_responder' => $report->assignedResponder?->name ?? 'Admin receiver pending',
            'timer_label' => $this->priorityTimerLabel($report),
        ];
    }

    private function priorityTimerLabel(IncidentReport $report): string
    {
        if ($report->created_at === null) {
            return 'Just received';
        }

        $minutes = max(1, (int) ceil($report->created_at->diffInMinutes(now())));

        return $minutes.' min active';
    }
}
