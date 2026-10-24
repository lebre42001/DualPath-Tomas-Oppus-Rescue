<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\IncidentReport;
use App\Services\IncidentReportFilterQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class OnlineReportController extends Controller
{
    public function index(Request $request, IncidentReportFilterQuery $filters): JsonResponse
    {
        $validated = $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        $parameters = $filters->parameters($request, $request->user());
        $query = $filters->build($request, $request->user())->with('reporter:id,name');
        match ($parameters['sort']) {
            'oldest' => $query->oldest('created_at')->oldest('id'),
            'severity' => $query->orderByRaw("CASE (".$filters->severityExpression($query).") WHEN 'Critical' THEN 1 WHEN 'Serious' THEN 2 WHEN 'Minor' THEN 3 WHEN 'Needs review' THEN 4 ELSE 5 END")->latest('created_at')->latest('id'),
            'status' => $query->orderByRaw("CASE status WHEN 'received' THEN 1 WHEN 'acknowledged' THEN 2 WHEN 'dispatched' THEN 3 WHEN 'responding' THEN 4 WHEN 'resolved' THEN 5 ELSE 6 END")->latest('created_at')->latest('id'),
            default => $query->latest('created_at')->latest('id'),
        };
        $reports = $query->paginate(15, ['*'], 'page', $validated['page'] ?? 1);

        return response()->json([
            'reports' => $reports->getCollection()->map(function (IncidentReport $report): array {
                $final = $report->current_severity_category !== 'Unassessed'
                    && ! in_array($report->ai_source, ['manual_only', 'text_review', 'text_model', 'text_model_experimental', 'text_rule_advisory'], true);
                $top = $report->ai_top_category;

                return [
                    'id' => $report->id, 'code' => $report->report_code,
                    'name' => $report->reporter_name ?: ($report->reporter?->name ?: 'Unknown reporter'),
                    'severity' => $report->current_severity_category,
                    'analysis' => $final ? 'Admin assessed' : ($top ? 'Model score '.number_format($top['score'], 1).'% · review required' : 'Awaiting admin assessment'),
                    'date' => $report->created_at?->timezone('Asia/Manila')->format('M d, Y h:i A') ?? 'Not recorded',
                    'detailsUrl' => route('reports.online-details', $report),
                ];
            }),
            'pagination' => ['page' => $reports->currentPage(), 'lastPage' => $reports->lastPage(),
                'total' => $reports->total(), 'from' => $reports->firstItem(), 'to' => $reports->lastItem()],
        ])->header('Cache-Control', 'private, no-store');
    }

    public function details(IncidentReport $incidentReport): JsonResponse
    {
        $this->onlineOnly($incidentReport);
        $incidentReport->load(['reporter', 'assignedResponder']);

        return response()->json([
            'title' => $incidentReport->report_code.' - '.$incidentReport->incident_type,
            'html' => view('reports.partials.online-detail', ['report' => $incidentReport])->render(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function print(IncidentReport $incidentReport): Response
    {
        $this->onlineOnly($incidentReport);
        $incidentReport->load(['reporter', 'assignedResponder']);

        return response()->view('reports.online-print', ['report' => $incidentReport,
            'printedAt' => now()->timezone('Asia/Manila')->format('M d, Y h:i A')])
            ->header('Cache-Control', 'private, no-store');
    }

    private function onlineOnly(IncidentReport $report): void
    {
        abort_unless(strtolower(trim((string) $report->transmission_type)) === 'online', 404);
    }
}
