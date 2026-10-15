<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\IncidentReport;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View|RedirectResponse
    {
        $user = Auth::user();
        $activeStatuses = ['received', 'acknowledged', 'dispatched', 'responding'];

        if ($user?->is_admin) {
            return redirect()->route('admin.dashboard');
        }

        if ($user?->isCivilian()) {
            $reports = $this->visibleReportsQuery($user)
                ->latest('created_at')
                ->take(8)
                ->get();

            return view('dashboard', [
                'dashboardMode' => 'civilian',
                'user' => $user,
                'stats' => [
                    'total' => $this->visibleReportsQuery($user)->count(),
                    'active' => $this->visibleReportsQuery($user)->whereIn('status', $activeStatuses)->count(),
                    'resolved' => $this->visibleReportsQuery($user)->where('status', 'resolved')->count(),
                    'today' => $this->visibleReportsQuery($user)->whereDate('created_at', now()->toDateString())->count(),
                ],
                'reports' => $reports,
                'fatalAlerts' => collect(),
            ]);
        }

        abort(403, 'Only civilian sender and admin receiver accounts can use this system.');
    }

    private function visibleReportsQuery(?User $user): Builder
    {
        return IncidentReport::query()
            ->with(['assignedResponder'])
            ->when(
                $user?->isCivilian(),
                fn (Builder $query) => $query->where('reported_by', $user?->id)
            );
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
        $priority = match ($report->severity) {
            'Critical', 'Fatal' => 'Priority 1',
            'Serious' => 'Priority 2',
            default => 'Priority 3',
        };

        $responseType = match ($report->severity) {
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
