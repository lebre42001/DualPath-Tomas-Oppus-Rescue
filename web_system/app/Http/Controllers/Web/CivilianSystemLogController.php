<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\CivilianActivity;
use App\Services\CivilianActivityRecorder;
use App\Support\SystemVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CivilianSystemLogController extends Controller
{
    private function civilian(Request $request): void
    {
        abort_unless($request->user()?->isCivilian(), 403);
    }

    public function index(Request $request): View
    {
        $this->civilian($request);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])],
            'type' => ['nullable', Rule::in(['account', 'report', 'app'])],
        ]);
        $query = CivilianActivity::query()->where('user_id', $request->user()->id);
        if ($filters['from'] ?? null) {
            $query->where('occurred_at', '>=', Carbon::parse($filters['from'], 'Asia/Manila')->startOfDay()->utc());
        }
        if ($filters['to'] ?? null) {
            $query->where('occurred_at', '<=', Carbon::parse($filters['to'], 'Asia/Manila')->endOfDay()->utc());
        }
        if ($filters['type'] ?? null) {
            match ($filters['type']) {
                'account' => $query->whereIn('kind', ['login', 'logout', 'account_created']),
                'report' => $query->where('kind', 'like', 'report_%'),
                'app' => $query->where('kind', 'like', 'app_%'),
            };
        }
        $q = trim($filters['q'] ?? '');
        if ($q !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q).'%';
            $query->where(function ($group) use ($pattern): void {
                foreach (['title', 'message', 'report_code', 'app_version'] as $column) {
                    $group->orWhereRaw($column." LIKE ? ESCAPE '!'", [$pattern]);
                }
            });
        }
        $events = $query->orderByDesc('occurred_at')->orderByDesc('id')->paginate(30)->withQueryString();

        return view('civilian.system-log', compact('events', 'filters'));
    }

    public function feed(Request $request, SystemVersion $version): JsonResponse
    {
        $this->civilian($request);
        $unread = CivilianActivity::query()->where('user_id', $request->user()->id)->where('notifiable', true)->whereNull('read_at');
        $count = (clone $unread)->count();
        $notifications = $unread->orderBy('id')->limit(20)->get()->map(fn ($event) => [
            'id' => $event->id, 'title' => $event->title, 'message' => $event->message,
            'kind' => $event->kind, 'occurred_at' => $event->occurred_at->toIso8601String(),
        ]);

        return response()->json(['version' => $version->current(), 'unread_count' => $count, 'notifications' => $notifications], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function read(Request $request): JsonResponse
    {
        $this->civilian($request);
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:20'], 'ids.*' => ['required', 'integer', 'min:1', 'distinct']]);
        CivilianActivity::query()->where('user_id', $request->user()->id)->whereIn('id', $data['ids'])
            ->where('notifiable', true)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['saved' => true], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function appEvent(Request $request, CivilianActivityRecorder $recorder, SystemVersion $systemVersion): JsonResponse
    {
        $this->civilian($request);
        $data = $request->validate([
            'event_id' => ['required', 'uuid'],
            'kind' => ['required', Rule::in(['app_version_seen', 'app_update_available', 'app_update_requested', 'app_update_applied', 'app_update_failed', 'app_connection_offline', 'app_connection_online'])],
            'version' => ['required', 'regex:/^[a-f0-9]{16}$/'],
            'previous_version' => ['nullable', 'regex:/^[a-f0-9]{16}$/'],
            'occurred_at' => ['nullable', 'date', 'before_or_equal:'.now()->addMinutes(5)->toIso8601String(), 'after_or_equal:'.now()->subDays(90)->toIso8601String()],
        ]);
        // Client telemetry cannot impersonate an admin/report/login action.
        // A detected/requested target must be a build the server currently serves.
        if (in_array($data['kind'], ['app_update_available', 'app_update_requested'], true)) {
            abort_unless(hash_equals($systemVersion->current(), $data['version']), 409, 'Check for the current app version again.');
        }
        [$title, $message] = match ($data['kind']) {
            'app_update_available' => ['App update available', 'The device detected a newer server build. Open Updates to apply it.'],
            'app_update_requested' => ['App update requested', 'The device requested a reload to apply the server build.'],
            'app_update_applied' => ['Updated app version opened', 'The device opened this build after previously using '.($data['previous_version'] ?? 'an earlier build').'.'],
            'app_update_failed' => ['App update could not be completed', 'The device could not prepare or apply the update. Existing report drafts were kept.'],
            'app_connection_offline' => ['Device went offline', 'The device reported losing its network connection. This is not a sign-out.'],
            'app_connection_online' => ['Device connection restored', 'The device reported its network connection was available again.'],
            default => ['App version used', 'The device reported opening this app build.'],
        };
        $deduplicateVersion = in_array($data['kind'], ['app_version_seen', 'app_update_available'], true);
        $key = $deduplicateVersion ? $data['kind'].':'.$data['version'] : $data['event_id'];
        $event = $recorder->record($request->user(), $data['kind'], $title, $message, [
            'app_version' => $data['version'], 'source_key' => hash('sha256', $request->user()->id.':client:'.$key),
            'notifiable' => $data['kind'] === 'app_update_available',
            'occurred_at' => isset($data['occurred_at']) ? Carbon::parse($data['occurred_at'])->utc() : now(),
        ]);
        if (in_array($data['kind'], ['app_version_seen', 'app_update_applied'], true)) {
            CivilianActivity::query()->where('user_id', $request->user()->id)->where('kind', 'app_update_available')
                ->where('app_version', $data['version'])->whereNull('read_at')->update(['read_at' => now()]);
        }

        return response()->json(['saved' => true, 'id' => $event?->id], 200, ['Cache-Control' => 'private, no-store']);
    }
}
