<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\IncidentReport;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class LoRaReportController extends Controller
{
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
