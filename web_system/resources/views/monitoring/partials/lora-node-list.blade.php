@php
    $gatewayNode = $loraNodes->firstWhere('sender_id', 'NODE_1');
    $gatewayActive = (bool) ($gatewayNode['recently_heard'] ?? false);
@endphp
<div class="lora-mesh-live-fragment" data-active-node-count="{{ $activeNodeCount }}" data-total-node-count="{{ $loraNodes->count() }}">
    <div class="summary-strip lora-mesh-summary-strip">
        <article class="summary-card">
            <span>Active within 30 seconds</span>
            <strong>{{ $activeNodeCount }} / {{ $loraNodes->count() }}</strong>
            <p>Heartbeat or SOS recently received by NODE_1/server</p>
        </article>
        <article class="summary-card">
            <span>NODE_1 gateway</span>
            <strong>{{ $gatewayActive ? 'ACTIVE' : (($gatewayNode['last_seen'] ?? null) ? 'UNREACHABLE' : 'AWAITING') }}</strong>
            <p>{{ $gatewayNode['last_seen']?->timezone(config('app.timezone'))->diffForHumans() ?? 'Waiting for gateway heartbeat' }}</p>
        </article>
        <article class="summary-card">
            <span>Latest node update</span>
            <strong>{{ $latestNodeUpdate?->timezone(config('app.timezone'))->format('g:i:s A') ?? 'No updates yet' }}</strong>
            <p>{{ $latestNodeUpdate?->timezone(config('app.timezone'))->format('M j, Y') ?? 'Waiting for the first heartbeat or SOS' }}</p>
        </article>
        <article class="summary-card">
            <span>Registered mesh nodes</span>
            <strong>{{ $loraNodes->count() }}</strong>
            <p>NODE_1 gateway + NODE_2 through NODE_10</p>
        </article>
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
                $status = $node['status'] ?? ($node['recently_heard'] ? 'ACTIVE' : ($node['has_report'] ? 'UNREACHABLE' : 'AWAITING'));
                $tagClass = $status === 'ACTIVE' ? 'green' : ($status === 'UNREACHABLE' ? 'amber' : 'neutral');
            @endphp
            <article class="lora-node-card" data-lora-node-card data-search="{{ $searchText }}">
                <div class="lora-node-card-head">
                    <div>
                        <p class="panel-kicker">{{ $node['sender_id'] }} · {{ $node['role'] ?? 'Field Mesh Node' }}</p>
                        <h3>{{ $node['node_name'] }}</h3>
                    </div>
                    <span class="tag {{ $tagClass }}">{{ $status }}</span>
                </div>

                <p class="lora-node-location">{{ $node['location_label'] }}</p>

                <dl class="lora-node-details">
                    <div><dt>Last update</dt><dd>{{ $node['last_seen']?->timezone(config('app.timezone'))->format('M j, g:i:s A') ?? 'Never' }}@if ($node['last_seen']) <span>{{ $node['last_seen']->diffForHumans() }}</span>@endif</dd></div>
                    <div><dt>Packet</dt><dd>{{ $node['last_packet_type'] ?: 'None' }}{{ $node['hop'] !== null ? ' · '.$node['hop'].' hop(s)' : '' }}</dd></div>
                    <div><dt>Route</dt><dd>{{ $node['route'] ?: 'Unknown' }}</dd></div>
                    <div><dt>Gateway</dt><dd>{{ $node['gateway_id'] ?: ($node['is_gateway'] ? 'NODE_1 (self)' : 'Not reported') }}</dd></div>
                    <div><dt>Nearby peers</dt><dd>{{ count($node['peers']) ? implode(', ', $node['peers']) : 'Not reported' }}</dd></div>
                    <div><dt>Fixed coordinates</dt><dd>{{ $coordinates }}</dd></div>
                    <div><dt>Battery voltage</dt><dd>{{ $node['battery'] ?: 'No voltage reading' }}@unless ($node['battery']) <span>Sensor/divider wiring and enabled firmware required.</span>@endunless</dd></div>
                    <div><dt>Node ↔ website delay (RTT)</dt><dd>@if ($node['round_trip_ms'] !== null && $node['round_trip_fresh']){{ number_format($node['round_trip_ms']) }} ms<span>{{ $node['is_gateway'] ? 'HTTPS request + response' : 'LoRa + HTTPS request + return via LoRa' }}</span>@else{{ $node['round_trip_ms'] !== null ? 'Sample expired' : 'Awaiting timing sample' }}@endif</dd></div>
                    <div><dt>Timing sample</dt><dd>{{ $node['round_trip_measured_at']?->diffForHumans() ?? 'Updated telemetry firmware required' }}</dd></div>
                    <div><dt>Radio signal</dt><dd>RSSI {{ $node['gateway_rssi'] ?? '—' }} dBm<span> · SNR {{ $node['gateway_snr'] ?? '—' }} dB</span></dd></div>
                </dl>
            </article>
        @empty
            <div class="data-empty lora-node-empty">No LoRa nodes are registered yet.</div>
        @endforelse
    </div>
    <p class="lora-node-filter-empty" data-lora-filter-empty hidden>No nodes match that search.</p>
</div>
