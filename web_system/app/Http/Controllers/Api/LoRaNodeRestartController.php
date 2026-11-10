<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LoraNodeRestartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LoRaNodeRestartController extends Controller
{
    public function pending(Request $request, LoraNodeRestartService $service): JsonResponse
    {
        $this->authorizeGateway($request);
        $request->validate(['gateway_id' => ['required', Rule::in(['NODE_1'])]]);
        $command = $service->pending();

        return response()->json([
            'ok' => true, 'pending' => $command !== null,
            'command' => $command === null ? null : [
                'id' => (string) $command->id, 'type' => 'restart', 'node_id' => $command->node_id,
                'expected_boot' => $command->expected_boot,
                'expires_at_epoch' => $command->expires_at->timestamp,
                'expires_in_seconds' => max(0, $command->expires_at->timestamp - now()->timestamp),
            ],
        ], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function result(Request $request, string $commandId, LoraNodeRestartService $service): JsonResponse
    {
        $this->authorizeGateway($request);
        abort_unless(preg_match('/^[1-9][0-9]{0,9}$/D', $commandId) === 1 && (int) $commandId <= 4294967295, 404);
        $data = $request->validate([
            'node_id' => ['required', 'string', 'regex:/^NODE_(?:[1-9]|10)$/D'],
            'status' => ['required', Rule::in(['sent', 'accepted', 'restarted', 'failed'])],
            'boot_id' => ['nullable', 'required_if:status,accepted,restarted', 'string', 'regex:/^[a-f0-9]{1,16}$/D'],
            'reason' => ['nullable', 'required_if:status,failed', Rule::in(['busy', 'persistence_error', 'unsupported', 'delivery_failed', 'stale_boot'])],
        ]);
        $command = $service->result((int) $commandId, $data);

        return response()->json(['ok' => true, 'command_id' => (string) $command->id, 'status' => $command->status], 200,
            ['Cache-Control' => 'private, no-store']);
    }

    private function authorizeGateway(Request $request): void
    {
        $expected = (string) config('services.lora_ingest.token');
        abort_if(blank($expected), 503, 'LoRa ingest token is not configured.');
        $provided = (string) ($request->bearerToken() ?: $request->header('X-STITCH-LORA-TOKEN'));
        abort_unless($provided !== '' && hash_equals($expected, $provided), 401, 'Invalid LoRa gateway token.');
    }
}
