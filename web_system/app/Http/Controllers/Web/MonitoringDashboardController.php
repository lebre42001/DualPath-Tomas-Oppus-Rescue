<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\IncidentReport;
use App\Support\LoraMeshNodeDirectory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class MonitoringDashboardController extends Controller
{
    public function __invoke(): View|RedirectResponse
    {
        $user = Auth::user();

        if ($user?->isCivilian()) {
            return redirect()->route('dashboard');
        }

        // One latest-report feed across both online and LoRa submissions.
        $reports = IncidentReport::query()
            ->with(['assignedResponder', 'reporter'])
            ->latest('created_at')
            ->latest('id')
            ->take(3)
            ->get();

        return view('dashboard', [
            'dashboardMode' => 'admin',
            'user' => $user,
            'reports' => $reports,
            // Keep the shared header's alert drawer functional without another feed panel.
            'fatalAlerts' => IncidentReport::query()->operationallyAssessed()
                ->whereIn('severity', ['Critical', 'Fatal'])
                ->whereIn('status', ['received', 'acknowledged', 'dispatched', 'responding'])
                ->latest()->take(5)->get(),
        ]);
    }

    public function loraMesh(LoraMeshNodeDirectory $loraMeshNodeDirectory): View|RedirectResponse
    {
        if (Auth::user()?->isCivilian()) {
            return redirect()->route('dashboard');
        }

        $nodes = $loraMeshNodeDirectory->all();

        return view('monitoring.lora-mesh', [
            'dashboardMode' => 'admin',
            'user' => Auth::user(),
            'loraNodes' => $nodes,
            'activeNodeCount' => $nodes->where('recently_heard', true)->count(),
            'latestNodeUpdate' => $nodes->pluck('last_seen')->filter()->sortDesc()->first(),
        ]);
    }

    public function loraMeshNodes(LoraMeshNodeDirectory $loraMeshNodeDirectory): View
    {
        $nodes = $loraMeshNodeDirectory->all();

        return view('monitoring.partials.lora-node-list', [
            'loraNodes' => $nodes,
            'activeNodeCount' => $nodes->where('recently_heard', true)->count(),
            'latestNodeUpdate' => $nodes->pluck('last_seen')->filter()->sortDesc()->first(),
        ]);
    }
}
