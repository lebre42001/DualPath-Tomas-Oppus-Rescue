<?php

namespace App\Support;

use App\Models\IncidentReport;
use App\Models\LoraNodeState;
use Illuminate\Support\Collection;

class LoraMeshNodeDirectory
{
    public const NODE_TIMEOUT_SECONDS = 30;
    public const NODE_UNAVAILABLE_SECONDS = 120;

    /** @return Collection<int, array<string, mixed>> */
    public function all(): Collection
    {
        // The live monitor represents the deployed NODE_1 ... NODE_10 mesh.
        // Legacy sender IDs may stay in the location registry for old reports.
        $meshNodeIds = collect(range(1, 10))
            ->map(fn (int $number): string => 'NODE_'.$number);

        $latestReports = IncidentReport::query()
            ->where('transmission_type', 'lora')
            ->whereIn('id', IncidentReport::query()
                ->selectRaw('MAX(id)')
                ->where('transmission_type', 'lora')
                ->whereIn('lora_sender_id', $meshNodeIds)
                ->groupBy('lora_sender_id'))
            ->get([
                'id', 'lora_sender_id', 'lora_gateway_id', 'created_at',
                'latitude', 'longitude', 'lora_gateway_rssi', 'lora_gateway_snr',
                'lora_receiver_rssi', 'lora_receiver_snr',
                'report_code', 'status', 'lora_sequence', 'lora_ack_queued_at', 'lora_ack_delivered_at',
            ])
            ->unique('lora_sender_id')
            ->keyBy('lora_sender_id');

        $registry = app(LoraNodeRegistry::class)->all();
        $states = LoraNodeState::query()
            ->whereIn('node_id', $meshNodeIds)
            ->get()
            ->keyBy('node_id');

        $now = now();
        $reachableSince = $now->copy()->subSeconds(self::NODE_TIMEOUT_SECONDS);
        $unavailableSince = $now->copy()->subSeconds(self::NODE_UNAVAILABLE_SECONDS);
        $discoveries = [];
        foreach ($states as $observer) {
            // Only a fresh heartbeat carries a current discovery snapshot.
            // An SOS can refresh last_seen_at while retaining an old peer list.
            if ($observer->last_packet_type !== 'HB' || ! $observer->last_seen_at?->greaterThanOrEqualTo($reachableSince)) {
                continue;
            }
            foreach ($observer->known_peers ?? [] as $peerId) {
                if ($peerId === $observer->node_id || ! $meshNodeIds->contains($peerId)) {
                    continue;
                }
                $discoveries[$peerId][] = [
                    'node_id' => $observer->node_id,
                    'observed_at' => $observer->last_seen_at,
                ];
            }
        }

        return $meshNodeIds
            ->map(function (string $senderId) use ($registry, $latestReports, $states, $reachableSince, $unavailableSince, $discoveries): array {
                /** @var IncidentReport|null $report */
                $report = $latestReports->get($senderId);
                $state = $states->get($senderId);
                $node = $registry[$senderId] ?? [];
                $lastSeen = $state?->last_seen_at ?? $report?->created_at;
                $recentlyHeard = $lastSeen?->greaterThanOrEqualTo($reachableSince) ?? false;
                $hasEverBeenSeen = $lastSeen !== null;
                $recentDiscoveries = collect($discoveries[$senderId] ?? [])
                    ->filter(fn (array $discovery): bool => $lastSeen === null || $discovery['observed_at']->greaterThan($lastSeen));
                $status = match (true) {
                    $recentlyHeard => 'REACHABLE',
                    $recentDiscoveries->isNotEmpty() => 'WAITING',
                    $lastSeen?->greaterThanOrEqualTo($unavailableSince) ?? false => 'UNREACHABLE',
                    default => 'UNAVAILABLE',
                };
                $statusDetail = match ($status) {
                    'REACHABLE' => 'Heartbeat or SOS received within 30 seconds.',
                    'WAITING' => 'Recently detected by '.implode(', ', $recentDiscoveries->pluck('node_id')->all()).'; waiting for this node\'s heartbeat.',
                    'UNREACHABLE' => 'No heartbeat for over 30 seconds. The connection may be interrupted.',
                    default => $hasEverBeenSeen
                        ? 'No heartbeat for over 2 minutes and no recent peer discovery. Power state is unknown.'
                        : 'No heartbeat or recent peer discovery received. Power state is unknown.',
                };

                return [
                    'sender_id' => $senderId,
                    'node_name' => $node['node_name'] ?? $senderId,
                    'role' => $senderId === 'NODE_1' ? 'Gateway / Server Bridge' : 'Field Mesh Node',
                    'is_gateway' => $senderId === 'NODE_1',
                    'status' => $status,
                    'status_detail' => $statusDetail,
                    'location_label' => $node['location_label'] ?? 'Not registered',
                    'gateway_id' => $state?->gateway_id ?? $report?->lora_gateway_id,
                    'last_seen' => $lastSeen,
                    'has_report' => $hasEverBeenSeen,
                    'latest_report' => $report,
                    'recently_heard' => $recentlyHeard,
                    'route' => $state?->last_known_route,
                    'peers' => $state?->known_peers ?? [],
                    'battery' => $state?->battery,
                    'round_trip_ms' => $state?->round_trip_ms,
                    'round_trip_measured_at' => $state?->round_trip_measured_at,
                    'round_trip_fresh' => $state?->round_trip_measured_at?->greaterThanOrEqualTo(now()->subSeconds(120)) ?? false,
                    'hop' => $state?->hop,
                    'ttl' => $state?->ttl,
                    'last_packet_type' => $state?->last_packet_type ?? ($report ? 'SOS' : null),
                    'latitude' => is_numeric($node['latitude'] ?? null) ? (float) $node['latitude'] : null,
                    'longitude' => is_numeric($node['longitude'] ?? null) ? (float) $node['longitude'] : null,
                    'gateway_rssi' => $state?->rssi ?? $report?->lora_gateway_rssi,
                    'gateway_snr' => $state?->snr ?? $report?->lora_gateway_snr,
                    'receiver_rssi' => $report?->lora_receiver_rssi,
                    'receiver_snr' => $report?->lora_receiver_snr,
                ];
            });
    }

    /** @return Collection<int, array<string, mixed>> */
    public function mapPoints(): Collection
    {
        return $this->all()->filter(fn (array $node): bool =>
            $node['latitude'] !== null && $node['longitude'] !== null
            && abs($node['latitude']) <= 90 && abs($node['longitude']) <= 180
        )->map(fn (array $node): array => [
            'id' => 'node-'.$node['sender_id'],
            'map_role' => 'lora_node',
            'node_id' => $node['sender_id'],
            'incident_type' => $node['node_name'],
            'location_text' => $node['location_label'],
            'latitude' => $node['latitude'],
            'longitude' => $node['longitude'],
            'connection_status' => $node['status'],
            'status_detail' => $node['status_detail'],
            'last_seen' => $node['last_seen']?->timezone(config('app.timezone'))->format('M j, Y g:i:s A') ?? 'Never received',
        ])->values();
    }
}
