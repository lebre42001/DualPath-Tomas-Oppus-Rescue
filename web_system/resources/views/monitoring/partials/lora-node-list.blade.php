@php
    $gatewayNode = $loraNodes->firstWhere('sender_id', 'NODE_1');
@endphp
<div class="lora-mesh-live-fragment" data-active-node-count="{{ $activeNodeCount }}" data-total-node-count="{{ $loraNodes->count() }}">
    <div class="summary-strip lora-mesh-summary-strip">
        <article class="summary-card">
            <span>Reachable nodes</span>
            <strong>{{ $activeNodeCount }} / {{ $loraNodes->count() }}</strong>
        </article>
        <article class="summary-card">
            <span>Gateway &middot; NODE_1</span>
            <strong class="lora-gateway-state" title="{{ $gatewayNode['status_detail'] ?? '' }}">{{ $gatewayNode['status'] ?? 'UNAVAILABLE' }}</strong>
        </article>
        <article class="summary-card">
            <span>Latest packet</span>
            <strong class="lora-latest-packet" title="{{ $latestNodeUpdate?->timezone(config('app.timezone'))->format('M j, Y g:i:s A') }}">{{ $latestNodeUpdate?->diffForHumans() ?? 'None yet' }}</strong>
        </article>
    </div>

    <div class="lora-node-columns" aria-hidden="true">
        <span>Node</span><span>Status</span><span>Last seen</span><span>Battery</span><span>Delay (RTT)</span><span>Radio signal</span><span></span>
    </div>
    <div class="lora-node-grid">
        @forelse ($loraNodes as $node)
            @php
                $searchText = strtolower(implode(' ', [
                    $node['node_name'], $node['sender_id'], $node['role'] ?? '', $node['location_label'],
                    $node['route'] ?? '', $node['gateway_id'] ?? '', $node['status'] ?? '',
                ]));
                $coordinates = $node['latitude'] !== null && $node['longitude'] !== null
                    ? number_format($node['latitude'], 6).', '.number_format($node['longitude'], 6)
                    : 'Coordinates not registered';
                $status = $node['status'] ?? 'UNAVAILABLE';
                $tagClass = match ($status) {
                    'REACHABLE' => 'green',
                    'UNREACHABLE' => 'amber',
                    'WAITING' => 'blue',
                    default => 'neutral',
                };
                $customName = ! preg_match('/^(Gateway|Field) Node \d+$/', $node['node_name']);
            @endphp
            <details class="lora-node-card" data-lora-node-card data-node-id="{{ $node['sender_id'] }}" data-search="{{ $searchText }}">
                <summary class="lora-node-row">
                    <span class="lora-node-identity">
                        <strong>{{ $node['sender_id'] }}</strong>
                        @if ($customName)<small>{{ $node['node_name'] }}</small>
                        @elseif ($node['is_gateway'])<small>Gateway</small>@endif
                    </span>
                    <span class="lora-node-status"><span class="tag {{ $tagClass }}" title="{{ $node['status_detail'] ?? '' }}">{{ $status }}</span></span>
                    <span class="lora-node-reading" title="{{ $node['last_seen']?->timezone(config('app.timezone'))->format('M j, Y g:i:s A') }}">
                        <span class="lora-node-cell-label">Last seen</span>{{ $node['last_seen']?->diffForHumans() ?? 'Never' }}
                    </span>
                    <span class="lora-node-reading">
                        <span class="lora-node-cell-label">Battery</span><span title="{{ $node['battery'] ?: 'No voltage reading' }}">{{ $node['battery'] ?: '—' }}</span>
                    </span>
                    <span class="lora-node-reading">
                        <span class="lora-node-cell-label">Delay (RTT)</span>
                        @if ($node['round_trip_ms'] !== null && $node['round_trip_fresh'])
                            {{ number_format($node['round_trip_ms']) }} ms
                        @elseif ($node['round_trip_ms'] !== null)
                            <span class="lora-node-missing" title="Sample expired">Expired</span>
                        @else
                            <span class="lora-node-missing" title="Awaiting timing sample">—</span>
                        @endif
                    </span>
                    <span class="lora-node-reading" title="Last received RSSI">
                        <span class="lora-node-cell-label">Radio signal</span>{{ $node['gateway_rssi'] !== null ? $node['gateway_rssi'].' dBm' : '—' }}
                    </span>
                    <span class="lora-node-expand" aria-hidden="true"></span>
                </summary>

                <div class="lora-node-expanded">
                    <dl class="lora-node-details">
                        <div><dt>Connection status</dt><dd>{{ $node['status_detail'] ?? $status }}</dd></div>
                        <div><dt>Location</dt><dd>{{ $node['location_label'] }}</dd></div>
                        <div><dt>Last update</dt><dd>{{ $node['last_seen']?->timezone(config('app.timezone'))->format('M j, Y g:i:s A') ?? 'Never received' }}</dd></div>
                        <div><dt>Route</dt><dd>{{ $node['route'] ?: 'Unknown' }}</dd></div>
                        <div><dt>Gateway</dt><dd>{{ $node['gateway_id'] ?: ($node['is_gateway'] ? 'NODE_1 (self)' : 'Not reported') }}</dd></div>
                        <div><dt>Packet &amp; hops</dt><dd>{{ $node['last_packet_type'] ?: 'None' }}{{ $node['hop'] !== null ? ' · '.$node['hop'].' hop(s)' : '' }}@if ($node['ttl'] !== null) &middot; TTL {{ $node['ttl'] }}@endif</dd></div>
                        <div><dt>Nearby peers</dt><dd>{{ count($node['peers']) ? implode(', ', $node['peers']) : 'Not reported' }}</dd></div>
                        <div><dt>Fixed coordinates</dt><dd>{{ $coordinates }}</dd></div>
                        <div><dt>Signal quality (SNR)</dt><dd>{{ $node['gateway_snr'] !== null ? $node['gateway_snr'].' dB' : 'Not reported' }}</dd></div>
                        <div><dt>Timing sample</dt><dd>{{ $node['round_trip_measured_at']?->timezone(config('app.timezone'))->format('M j, Y g:i:s A') ?? 'Awaiting timing sample' }}</dd></div>
                        <div><dt>Battery voltage</dt><dd>{{ $node['battery'] ?: 'No voltage reading' }}</dd></div>
                        <div><dt>Submission protection</dt><dd>@if ($node['blocked_submissions'] !== null){{ number_format($node['blocked_submissions']) }} blocked submission attempt(s) since this node restarted. A blocked repeat is not proof of a prank.@else Not reported by installed firmware @endif</dd></div>
                        <div><dt>Radio authentication</dt><dd>{{ $node['radio_auth'] === 'hmac-sha256-v2' ? 'Authenticated firmware v2' : 'Not reported by installed firmware' }}@if ($node['radio_rejections'] !== null) · {{ number_format($node['radio_rejections']) }} rejected frame(s) since restart @endif</dd></div>
                        <div><dt>Protection last reported</dt><dd>{{ $node['guard_reported_at']?->timezone(config('app.timezone'))->format('M j, Y g:i:s A') ?? 'Not yet reported' }}@if (! $node['recently_heard']) (stale)@endif</dd></div>
                    </dl>
                </div>
            </details>
        @empty
            <div class="data-empty lora-node-empty">No LoRa nodes are registered yet.</div>
        @endforelse
    </div>
    <p class="lora-node-filter-empty" data-lora-filter-empty hidden>No nodes match that search.</p>
</div>
