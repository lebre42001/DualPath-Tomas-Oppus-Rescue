<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\IncidentReport;
use App\Models\IncidentReportAudit;
use App\Models\User;
use App\Support\LoraMeshNodeDirectory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AdminDashboardController extends Controller
{
    public function __invoke(Request $request, LoraMeshNodeDirectory $directory): View
    {
        $activeStatuses = ['received', 'acknowledged', 'dispatched', 'responding'];
        $assessedReports = IncidentReport::query()->operationallyAssessed();
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
            ->orderByRaw("case when status = 'rejected' then 5 when reported_fatality_flag = 1 then 0 when severity is null or ai_source in ('manual_only', 'text_review', 'text_model', 'text_model_experimental', 'text_rule_advisory') then 4 when severity in ('Critical', 'Fatal') then 1 when severity = 'Serious' then 2 else 3 end")
            ->latest('status_updated_at')
            ->latest('created_at')
            ->take(8)
            ->get();

        $auditFilters = $this->auditFilters($request);

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
            'fatalAlerts' => (clone $assessedReports)
                ->whereIn('severity', ['Critical', 'Fatal'])
                ->whereIn('status', $activeStatuses)
                ->latest()
                ->take(5)
                ->get(),
            'connectivity' => $this->connectivityIntelligence(),
            'nodeMapPoints' => $directory->mapPoints(),
            'moderationQueue' => $moderationQueue,
            'auditTrail' => $this->auditQuery([])->paginate(6, ['*'], 'audit_page', 1),
            'auditFilters' => $auditFilters,
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

    public function history(Request $request): JsonResponse
    {
        $filters = $this->auditFilters($request);
        $entries = $this->auditQuery($filters)->paginate(10, ['*'], 'audit_page', $filters['audit_page'] ?? 1);

        return response()->json([
            'html' => view('admin.partials.report-audit-rows', ['auditTrail' => $entries, 'auditSelectable' => true])->render(),
            'pagination' => ['page' => $entries->currentPage(), 'lastPage' => $entries->lastPage(),
                'total' => $entries->total(), 'from' => $entries->firstItem(), 'to' => $entries->lastItem()],
        ])->header('Cache-Control', 'private, no-store');
    }

    public function printHistory(Request $request): Response
    {
        $filters = $this->auditFilters($request);
        $options = $request->validate([
            'audit_ids' => ['sometimes', 'required', 'string', 'max:4000', 'regex:/^[1-9][0-9]*(,[1-9][0-9]*)*$/'],
            'include_notes' => ['sometimes', 'boolean'],
            'print' => ['sometimes', 'boolean'],
        ]);
        $ids = isset($options['audit_ids']) ? array_values(array_unique(explode(',', $options['audit_ids']))) : [];
        if (count($ids) > 500) {
            throw ValidationException::withMessages(['audit_ids' => 'Select up to 500 actions per print copy, or print all matching filters.']);
        }
        $query = $this->auditQuery($filters);
        if ($ids !== []) {
            $query->whereIn('id', $ids);
        }
        $entries = $query->get();
        if ($ids !== [] && $entries->count() !== count($ids)) {
            throw ValidationException::withMessages(['audit_ids' => 'Some selected actions are unavailable or do not match these filters. Refresh the list and select them again.']);
        }

        return response()->view('admin.report-audit-print', [
            'entries' => $entries,
            'filters' => $filters,
            'selectedIds' => $ids,
            'includeNotes' => $request->boolean('include_notes'),
            'autoPrint' => $request->boolean('print'),
            'printedAt' => now()->timezone('Asia/Manila')->format('M d, Y h:i A'),
        ])->header('Cache-Control', 'private, no-store');
    }

    private function auditFilters(Request $request): array
    {
        return $request->validate([
            'audit_source' => ['nullable', 'in:online,lora'],
            'audit_action' => ['nullable', 'in:coordination,severity_review,ai_analysis,deletion'],
            'audit_search' => ['nullable', 'string', 'max:100'],
            'audit_admin' => ['nullable', 'string', 'max:100'],
            'audit_page' => ['nullable', 'integer', 'min:1'],
            'audit_from' => ['nullable', 'date_format:Y-m-d'],
            'audit_to' => array_merge(['nullable', 'date_format:Y-m-d'], $request->filled('audit_from') ? ['after_or_equal:audit_from'] : []),
        ]);
    }

    private function auditQuery(array $filters): Builder
    {
        $query = IncidentReportAudit::query()->with('report:id,transmission_type');
        if (filled($filters['audit_source'] ?? null)) {
            $query->where('transmission_type', $filters['audit_source']);
        }
        if (filled($filters['audit_action'] ?? null)) {
            $query->where('action', $filters['audit_action']);
        }
        if ($admin = trim($filters['audit_admin'] ?? '')) {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($admin)).'%';
            $query->whereRaw("LOWER(actor_name) LIKE ? ESCAPE '!'", [$pattern]);
        }
        if ($search = trim($filters['audit_search'] ?? '')) {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)).'%';
            $query->where(function ($scoped) use ($pattern): void {
                foreach (['report_code', 'actor_name', 'node_id', 'incident_type', 'notes', 'label'] as $column) {
                    $scoped->orWhereRaw("LOWER({$column}) LIKE ? ESCAPE '!'", [$pattern]);
                }
            });
        }
        if (! empty($filters['audit_from'])) {
            $query->where('occurred_at', '>=', CarbonImmutable::createFromFormat('!Y-m-d', $filters['audit_from'], 'Asia/Manila')->utc());
        }
        if (! empty($filters['audit_to'])) {
            $query->where('occurred_at', '<', CarbonImmutable::createFromFormat('!Y-m-d', $filters['audit_to'], 'Asia/Manila')->addDay()->utc());
        }

        return $query->orderByRaw('CASE WHEN occurred_at IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('occurred_at')->orderByDesc('id');
    }
}
