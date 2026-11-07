<?php

namespace App\Http\Middleware;

use App\Models\AdminDutyShift;
use App\Services\AdminActivityRecorder;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RecordAdminActivity
{
    public function handle(Request $request, Closure $next): Response
    {
        $actor = $request->user();
        $route = (string) $request->route()?->getName();
        // Snapshot a bound target before a delete. Never copy request bodies,
        // passwords, tokens, files, coordinates or raw query strings into logs.
        $target = $request->route('incidentReport') ?? $request->route('civilianAccount') ?? $request->route('nodeId');
        $targetLabel = is_object($target) ? ($target->report_code ?? 'Account #'.$target->id) : (is_string($target) ? $target : null);
        $response = $next($request);
        if (! $actor?->is_admin) return $response;

        AdminDutyShift::query()->where('active_user_id', $actor->id)
            ->where('last_activity_at', '<', now()->subMinute())->update(['last_activity_at' => now()]);

        $pages = [
            'monitoring' => 'Live Monitoring', 'monitoring.lora-mesh' => 'LoRa Mesh',
            'lora-reports.index' => 'LoRa Reports', 'reports.index' => 'Online Reports',
            'admin.dashboard' => 'Overview', 'admin.node-locations.index' => 'Node locations',
            'civilian-accounts.index' => 'Civilian Accounts', 'profile.show' => 'Profile',
            'settings.index' => 'Settings', 'admin.system-log' => 'System Log',
            'admin.report-audit' => 'Report audit history',
            'reports.online-details' => 'Report details', 'reports.show' => 'Report details',
        ];
        $print = str_ends_with($route, '.print') || in_array($route, ['reports.export', 'reports.export.preview'], true);
        $reportAction = in_array($route, ['reports.coordination', 'reports.severity-review', 'reports.ai-suggestion', 'reports.destroy', 'reports.destroy-all', 'lora-reports.verification'], true);
        $ownAction = str_starts_with($route, 'admin.system-log.') || in_array($route, ['admin.app-events', 'logout'], true);
        $failed = $response->getStatusCode() >= 400 || ($request->hasSession() && in_array('errors', (array) $request->session()->get('_flash.new', []), true));
        $safeRead = $request->isMethod('GET') && (isset($pages[$route]) || $print)
            && ! ($route === 'admin.system-log' && $request->ajax());
        $mutation = ! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true) && ! $ownAction;
        if ((! $safeRead && ! $mutation) || ($reportAction && ! $failed)) return $response;
        // Redirected unauthorized GETs are not successful page visits.
        if ($safeRead && ! $response->isSuccessful()) return $response;

        $label = $failed ? 'Action not completed' : ($print ? 'Opened print / export preview' : ($safeRead ? 'Opened '.$pages[$route] : 'Saved '.str_replace(['.', '-'], ' ', $route)));
        app(AdminActivityRecorder::class)->record($actor, $safeRead ? ($print ? 'system' : 'navigation') : 'system', $failed ? 'request_failed' : ($print ? 'print_preview' : ($safeRead ? 'page_view' : 'settings_or_account_action')), $label,
            $failed ? 'The request was rejected or did not pass validation. No successful change is claimed.' : ($safeRead ? 'Opened an administrative page or printable preview.' : 'The server completed this authenticated administrative request.'), [
                'target_label' => $targetLabel, 'route' => $route ?: null,
                'context' => ['method' => $request->method(), 'http_status' => $response->getStatusCode()],
            ]);

        return $response;
    }
}
