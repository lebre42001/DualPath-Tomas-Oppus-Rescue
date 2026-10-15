<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IncidentReport;
use App\Models\LoraNodeState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class LoRaMeshGatewayController extends Controller
{
    private const GATEWAY_ID = 'NODE_1';
    private const NODE_TIMEOUT_SECONDS = 30;
    private const NETWORK_TTL = 12;

    public function health(Request $request): JsonResponse
    {
        $this->authorizeGateway($request);

        return response()->json([
            'ok' => true,
            'gatewayId' => self::GATEWAY_ID,
            'serverTime' => now('UTC')->toIso8601String(),
        ]);
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $this->authorizeGateway($request);

        $validated = $request->validate([
            'node_id' => ['required', 'string', 'max:40'],
            'gateway_id' => ['nullable', 'string', 'max:40'],
            'route' => ['nullable', 'string', 'max:200'],
            'peers' => ['nullable', 'array', 'max:10'],
            'peers.*' => ['string', 'max:40'],
            'battery' => ['nullable', 'string', 'max:20'],
            'hop' => ['nullable', 'integer', 'min:0', 'max:255'],
            'ttl' => ['nullable', 'integer', 'min:0', 'max:255'],
            'rssi' => ['nullable', 'integer'],
            'snr' => ['nullable', 'numeric', 'between:-100,100'],
            'round_trip_ms' => ['nullable', 'required_with:round_trip_sample', 'integer', 'between:0,60000'],
            'round_trip_sample' => ['nullable', 'required_with:round_trip_ms', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'],
            'round_trip_age_ms' => ['nullable', 'integer', 'between:0,120000'],
            'packet_type' => ['nullable', 'in:HB,SOS'],
        ]);

        $nodeId = strtoupper(trim($validated['node_id']));
        abort_unless(isset(config('lora_nodes')[$nodeId]), 422, 'Unknown registered LoRa node.');

        $knownPeers = collect($validated['peers'] ?? [])
            ->map(fn (mixed $peer): string => strtoupper(trim((string) $peer)))
            ->filter(fn (string $peer): bool => $peer !== $nodeId && isset(config('lora_nodes')[$peer]))
            ->unique()
            ->values()
            ->all();

        $previous = LoraNodeState::query()->where('node_id', $nodeId)->first();
        $sample = $validated['round_trip_sample'] ?? null;
        $measuredAt = $sample === null ? null : (
            $previous?->round_trip_sample === $sample
                ? $previous->round_trip_measured_at
                : now()->subMilliseconds((int) ($validated['round_trip_age_ms'] ?? 0))
        );

        LoraNodeState::query()->updateOrCreate(
            ['node_id' => $nodeId],
            [
                'gateway_id' => $validated['gateway_id'] ?? self::GATEWAY_ID,
                'last_seen_at' => now(),
                'last_known_route' => $validated['route'] ?? $nodeId,
                'known_peers' => $knownPeers,
                'battery' => $this->normalizedBattery($validated['battery'] ?? null),
                'hop' => (int) ($validated['hop'] ?? 0),
                'ttl' => (int) ($validated['ttl'] ?? 0),
                'rssi' => $validated['rssi'] ?? null,
                'snr' => $validated['snr'] ?? null,
                'round_trip_ms' => $validated['round_trip_ms'] ?? null,
                'round_trip_sample' => $sample,
                'round_trip_measured_at' => $measuredAt,
                'last_packet_type' => $validated['packet_type'] ?? 'HB',
            ]
        );

        return response()->json([
            'ok' => true,
            'nodeId' => $nodeId,
            'status' => 'ACTIVE',
            'serverTime' => now('UTC')->toIso8601String(),
        ]);
    }

    public function probe(Request $request): JsonResponse
    {
        $this->authorizeGateway($request);
        $validated = $request->validate([
            'node_id' => ['required', 'string', 'max:40'],
            'probe_id' => ['required', 'string', 'max:24', 'regex:/^[A-Za-z0-9_-]+$/'],
        ]);
        $nodeId = strtoupper(trim($validated['node_id']));
        abort_unless(isset(config('lora_nodes')[$nodeId]), 422, 'Unknown registered LoRa node.');

        // Only the origin's monotonic clock measures the returned round trip.
        // A probe must not create an incident or pretend a heartbeat arrived.
        return response()->json([
            'ok' => true,
            'packet' => 'PONG|'.$nodeId.'|'.$validated['probe_id'].'|'.self::NETWORK_TTL.'|'.self::GATEWAY_ID,
        ]);
    }

    public function networkSummary(Request $request): JsonResponse
    {
        $this->authorizeGateway($request);

        $nodeIds = collect(range(1, 10))->map(fn (int $number): string => 'NODE_'.$number);
        $states = LoraNodeState::query()->whereIn('node_id', $nodeIds)->get()->keyBy('node_id');
        $now = now();
        $activeNodeBitmask = 0;

        foreach ($nodeIds as $index => $nodeId) {
            $lastSeenAt = $states->get($nodeId)?->last_seen_at;
            if ($lastSeenAt !== null && $lastSeenAt->greaterThanOrEqualTo($now->copy()->subSeconds(self::NODE_TIMEOUT_SECONDS))) {
                $activeNodeBitmask |= (1 << $index);
            }
        }

        $activeCount = 0;
        for ($index = 0; $index < 10; $index++) {
            if (($activeNodeBitmask & (1 << $index)) !== 0) $activeCount++;
        }

        $serverTimestamp = $now->copy()->utc()->toIso8601String();

        return response()->json([
            'ok' => true,
            'activeCount' => $activeCount,
            'expectedCount' => 10,
            'nodeTimeoutSeconds' => self::NODE_TIMEOUT_SECONDS,
            'activeNodeBitmask' => $activeNodeBitmask,
            'serverTimestamp' => $serverTimestamp,
            'packet' => 'NET|'.$activeCount.'|'.$activeNodeBitmask.'|'.$serverTimestamp,
        ]);
    }

    public function pendingAcknowledgment(Request $request): JsonResponse
    {
        $this->authorizeGateway($request);
        abort_unless($request->query('gatewayId') === self::GATEWAY_ID, 422, 'gatewayId must be NODE_1.');

        $report = IncidentReport::query()
            ->where('transmission_type', 'lora')
            ->where('status', '!=', 'received')
            ->whereNotNull('lora_ack_queued_at')
            ->whereNull('lora_ack_delivered_at')
            ->whereNotNull('lora_sequence')
            ->oldest('lora_ack_queued_at')
            ->first();

        if ($report === null) {
            return response()->json(['ok' => true, 'pending' => false]);
        }

        $ackStatus = $report->status === 'rejected' ? 'REJECTED' : 'ACKNOWLEDGED';
        $defaultMessage = match ($report->status) {
            'rejected' => 'Your report was reviewed and cancelled by the emergency team.',
            'acknowledged' => 'We received your emergency report and are reviewing it.',
            'resolved' => 'Your emergency report has been marked done by the emergency team.',
            'dispatched', 'responding' => 'The emergency team is responding to your report.',
            default => 'Your emergency report has been reviewed by the emergency team.',
        };
        $message = Str::of((string) ($report->response_notes ?: $defaultMessage))
            ->replace(['|', "\r", "\n"], ' ')
            ->squish();
        // Radio payload limits count UTF-8 bytes, including relay route growth.
        $message = mb_strcut((string) $message, 0, 72, 'UTF-8');
        $acknowledgedAt = $report->lora_ack_queued_at->copy()->utc()->toIso8601String();
        $packet = implode('|', [
            'ACK',
            $report->lora_sequence,
            $report->lora_sender_id,
            self::GATEWAY_ID,
            $ackStatus,
            $acknowledgedAt,
            self::NETWORK_TTL,
            self::GATEWAY_ID,
            (string) $message,
        ]);

        return response()->json([
            'ok' => true,
            'pending' => true,
            'msgId' => $report->lora_sequence,
            'packet' => $packet,
        ]);
    }

    public function acknowledgmentDelivered(Request $request, string $msgId): JsonResponse
    {
        $this->authorizeGateway($request);

        $report = IncidentReport::query()
            ->where('transmission_type', 'lora')
            ->where('lora_sequence', $msgId)
            ->firstOrFail();

        if ($report->lora_ack_queued_at === null) {
            return response()->json(['ok' => false, 'error' => 'No responder acknowledgment is queued.'], 409);
        }

        $report->forceFill(['lora_ack_delivered_at' => $report->lora_ack_delivered_at ?? now()])->save();

        return response()->json([
            'ok' => true,
            'msgId' => $msgId,
            'deliveredAt' => $report->lora_ack_delivered_at?->toIso8601String(),
        ]);
    }

    private function authorizeGateway(Request $request): void
    {
        $expectedToken = (string) config('services.lora_ingest.token');
        abort_if(blank($expectedToken), 503, 'LoRa ingest token is not configured.');

        $providedToken = (string) ($request->bearerToken() ?: $request->header('X-STITCH-LORA-TOKEN'));
        abort_unless($providedToken !== '' && hash_equals($expectedToken, $providedToken), 401, 'Invalid LoRa gateway token.');
    }

    private function normalizedBattery(mixed $value): ?string
    {
        $battery = trim((string) $value);

        return $battery === '' || in_array(strtoupper($battery), ['NA', 'N/A', 'NOT MEASURED'], true)
            ? null
            : Str::limit($battery, 20, '');
    }
}
