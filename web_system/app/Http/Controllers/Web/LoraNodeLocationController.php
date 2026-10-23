<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\LoraNodeLocation;
use App\Support\LoraMeshNodeDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LoraNodeLocationController extends Controller
{
    public function index(LoraMeshNodeDirectory $directory): View
    {
        return view('admin.node-locations', ['loraNodes' => $directory->all()]);
    }

    public function update(Request $request, string $nodeId): RedirectResponse
    {
        abort_unless(preg_match('/^NODE_([1-9]|10)$/', $nodeId) === 1 && isset(config('lora_nodes')[$nodeId]), 404);
        $validated = $request->validate([
            'location_label' => ['required', 'string', 'max:120'],
            'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:-180,180'],
        ]);
        LoraNodeLocation::query()->updateOrCreate(['node_id' => $nodeId], $validated);

        return redirect()->to(route('admin.node-locations.index').'#location-'.$nodeId)
            ->with('status', $nodeId.' location saved. New LoRa reports will use this location.')
            ->with('saved_node', $nodeId);
    }

    public function points(LoraMeshNodeDirectory $directory): JsonResponse
    {
        return response()->json([
            'points' => $directory->mapPoints(),
            'totalNodes' => 10,
        ])->header('Cache-Control', 'private, no-store');
    }
}
