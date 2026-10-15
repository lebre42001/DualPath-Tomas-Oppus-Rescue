<?php

namespace App\Support;

use App\Models\IncidentReport;
use App\Models\LoraNodeState;
use Illuminate\Support\Collection;

class LoraMeshNodeDirectory
{
    private const NODE_TIMEOUT_SECONDS = 30;

    /** @return Collection<int, array<string, mixed>> */
    public function all(): Collection
    {
        // The live monitor represents the deployed NODE_1 ... NODE_10 mesh.
        // Legacy sender IDs may stay in the location registry for old reports.
        $meshNodeIds = collect(range(1, 10))
            ->map(fn (int $number): string => 'NODE_'.$number);

        $latestReports = IncidentReport::query()
            ->where('transmission_type', 'lora')
            ->whereIn('lora_sender_id', $meshNodeIds)
            ->latest('created_at')
            ->take(100)
            ->get([
                'id', 'lora_sender_id', 'lora_gateway_id', 'created_at',
                'latitude', 'longitude', 'lora_gateway_rssi', 'lora_gateway_snr',
                'lora_receiver_rssi', 'lora_receiver_snr',
            ])
            ->unique('lora_sender_id')
            ->keyBy('lora_sender_id');

        $registry = config('lora_nodes', []);
        $states = LoraNodeState::query()
            ->whereIn('node_id', $meshNodeIds)
            ->get()
            ->keyBy('node_id');

        return $meshNodeIds
            ->map(function (string $senderId) use ($registry, $latestReports, $states): array {
                /** @var IncidentReport|null $report */
                $report = $latestReports->get($senderId);
                $state = $states->get($senderId);
                $node = $registry[$senderId] ?? [];
                $lastSeen = $state?->last_seen_at ?? $report?->created_at;
                $recentlyHeard = $lastSeen?->greaterThanOrEqualTo(now()->subSeconds(self::NODE_TIMEOUT_SECONDS)) ?? false;
                $hasEverBeenSeen = $lastSeen !== null;
                $status = $recentlyHeard
                    ? 'ACTIVE'
                    : ($hasEverBeenSeen ? 'UNREACHABLE' : 'AWAITING');

                return [
                    'sender_id' => $senderId,
                    'node_name' => $node['node_name'] ?? $senderId,
                    'role' => $senderId === 'NODE_1' ? 'Gateway / Server Bridge' : 'Field Mesh Node',
                    'is_gateway' => $senderId === 'NODE_1',
                    'status' => $status,
                    'location_label' => $node['location_label'] ?? 'Not registered',
                    'gateway_id' => $state?->gateway_id ?? $report?->lora_gateway_id,
                    'last_seen' => $lastSeen,
                    'has_report' => $hasEverBeenSeen,
                    'recently_heard' => $recentlyHeard,
                    'route' => $state?->last_known_route,
                    'peers' => $state?->known_peers ?? [],
                    'battery' => $state?->battery,
                    'hop' => $state?->hop,
                    'ttl' => $state?->ttl,
                    'last_packet_type' => $state?->last_packet_type ?? ($report ? 'SOS' : null),
                    'latitude' => is_numeric($node['latitude'] ?? null) ? (float) $node['latitude'] : null,
                    'longitude' => is_numeric($node['longitude'] ?? null) ? (float) $node['longitude'] : null,
                    'gateway_rssi' => $state?->rssi ?? $report?->lora_gateway_rssi,
                    'gateway_snr' => $report?->lora_gateway_snr,
                    'receiver_rssi' => $report?->lora_receiver_rssi,
                    'receiver_snr' => $report?->lora_receiver_snr,
                ];
            });
    }
}
