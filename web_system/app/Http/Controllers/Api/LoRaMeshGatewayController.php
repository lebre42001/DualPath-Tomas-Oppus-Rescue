<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IncidentReport;
use App\Models\LoraNodeState;
use App\Support\LoraMeshNodeDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\InputBag;

class LoRaMeshGatewayController extends Controller
{
    private const GATEWAY_ID = 'NODE_1';

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

    public function heartbeats(Request $request): JsonResponse
    {
        $this->authorizeGateway($request);
        $request->validate([
            'nodes' => ['required', 'array', 'min:1', 'max:10'],
            'nodes.*' => ['required', 'array'],
            'nodes.*.node_id' => ['required', 'string', 'distinct:ignore_case', 'max:40'],
        ]);

        // Reuse the same validation and storage rules. Any invalid row rolls
        // back the whole batch rather than accepting a partial node snapshot.
        $ids = DB::transaction(function () use ($request): array {
            $ids = [];
            foreach ($request->input('nodes') as $node) {
                $child = clone $request;
                $child->setJson(new InputBag($node));
                $result = $this->heartbeat($child)->getData(true);
                $ids[] = $result['nodeId'];
            }

            return $ids;
        });

        return response()->json(['ok' => true, 'nodeIds' => $ids]);
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
            'blocked_submissions' => ['nullable', 'integer', 'between:0,4294967295'],
            'radio_rejections' => ['nullable', 'integer', 'between:0,4294967295'],
            'guard_boot' => ['nullable', 'string', 'max:16', 'regex:/^[a-f0-9]+$/'],
            'radio_auth' => ['nullable', 'in:hmac-sha256-v2'],
            'received_age_ms' => ['nullable', 'integer', 'between:0,30000'],
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
                : now()->subMilliseconds((int) ($validated['round_trip_age_ms'] ?? 0) + (int) ($validated['received_age_ms'] ?? 0))
        );

        LoraNodeState::query()->updateOrCreate(
            ['node_id' => $nodeId],
            [
                'gateway_id' => $validated['gateway_id'] ?? self::GATEWAY_ID,
                'last_seen_at' => now()->subMilliseconds((int) ($validated['received_age_ms'] ?? 0)),
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
                'blocked_submissions' => $validated['blocked_submissions'] ?? null,
                'radio_rejections' => $validated['radio_rejections'] ?? null,
                'guard_boot' => $validated['guard_boot'] ?? null,
                'radio_auth' => $validated['radio_auth'] ?? null,
                'guard_reported_at' => isset($validated['radio_auth']) ? now() : null,
            ]
        );

        return response()->json([
            'ok' => true,
            'nodeId' => $nodeId,
            'status' => 'REACHABLE',
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
            if ($lastSeenAt !== null && $lastSeenAt->greaterThanOrEqualTo($now->copy()->subSeconds(LoraMeshNodeDirectory::NODE_TIMEOUT_SECONDS))) {
                $activeNodeBitmask |= (1 << $index);
            }
        }

        $activeCount = 0;
        for ($index = 0; $index < 10; $index++) {
            if (($activeNodeBitmask & (1 << $index)) !== 0) {
                $activeCount++;
            }
        }

        $serverTimestamp = $now->copy()->utc()->toIso8601String();

        return response()->json([
            'ok' => true,
            'activeCount' => $activeCount,
            'expectedCount' => 10,
            'nodeTimeoutSeconds' => LoraMeshNodeDirectory::NODE_TIMEOUT_SECONDS,
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
            ->where(fn ($query) => $query->where('status', '!=', 'received')->orWhere('lora_verification', '!=', 'unverified'))
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
        $reviewIsNewest = $report->lora_verified_at !== null
            && ($report->status_updated_at === null || $report->lora_verified_at->greaterThanOrEqualTo($report->status_updated_at));
        $message = Str::of((string) ($reviewIsNewest ? $report->lora_verification_notes : ($report->response_notes ?: $defaultMessage)))
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
        // Legacy reports keep their original 9-field response. V2 gateways
        // append an origin-specific ACK authentication tag before transmission.
        if ($report->lora_radio_auth === 'hmac-sha256-v2') {
            $verificationLabel = match ($report->lora_verification) {
                'confirmed' => 'Confirmed',
                'needs_clarification' => 'Needs clarification',
                'false_report' => 'False report',
                default => 'Unverified',
            };
            $parts = explode('|', $packet);
            $parts[] = $verificationLabel;
            // Reserve the gateway's 22-character origin tag and full 10-node
            // relay route, including the transport's reversible escaping.
            do {
                $worst = $parts;
                $worst[7] = implode('>', array_map(fn ($n) => 'NODE_'.$n, range(1, 10)));
                $compact = str_replace('NODE_', '~N', str_replace('~', '~~', implode('|', $worst)));
                if (strlen($compact) + 34 + 23 <= 255) {
                    break;
                }
                $parts[8] = mb_strcut($parts[8], 0, max(0, strlen($parts[8]) - 1), 'UTF-8');
            } while ($parts[8] !== '');
            $packet = implode('|', $parts);
        }

        return response()->json([
            'ok' => true,
            'pending' => true,
            'msgId' => $report->lora_sequence,
            'packet' => $packet,
            'ackToken' => $this->ackRevisionToken($report),
        ], 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function acknowledgmentDelivered(Request $request, string $msgId): JsonResponse
    {
        $this->authorizeGateway($request);
        $validated = $request->validate(['ack_token' => ['sometimes', 'required', 'string', 'regex:/^[a-f0-9]{64}$/']]);

        return DB::transaction(function () use ($msgId, $validated): JsonResponse {
            $report = IncidentReport::query()
                ->where('transmission_type', 'lora')
                ->where('lora_sequence', $msgId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($report->lora_ack_queued_at === null) {
                return response()->json(['ok' => false, 'error' => 'No responder acknowledgment is queued.'], 409);
            }
            if (isset($validated['ack_token']) && ! hash_equals($this->ackRevisionToken($report), $validated['ack_token'])) {
                // A previously transmitted response must not clear a newer admin update.
                return response()->json(['ok' => true, 'delivered' => false, 'superseded' => true, 'msgId' => $msgId]);
            }

            $report->forceFill(['lora_ack_delivered_at' => $report->lora_ack_delivered_at ?? now()])->save();

            return response()->json([
                'ok' => true,
                'msgId' => $msgId,
                'deliveredAt' => $report->lora_ack_delivered_at?->toIso8601String(),
            ]);
        });
    }

    private function ackRevisionToken(IncidentReport $report): string
    {
        return hash('sha256', json_encode([
            $report->lora_sequence, $report->lora_ack_queued_at?->toIso8601String(),
            $report->status, $report->response_notes, $report->status_updated_at?->toIso8601String(),
            $report->lora_verification, $report->lora_verification_notes, $report->lora_verified_at?->toIso8601String(),
        ], JSON_THROW_ON_ERROR));
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
