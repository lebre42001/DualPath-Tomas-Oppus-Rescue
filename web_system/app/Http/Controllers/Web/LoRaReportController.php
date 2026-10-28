<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\IncidentReport;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use App\Support\IncidentFeedBroadcaster;
use App\Models\LoraNodeState;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class LoRaReportController extends Controller
{
    public function verify(Request $request, IncidentReport $incidentReport): RedirectResponse
    {
        abort_unless($request->user()?->is_admin && $incidentReport->transmission_type === 'lora', 403);
        $validated = $request->validate([
            'verification' => ['required', Rule::in(['confirmed', 'needs_clarification', 'false_report'])],
            'verification_notes' => ['required', 'string', 'max:500'],
        ]);
        DB::transaction(function () use ($incidentReport, $validated, $request): void {
            $report = IncidentReport::query()->lockForUpdate()->findOrFail($incidentReport->id);
            // Identical resubmission does not add another log or radio ACK.
            if ($report->lora_verification === $validated['verification']
                && $report->lora_verification_notes === $validated['verification_notes']) {
                return;
            }
            $previous = $report->lora_verification ?? 'unverified';
            $report->forceFill([
                'lora_verification' => $validated['verification'],
                'lora_verification_notes' => $validated['verification_notes'],
                'lora_verified_by' => $request->user()->id,
                'lora_verified_at' => now(),
                // Veracity and rescue progress are separate decisions. Saving
                // this review does not silently dispatch or cancel responders.
                'lora_ack_queued_at' => now(),
                'lora_ack_delivered_at' => null,
                'coordination_log' => [
                    ...($report->coordination_log ?? []),
                    [
                        'event' => 'lora_verification',
                        'label' => 'Report verification: '.str_replace('_', ' ', $validated['verification']),
                        'previous_verification' => $previous,
                        'verification' => $validated['verification'],
                        'actor_id' => $request->user()->id,
                        'actor_name' => $request->user()->name,
                        'at' => now()->toIso8601String(),
                        'response_notes' => $validated['verification_notes'],
                    ],
                ],
            ])->save();
        });
        IncidentFeedBroadcaster::dispatch($incidentReport->fresh(), 'updated');

        return back()->with('status', 'LoRa report verification saved.');
    }

    public function print(Request $request, IncidentReport $incidentReport): Response
    {
        abort_unless(strtolower(trim((string) $incidentReport->transmission_type)) === 'lora', 404);
        $request->validate([
            'include_location' => ['sometimes', 'boolean'],
            'include_contact' => ['sometimes', 'boolean'],
            'print' => ['sometimes', 'boolean'],
        ]);
        $incidentReport->load(['reporter', 'assignedResponder']);

        return response()->view('reports.print', [
            'reports' => collect([$incidentReport]),
            'reportTypeLabel' => 'LoRa',
            'printAction' => route('lora-reports.print', $incidentReport),
            'includePhoto' => false,
            'includeLocation' => $request->boolean('include_location'),
            'includeContact' => $request->boolean('include_contact'),
            'autoPrint' => $request->boolean('print'),
            'printedAt' => now()->timezone('Asia/Manila')->format('M d, Y h:i A'),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'node' => ['nullable', Rule::in(array_map(fn ($n) => 'NODE_'.$n, range(1, 10)))],
            'scope' => ['nullable', Rule::in(['all', 'active', 'closed'])],
            'sort' => ['nullable', Rule::in(['newest', 'oldest'])],
            'per_page' => ['nullable', Rule::in([5, 10, 25])],
            'page' => ['nullable', 'integer', 'min:1'],
            'review' => ['nullable', 'integer', 'min:1'],
        ]);
        $filters = array_replace([
            'search' => '', 'node' => '', 'scope' => 'all', 'sort' => 'newest', 'per_page' => 10,
        ], array_filter($filters, fn ($value) => $value !== null));
        $query = IncidentReport::query()->where('transmission_type', 'lora');
        $counts = (clone $query)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $activeStatuses = ['received', 'acknowledged', 'dispatched', 'responding'];

        if ($filters['node'] !== '') {
            $query->where('lora_sender_id', $filters['node']);
        }
        if ($filters['scope'] === 'active') {
            $query->whereIn('status', $activeStatuses);
        } elseif ($filters['scope'] === 'closed') {
            $query->whereIn('status', ['resolved', 'rejected']);
        }
        if (trim($filters['search']) !== '') {
            $term = '%'.trim($filters['search']).'%';
            $query->where(function ($scoped) use ($term): void {
                foreach (['report_code', 'lora_sequence', 'lora_sender_id', 'reporter_name', 'incident_type', 'description'] as $column) {
                    $scoped->orWhere($column, 'like', $term);
                }
            });
        }

        $reviewId = (int) ($filters['review'] ?? 0);
        if ($reviewId > 0) {
            $query->orderByRaw('case when id = ? then 0 else 1 end', [$reviewId]);
        }
        $direction = $filters['sort'] === 'oldest' ? 'asc' : 'desc';
        $reports = $query->orderBy('created_at', $direction)->orderBy('id', $direction)
            ->paginate((int) $filters['per_page'])->withQueryString();

        return view('lora-reports.index', [
            'guardStates' => LoraNodeState::query()->whereNotNull('guard_reported_at')->orderBy('node_id')->get(),
            'reports' => $reports,
            'filters' => $filters,
            'reviewReportId' => (int) old('review_report_id', $reviewId),
            'reportCounts' => [
                'all' => $counts->sum(),
                'active' => $counts->only($activeStatuses)->sum(),
                'closed' => $counts->only(['resolved', 'rejected'])->sum(),
            ],
        ]);
    }
}
