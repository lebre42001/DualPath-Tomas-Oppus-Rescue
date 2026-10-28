<?php

namespace App\Http\Controllers\Web;

use App\Events\IncidentFeedUpdated;
use App\Http\Controllers\Controller;
use App\Models\IncidentReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IncomingReportController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate(['after' => ['sometimes', 'integer', 'min:0']]);
        $initial = ! array_key_exists('after', $validated);
        // Capture the initial cursor before reading rows so a concurrent arrival
        // can be repeated by the next poll, but can never fall into a gap.
        $cursor = $initial ? (int) IncidentReport::query()->max('id') : (int) $validated['after'];
        $query = IncidentReport::query()->with('assignedResponder');
        $reports = $initial
            ? $query->where('status', 'received')->latest('id')->take(20)->get()->reverse()->values()
            : $query->where('id', '>', $cursor)->orderBy('id')->take(50)->get();

        return response()->json([
            'cursor' => $initial ? $cursor : (int) ($reports->max('id') ?? $cursor),
            'initial' => $initial,
            'items' => $reports->map(fn (IncidentReport $report): array =>
                (new IncidentFeedUpdated($report, 'created'))->broadcastWith())->values(),
        ])->header('Cache-Control', 'no-store, private');
    }
}
