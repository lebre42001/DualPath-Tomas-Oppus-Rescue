<?php

namespace App\Http\Controllers;

use App\Support\CivilianPresence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CivilianPresenceController extends Controller
{
    public function __invoke(Request $request, CivilianPresence $presence): JsonResponse
    {
        abort_unless($request->user()?->isCivilian(), 403);
        $data = $request->validate([
            'client_id' => ['required', 'string', 'min:8', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'sequence' => ['required', 'integer', 'between:1,2147483646'],
            'state' => ['required', 'in:online,offline'],
        ]);
        $presence->record($request, $data);

        return response()->json(['ok' => true, 'heartbeatSeconds' => CivilianPresence::HEARTBEAT_SECONDS])
            ->header('Cache-Control', 'private, no-store');
    }
}
