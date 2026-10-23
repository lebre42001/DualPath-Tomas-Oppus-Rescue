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

    public function print(Request $request, User $civilianAccount): Response
    {
        abort_unless($civilianAccount->isCivilian(), 404);
        $validated = $request->validate(['report' => ['sometimes', 'integer', 'min:1']]);
        // Always scope the selection to this account, including single-report printing.
        $query = $civilianAccount->submittedReports()->with('assignedResponder:id,name')->latest('created_at')->latest('id');
        $reports = isset($validated['report'])
            ? collect([$query->whereKey($validated['report'])->firstOrFail()])
            : $query->get();

        return response()->view('civilian-accounts.reports-print', [
            'civilianAccount' => $civilianAccount,
            'reports' => $reports->map(fn (IncidentReport $report): array => $this->record($report, $civilianAccount)),
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
            'reportedFatality' => (bool) $report->reported_fatality_flag,
            'location' => $report->location_text ?: 'Not recorded',
            'coordinates' => $report->latitude !== null && $report->longitude !== null ? $report->latitude.', '.$report->longitude : null,
            'description' => $report->description ?: 'No description provided.',
            'responder' => $report->assignedResponder?->name,
            'responseNotes' => $report->response_notes,
            'resolvedAt' => $report->resolved_at?->timezone('Asia/Manila')->format('M d, Y h:i A'),
            'printUrl' => route('civilian-accounts.reports.print', ['civilianAccount' => $account, 'report' => $report->id]),
        ];
    }
}
