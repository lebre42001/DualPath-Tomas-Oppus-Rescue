<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IncidentReport;
use App\Models\LoraNodeState;
use App\Support\IncidentFeedBroadcaster;
use App\Support\IncidentReportIngestor;
use App\Support\LoraNodeRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class LoRaAlertIngestController extends Controller
{
    public function __invoke(Request $request, IncidentReportIngestor $ingestor): JsonResponse
    {
        $this->authorizeHardwareGateway($request);

        $validated = Validator::make($request->all(), [
            'sender_id' => ['required', 'string', 'max:40'],
            'sequence' => ['required', 'string', 'max:40'],
            'radio_auth' => ['nullable', 'in:hmac-sha256-v2'],
            'incident_landmark' => ['required_with:radio_auth', 'nullable', 'string', 'max:32'],
            'reporter_name' => ['nullable', 'string', 'max:48'],
            'gateway_id' => ['nullable', 'string', 'max:40'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'satellites' => ['nullable', 'integer', 'min:0', 'max:99'],
            'gateway_rssi' => ['nullable', 'numeric'],
            'gateway_snr' => ['nullable', 'numeric'],
            'receiver_rssi' => ['nullable', 'numeric'],
            'receiver_snr' => ['nullable', 'numeric'],
            'severity' => ['nullable', 'in:Minor,Serious,Fatal'],
            'incident_type' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:80'],
            'created_at' => ['nullable', 'string', 'max:60'],
            'hop' => ['nullable', 'integer', 'min:0', 'max:255'],
            'ttl' => ['nullable', 'integer', 'min:0', 'max:255'],
            'route' => ['nullable', 'string', 'max:200'],
            'battery' => ['nullable', 'string', 'max:20'],
            'rssi' => ['nullable', 'integer'],
            'snr' => ['nullable', 'numeric'],
        ])->validate();

        // During the coordinated firmware rollout, legacy gateways remain
        // readable and are explicitly labelled unauthenticated. V2 firmware
        // rejects unsigned radio traffic before using this token-protected API.
        if (($validated['radio_auth'] ?? null) === 'hmac-sha256-v2') {
            Validator::make($validated, [
                'incident_type' => ['required', 'in:Road Accident'],
                'description' => ['required', 'string', 'max:70'],
                'created_at' => ['required', 'regex:/^[0-9]{10}$/'],
                'incident_landmark' => ['required', function (string $attribute, mixed $value, \Closure $fail): void {
                    if (strlen((string) $value) > 32 || preg_match('/[|\r\n\t]/', (string) $value)) {
                        $fail('Use a short, single-line accident landmark.');
                    }
                }],
            ])->validate();
            $validated['created_at'] = \Carbon\CarbonImmutable::createFromTimestampUTC((int) $validated['created_at'])->toIso8601String();
        }

        $senderId = strtoupper(trim((string) $validated['sender_id']));
        $sequence = trim((string) $validated['sequence']);
        $reporterName = trim((string) ($validated['reporter_name'] ?? ''));
        $gatewayId = strtoupper(trim((string) ($validated['gateway_id'] ?? 'NODE_1')));
        abort_unless(
            preg_match('/^NODE_([1-9]|10)$/', $senderId) === 1 && isset(config('lora_nodes')[$senderId]),
            422,
            'Unknown registered LoRa node.'
        );
        $node = app(LoraNodeRegistry::class)->all()[$senderId] ?? [];
        $reporterName = $reporterName !== '' ? $reporterName : (string) ($node['node_name'] ?? $senderId);

        $ingestKey = $ingestor->loraKey($senderId, $sequence);

        if ($existing = $ingestor->existing($ingestKey)) {
            $this->retryReviewAcknowledgment($existing);
            $this->recordNodeSeen($senderId, $gatewayId, $validated, 'SOS');

            return response()->json([
                'message' => 'LoRa alert already exists.',
                'duplicate' => true,
                'data' => $this->reportPayload($existing),
            ]);
        }

        $latitude = $this->coordinateOrNull($node['latitude'] ?? null);
        $longitude = $this->coordinateOrNull($node['longitude'] ?? null);
        $nodeName = trim((string) ($node['node_name'] ?? $senderId));
        $locationLabel = trim((string) ($node['location_label'] ?? 'Location not registered'));
        $locationText = $this->locationText($latitude, $longitude, $senderId, $nodeName, $locationLabel, $gatewayId);
        $description = $this->descriptionFor($validated, $senderId, $sequence, $gatewayId);

        $result = $ingestor->persist($ingestKey, [
            'report_code' => 'LORA-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4)),
            'reported_by' => null,
            'reporter_name' => $reporterName,
            'reporter_contact' => null,
            'incident_type' => $validated['incident_type'] ?? 'LoRa Emergency Alert',
            'severity' => $validated['severity'] ?? 'Serious',
            'status' => 'received',
            'channel' => 'LoRa Mesh',
            'transmission_type' => 'lora',
            'location_text' => $locationText,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'description' => $description,
            'ai_summary' => null,
            'ai_confidence' => null,
            'ai_source' => null,
            'ai_status' => 'not_applicable',
            'ai_model_name' => null,
            'ai_model_version' => null,
            'ai_review_required' => false,
            'ai_probabilities' => null,
            'ai_processed_at' => null,
            'ai_error_message' => null,
            'evidence_type' => 'none',
            'coordination_log' => [
                [
                    'at' => now()->toIso8601String(),
                    'event' => 'lora_alert_ingested',
                    'sender_id' => $senderId,
                    'node_name' => $nodeName,
                    'location_label' => $locationLabel,
                    'reporter_name' => $reporterName,
                    'sequence' => $sequence,
                    'gateway_id' => $gatewayId,
                    'created_at' => $validated['created_at'] ?? null,
                    'hop' => (int) ($validated['hop'] ?? 0),
                    'ttl' => (int) ($validated['ttl'] ?? 0),
                    'route' => $validated['route'] ?? $senderId,
                    'battery' => $validated['battery'] ?? null,
                    'gateway_rssi' => $this->integerOrNull($validated['rssi'] ?? null),
                ],
            ],
            'transmitted_at' => now(),
            'status_updated_at' => now(),
            'hardware_source' => 'arduino_lora_gateway',
            'lora_incident_landmark' => $validated['incident_landmark'] ?? null,
            'lora_radio_auth' => $validated['radio_auth'] ?? null,
            'lora_sender_id' => $senderId,
            'lora_sequence' => $sequence,
            'lora_gateway_id' => $gatewayId,
            'lora_gateway_rssi' => $this->integerOrNull($validated['gateway_rssi'] ?? $validated['rssi'] ?? null),
            'lora_gateway_snr' => $this->floatOrNull($validated['gateway_snr'] ?? $validated['snr'] ?? null),
            'lora_receiver_rssi' => $this->integerOrNull($validated['receiver_rssi'] ?? null),
            'lora_receiver_snr' => $this->floatOrNull($validated['receiver_snr'] ?? null),
            'lora_satellites' => null,
        ]);
        $report = $result['report'];

        if (! $result['created']) {
            $this->retryReviewAcknowledgment($report);
            $this->recordNodeSeen($senderId, $gatewayId, $validated, 'SOS');

            return response()->json([
                'message' => 'LoRa alert already exists.',
                'duplicate' => true,
                'data' => $this->reportPayload($report),
            ]);
        }

        $this->recordNodeSeen($senderId, $gatewayId, $validated, 'SOS');

        IncidentFeedBroadcaster::dispatch($report, 'created');

        return response()->json([
            'message' => 'LoRa alert ingested successfully.',
            'duplicate' => false,
            'data' => $this->reportPayload($report),
        ], 201);
    }

    private function retryReviewAcknowledgment(IncidentReport $report): void
    {
        // A field node retries its SOS until it receives the admin's ACK.
        // A successful gateway broadcast alone does not confirm radio receipt.
        IncidentReport::query()->whereKey($report->id)
            ->whereNotNull('lora_ack_queued_at')
            ->whereNotNull('lora_ack_delivered_at')
            ->update(['lora_ack_delivered_at' => null]);
        $report->refresh();
    }

    private function authorizeHardwareGateway(Request $request): void
    {
        $expectedToken = (string) config('services.lora_ingest.token');

        abort_if(blank($expectedToken), 503, 'LoRa ingest token is not configured.');

        $providedToken = (string) (
            $request->bearerToken()
            ?: $request->header('X-STITCH-LORA-TOKEN')
            ?: $request->input('token')
        );

        abort_unless(
            $providedToken !== '' && hash_equals($expectedToken, $providedToken),
            401,
            'Invalid LoRa ingest token.'
        );
    }

    private function coordinateOrNull(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $coordinate = (float) $value;

        return abs($coordinate) < 0.000001 ? null : $coordinate;
    }

    private function integerOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) round((float) $value) : null;
    }

    private function floatOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? round((float) $value, 2) : null;
    }

    private function normalizedBattery(mixed $value): ?string
    {
        $battery = trim((string) $value);

        return $battery === '' || in_array(strtoupper($battery), ['NA', 'N/A', 'NOT MEASURED'], true)
            ? null
            : mb_substr($battery, 0, 20);
    }

    private function recordNodeSeen(string $senderId, string $gatewayId, array $payload, string $packetType): void
    {
        LoraNodeState::query()->updateOrCreate(
            ['node_id' => $senderId],
            [
                'gateway_id' => $gatewayId,
                'last_seen_at' => now(),
                'last_known_route' => $payload['route'] ?? $senderId.'>NODE_1',
                'battery' => $this->normalizedBattery($payload['battery'] ?? null),
                'hop' => (int) ($payload['hop'] ?? 0),
                'ttl' => (int) ($payload['ttl'] ?? 0),
                'rssi' => $this->integerOrNull($payload['rssi'] ?? $payload['gateway_rssi'] ?? null),
                'last_packet_type' => $packetType,
            ]
        );
    }

    private function locationText(
        ?float $latitude,
        ?float $longitude,
        string $senderId,
        string $nodeName,
        string $locationLabel,
        string $gatewayId
    ): string
    {
        if ($latitude !== null && $longitude !== null) {
            return $nodeName.' ('.$senderId.') at '.$locationLabel.' | registered coordinates '.$latitude.', '.$longitude.' | gateway '.$gatewayId;
        }

        return $nodeName.' ('.$senderId.') | coordinates not configured | gateway '.$gatewayId;
    }

    private function descriptionFor(array $payload, string $senderId, string $sequence, string $gatewayId): string
    {
        if (filled($payload['description'] ?? null)) {
            return trim((string) $payload['description']);
        }

        return 'SOS received from '.$senderId.' via '.$gatewayId.' (message '.$sequence.'). No optional description.';
    }

    private function reportPayload(IncidentReport $report): array
    {
        return [
            'id' => $report->id,
            'report_code' => $report->report_code,
            'reporter_name' => $report->reporter_name,
            'incident_type' => $report->incident_type,
            'severity' => $report->severity,
            'status' => $report->status,
            'transmission_type' => $report->transmission_type,
            'location_text' => $report->location_text,
            'latitude' => $report->latitude,
            'longitude' => $report->longitude,
            'description' => $report->description,
            'lora_sender_id' => $report->lora_sender_id,
            'lora_sequence' => $report->lora_sequence,
            'lora_gateway_id' => $report->lora_gateway_id,
            'lora_ack_queued_at' => $report->lora_ack_queued_at?->toIso8601String(),
            'lora_ack_delivered_at' => $report->lora_ack_delivered_at?->toIso8601String(),
        ];
    }
}
