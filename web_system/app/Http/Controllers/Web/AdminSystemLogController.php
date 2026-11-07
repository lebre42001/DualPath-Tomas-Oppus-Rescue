<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AdminActivity;
use App\Models\AdminDutyShift;
use App\Services\AdminActivityRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminSystemLogController extends Controller
{
    private function filters(Request $request): array
    {
        abort_unless($request->user()?->is_admin, 403);
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])],
            'officer' => ['nullable', 'integer', 'min:1'],
            'category' => ['nullable', Rule::in(['account', 'duty', 'report', 'navigation', 'system'])],
            'source' => ['nullable', Rule::in(['online', 'lora', 'system'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'print' => ['nullable', 'boolean'],
        ]);
        if (! $request->hasAny(['from', 'to'])) {
            $data['from'] = $data['to'] = now()->timezone('Asia/Manila')->toDateString();
        } else {
            // Preserve an explicit All dates selection in generated links;
            // null query values are otherwise dropped and become today's view.
            $data['from'] = $data['from'] ?? '';
            $data['to'] = $data['to'] ?? '';
        }
        return $data;
    }

    private function query(array $filters)
    {
        $query = AdminActivity::query();
        if ($filters['from'] ?? null) $query->where('occurred_at', '>=', CarbonImmutable::parse($filters['from'], 'Asia/Manila')->startOfDay()->utc());
        if ($filters['to'] ?? null) $query->where('occurred_at', '<', CarbonImmutable::parse($filters['to'], 'Asia/Manila')->startOfDay()->addDay()->utc());
        foreach (['officer' => 'actor_reference', 'category' => 'category', 'source' => 'source'] as $filter => $column) {
            if ($filters[$filter] ?? null) $query->where($column, $filters[$filter]);
        }
        if ($q = trim($filters['q'] ?? '')) {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q).'%';
            $query->where(function ($group) use ($pattern): void {
                foreach (['actor_name', 'label', 'summary', 'target_label', 'route'] as $column) {
                    $group->orWhereRaw($column." LIKE ? ESCAPE '!'", [$pattern]);
                }
            });
        }
        return $query->orderByDesc('occurred_at')->orderByDesc('id');
    }

    public function index(Request $request)
    {
        $filters = $this->filters($request);
        $parameters = $request->except(['page', 'print']);
        if ($request->hasAny(['from', 'to'])) {
            $parameters['from'] = $filters['from'];
            $parameters['to'] = $filters['to'];
        }
        return response()->view('admin.system-log', [
            'filters' => $filters,
            'events' => $this->query($filters)->paginate(30)->appends($parameters),
            'officers' => AdminActivity::query()->whereNotNull('actor_reference')->select('actor_reference', 'actor_name')->distinct()->orderBy('actor_name')->get(),
            'shifts' => AdminDutyShift::query()->whereNull('ended_at')->orderBy('started_at')->get(),
            'myShift' => AdminDutyShift::query()->where('active_user_id', $request->user()->id)->first(),
            'dailyView' => ! $request->hasAny(['from', 'to']),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function print(Request $request)
    {
        $filters = $this->filters($request);
        $entries = $this->query($filters)->limit(2001)->get();
        if ($entries->count() > 2000) throw ValidationException::withMessages(['from' => 'More than 2,000 entries match. Narrow the dates, officer or category before printing.']);
        return response()->view('admin.system-log-print', [
            'filters' => $filters, 'entries' => $entries, 'autoPrint' => $request->boolean('print') && $entries->isNotEmpty(),
            'printedAt' => now()->timezone('Asia/Manila')->format('M d, Y h:i:s A'),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function duty(Request $request, AdminActivityRecorder $recorder)
    {
        abort_unless($request->user()?->is_admin, 403);
        $data = $request->validate(['action' => ['required', Rule::in(['start', 'end'])]]);
        if ($data['action'] === 'start') $recorder->startDuty($request->user());
        else $recorder->endDuty($request->user());
        return redirect()->route('admin.system-log')->with('status', $data['action'] === 'start' ? 'Your monitoring duty is active.' : 'Your monitoring duty has ended.');
    }

    public function activity(Request $request)
    {
        abort_unless($request->user()?->is_admin, 403);
        $data = $request->validate([
            'event_id' => ['required', 'uuid'],
            'event' => ['required', Rule::in(['report_opened', 'alarm_enabled', 'alarm_muted', 'alarm_tested', 'alarm_stopped', 'alarm_play_requested'])],
            'report_id' => ['nullable', 'required_if:event,report_opened', 'integer', 'exists:incident_reports,id'],
        ]);
        $report = $data['event'] === 'report_opened' ? \App\Models\IncidentReport::findOrFail($data['report_id']) : null;
        $label = match ($data['event']) {
            'report_opened' => 'Opened report review', 'alarm_enabled' => 'Enabled report alarm',
            'alarm_muted' => 'Muted report alarm', 'alarm_stopped' => 'Stopped report alarm',
            'alarm_play_requested' => 'Requested report alarm playback', default => 'Requested report alarm test',
        };
        AdminActivity::query()->firstOrCreate(['entry_key' => 'browser:'.$request->user()->id.':'.$data['event_id']], [
            'actor_reference' => $request->user()->id, 'actor_name' => $request->user()->name,
            'category' => $report ? 'report' : 'system', 'action' => $data['event'], 'label' => $label,
            'summary' => 'The signed-in browser reported this interface action. This does not confirm audio playback or a saved report decision.',
            'target_label' => $report?->report_code, 'incident_report_id' => $report?->id,
            'source' => $report ? ($report->transmission_type === 'lora' ? 'lora' : 'online') : 'system',
            'context' => ['provenance' => 'authenticated_browser'], 'occurred_at' => now(),
        ]);
        return response()->json(['saved' => true], 200, ['Cache-Control' => 'private, no-store']);
    }
}
