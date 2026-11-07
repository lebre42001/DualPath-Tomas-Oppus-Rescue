<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\IncidentReport;
use App\Services\IncidentReportFilterQuery;
use App\Support\CivilianSeverityAssessment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class CivilianReportLibraryController extends Controller
{
    public function index(Request $request, IncidentReportFilterQuery $filters): JsonResponse
    {
        abort_unless($request->user()?->isCivilian(), 403);
        $input = $request->validate([
            'page' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'view' => ['nullable', Rule::in(['history', 'status'])],
            'sort' => ['nullable', Rule::in(['newest', 'oldest'])],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])],
        ]);
        $query = $filters->build($request, $request->user());
        if ($input['from'] ?? null) {
            $query->where('created_at', '>=', Carbon::parse($input['from'], 'Asia/Manila')->startOfDay()->utc());
        }
        if ($input['to'] ?? null) {
            $query->where('created_at', '<', Carbon::parse($input['to'], 'Asia/Manila')->addDay()->startOfDay()->utc());
        }
        $direction = ($input['sort'] ?? '') === 'oldest' ? 'asc' : 'desc';
        if (($input['view'] ?? '') === 'status') {
            $query->orderByRaw('COALESCE(status_updated_at, created_at) '.$direction);
        } else {
            $query->orderBy('created_at', $direction);
        }
        $reports = $query->orderBy('id', $direction)->paginate(10);

        return response()->json([
            'reports' => $reports->getCollection()->map(fn ($report) => $this->summary($report)),
            'pagination' => [
                'page' => $reports->currentPage(), 'lastPage' => $reports->lastPage(),
                'total' => $reports->total(), 'from' => $reports->firstItem(), 'to' => $reports->lastItem(),
            ],
        ], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function details(Request $request, IncidentReport $incidentReport): JsonResponse
    {
        abort_unless($request->user()?->isCivilian(), 403);
        abort_unless((int) $incidentReport->reported_by === (int) $request->user()->id, 404);

        return response()->json(['report' => [
            ...$this->summary($incidentReport),
            'description' => $incidentReport->description,
            'response' => $incidentReport->response_notes,
            'assignedTo' => $incidentReport->assignedResponder?->name,
            'evidenceUrl' => $incidentReport->evidence_type === 'photo' && $incidentReport->evidence_path
                ? route('reports.evidence', $incidentReport) : null,
            'selfieUrl' => $incidentReport->reporter_selfie_path ? route('reports.selfie', $incidentReport) : null,
            'showUrl' => route('reports.show', $incidentReport),
            'assessmentHtml' => view('partials.civilian-severity-assessment', [
                'assessment' => CivilianSeverityAssessment::forReport($incidentReport),
            ])->render(),
        ]], 200, ['Cache-Control' => 'private, no-store']);
    }

    private function summary(IncidentReport $report): array
    {
        return [
            'id' => $report->id, 'code' => $report->report_code, 'type' => $report->incident_type,
            'status' => $report->status,
            'statusLabel' => match ($report->status) {
                'received', 'pending', 'queued' => 'Pending', 'acknowledged' => 'Acknowledged',
                'dispatched' => 'Dispatched', 'responding' => 'Responding', 'resolved' => 'Resolved',
                'rejected', 'cancelled', 'canceled' => 'Cancelled', default => 'Under review',
            },
            'submittedAt' => $report->created_at?->toIso8601String(),
            'updatedAt' => $report->status_updated_at?->toIso8601String(),
            'location' => $report->location_text,
            'detailsUrl' => route('reports.civilian-details', $report),
        ];
    }
}
