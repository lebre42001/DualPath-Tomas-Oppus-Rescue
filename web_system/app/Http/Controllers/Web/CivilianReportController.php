<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\IncidentReport;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class CivilianReportController extends Controller
{
    public function index(Request $request, User $civilianAccount): JsonResponse
    {
        abort_unless($civilianAccount->isCivilian(), 404);
        $validated = $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        $reports = $civilianAccount->submittedReports()->with('assignedResponder:id,name')
            ->latest('created_at')->latest('id')->paginate(15, ['*'], 'page', $validated['page'] ?? 1);

        return response()->json([
            'account' => ['name' => $civilianAccount->name, 'email' => $civilianAccount->email],
            'reports' => $reports->getCollection()->map(fn (IncidentReport $report): array => $this->record($report, $civilianAccount)),
            'printAllUrl' => route('civilian-accounts.reports.print', $civilianAccount),
            'pagination' => [
                'page' => $reports->currentPage(), 'lastPage' => $reports->lastPage(), 'total' => $reports->total(),
                'from' => $reports->firstItem(), 'to' => $reports->lastItem(),
            ],
        ])->header('Cache-Control', 'private, no-store');
    }

    public function details(User $civilianAccount, IncidentReport $incidentReport): JsonResponse
    {
        abort_unless($civilianAccount->isCivilian(), 404);
        $report = $civilianAccount->submittedReports()->with(['reporter', 'assignedResponder'])
            ->whereKey($incidentReport->id)->firstOrFail();

        return response()->json([
            'title' => $report->report_code.' - '.$report->incident_type,
            'html' => view('reports.partials.online-detail', [
                'report' => $report,
                'printUrl' => route('civilian-accounts.reports.print', ['civilianAccount' => $civilianAccount, 'report' => $report->id]),
            ])->render(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function print(Request $request, User $civilianAccount): Response
    {
        abort_unless($civilianAccount->isCivilian(), 404);
        $validated = $request->validate([
            'report' => ['sometimes', 'integer', 'min:1'],
            'include_photo' => ['sometimes', 'boolean'], 'include_location' => ['sometimes', 'boolean'],
            'include_contact' => ['sometimes', 'boolean'], 'print' => ['sometimes', 'boolean'],
        ]);
        // Always scope the selection to this account, including single-report printing.
        $query = $civilianAccount->submittedReports()->with(['reporter:id,name', 'assignedResponder:id,name'])->latest('created_at')->latest('id');
        $reports = isset($validated['report'])
            ? collect([$query->whereKey($validated['report'])->firstOrFail()])
            : $query->get();

        return response()->view('civilian-accounts.reports-print', [
            'civilianAccount' => $civilianAccount,
            'reports' => $reports,
            'accountName' => $civilianAccount->name,
            'selectedReportId' => $validated['report'] ?? null,
            'printAction' => route('civilian-accounts.reports.print', $civilianAccount),
            'includePhoto' => $request->boolean('include_photo'), 'includeLocation' => $request->boolean('include_location'),
            'includeContact' => $request->boolean('include_contact'), 'autoPrint' => $request->boolean('print'),
            'printedAt' => now()->timezone('Asia/Manila')->format('M d, Y h:i A'),
        ])->header('Cache-Control', 'private, no-store');
    }

    private function record(IncidentReport $report, User $account): array
    {
        $status = match ($report->status) {
            'received' => 'Pending', 'acknowledged' => 'Under review', 'dispatched' => 'Response started',
            'responding' => 'Responded', 'resolved' => 'Resolved', 'rejected' => 'Cancelled',
            default => ucfirst((string) $report->status),
        };

        return [
            'id' => $report->id, 'code' => $report->report_code, 'type' => $report->incident_type,
            'submittedAt' => $report->created_at?->timezone('Asia/Manila')->format('M d, Y h:i A') ?? 'Not recorded',
            'status' => $status,
            'statusTone' => match ($report->status) { 'received' => 'amber', 'resolved' => 'green', 'rejected' => 'red', default => 'blue' },
            'severity' => $report->current_severity_category,
            'reviewRequired' => (bool) $report->ai_review_required || $report->operational_severity === null,
            'detailsUrl' => route('civilian-accounts.reports.details', ['civilianAccount' => $account, 'incidentReport' => $report]),
            'printUrl' => route('civilian-accounts.reports.print', ['civilianAccount' => $account, 'report' => $report->id]),
        ];
    }
}
