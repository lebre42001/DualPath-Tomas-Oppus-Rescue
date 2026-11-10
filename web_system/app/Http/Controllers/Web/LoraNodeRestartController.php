<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\LoraNodeRestartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LoraNodeRestartController extends Controller
{
    public function index(Request $request, LoraNodeRestartService $service): JsonResponse
    {
        abort_unless($request->user()?->is_admin, 403);

        return response()->json(['commands' => $service->latestByNode()->map(fn ($command) => $service->summary($command))], 200,
            ['Cache-Control' => 'private, no-store']);
    }

    public function store(Request $request, string $nodeId, LoraNodeRestartService $service): JsonResponse
    {
        abort_unless($request->user()?->is_admin, 403);
        $request->validate(['confirm' => ['required', 'accepted']]);
        $result = $service->request($request->user(), $nodeId);

        return response()->json([
            'ok' => true, 'created' => $result['created'], 'command' => $service->summary($result['command']),
            'message' => $result['created'] ? 'Restart queued. Waiting for delivery and post-boot confirmation.' : 'A restart is already pending for this node.',
        ], $result['created'] ? 202 : 200, ['Cache-Control' => 'private, no-store']);
    }
}
