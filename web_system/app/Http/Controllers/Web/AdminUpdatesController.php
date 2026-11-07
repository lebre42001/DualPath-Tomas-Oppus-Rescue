<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AdminActivity;
use App\Support\SystemVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class AdminUpdatesController extends Controller
{
    public function feed(Request $request, SystemVersion $version)
    {
        abort_unless($request->user()?->is_admin, 403);

        return response()->json([
            'version' => $version->current(),
            'release_notes_html' => view('partials.admin-release-notes')->render(),
        ], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function event(Request $request, SystemVersion $version)
    {
        abort_unless($request->user()?->is_admin, 403);
        $data = $request->validate([
            'event_id' => ['required', 'uuid'],
            'kind' => ['required', Rule::in(['app_version_seen', 'app_update_available', 'app_update_requested', 'app_update_applied', 'app_update_failed', 'app_connection_offline', 'app_connection_online'])],
            'version' => ['required', 'regex:/^[a-f0-9]{16}$/'],
            'previous_version' => ['nullable', 'regex:/^[a-f0-9]{16}$/'],
            'occurred_at' => ['nullable', 'date', 'before_or_equal:'.now()->addMinutes(5)->toIso8601String(), 'after_or_equal:'.now()->subDays(90)->toIso8601String()],
        ]);
        if (in_array($data['kind'], ['app_update_available', 'app_update_requested'], true)) {
            abort_unless(hash_equals($version->current(), $data['version']), 409, 'Check for the current server build again.');
        }
        $label = match ($data['kind']) {
            'app_update_available' => 'Admin app update available', 'app_update_requested' => 'Requested admin app update',
            'app_update_applied' => 'Updated admin app version opened', 'app_update_failed' => 'Admin app update preparation failed',
            'app_connection_offline' => 'Admin browser went offline', 'app_connection_online' => 'Admin browser reconnected',
            default => 'Admin app version opened',
        };
        $deduplicate = in_array($data['kind'], ['app_version_seen', 'app_update_available'], true);
        $key = $deduplicate ? $data['kind'].':'.$data['version'] : $data['event_id'];
        $summary = 'Build '.$data['version'].(($data['previous_version'] ?? null) ? '; previous build '.$data['previous_version'] : '').'.';
        if ($data['kind'] === 'app_update_available') $summary .= ' Changes: '.implode(' ', config('admin_releases.0.changes', []));
        $event = AdminActivity::query()->firstOrCreate(['entry_key' => 'admin-app:'.$request->user()->id.':'.$key], [
            'actor_reference' => $request->user()->id, 'actor_name' => $request->user()->name,
            'category' => 'system', 'action' => $data['kind'], 'label' => $label,
            'summary' => $summary.' Reported by the signed-in browser; opening a version does not prove a firmware upload or server deployment.',
            'target_label' => $data['version'], 'source' => 'system',
            'context' => ['provenance' => 'authenticated_browser', 'version' => $data['version'], 'previous_version' => $data['previous_version'] ?? null,
                'device_occurred_at' => isset($data['occurred_at']) ? Carbon::parse($data['occurred_at'])->utc()->toIso8601String() : null],
            'occurred_at' => now(),
        ]);

        return response()->json(['saved' => true, 'id' => $event->id], 200, ['Cache-Control' => 'private, no-store']);
    }
}
