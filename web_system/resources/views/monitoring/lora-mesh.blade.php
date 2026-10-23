@extends('layouts.app')

@section('title', 'LoRa Mesh Monitor')
@section('page_label', 'Field Network')
@section('page_heading', 'LoRa Mesh Monitor')
@section('page_subheading', 'Live node health and radio telemetry.')

@section('content')
    <section class="panel lora-mesh-panel">
        <div class="panel-head lora-mesh-toolbar">
            <div class="panel-heading">
                <h2 class="panel-title">Mesh nodes</h2>
                <div class="lora-mesh-refresh-status">
                    <span data-lora-refresh-state role="status" aria-live="polite">Connecting</span>
                    <span>Checked <time data-lora-last-checked>—</time></span>
                </div>
            </div>
            <div class="lora-mesh-actions">
                <a href="{{ route('lora-reports.index') }}" class="btn btn-primary">LoRa reports</a>
                <a href="{{ route('monitoring') }}" class="btn btn-secondary">Live Monitoring</a>
                <button type="button" class="btn btn-secondary" data-lora-refresh>Refresh</button>
            </div>
        </div>
        <div class="lora-mesh-tools">
            <label class="lora-node-search">
                <span class="sr-only">Find a node</span>
                <input type="search" data-lora-search placeholder="Search node, status, route or location" autocomplete="off">
            </label>
            <details class="lora-mesh-guide">
                <summary>Status &amp; telemetry guide</summary>
                <div>
                    <p><strong>Reachable:</strong> a heartbeat or SOS arrived within 30 seconds. <strong>Unreachable:</strong> no heartbeat for over 30 seconds. <strong>Waiting:</strong> recently detected by a connected peer, awaiting this node's heartbeat. <strong>Unavailable:</strong> no heartbeat yet, or none for over 2 minutes, with no recent discovery. Silence cannot confirm whether a node is powered off.</p>
                    <p><strong>Delay (RTT):</strong> a measured trip to the website and back, including LoRa relays. Samples expire after 120 seconds. It is not upload speed or one-way delivery time.</p>
                    <p><strong>Battery:</strong> measured voltage only. A missing reading may need sensor/divider wiring or enabled firmware; no battery percentage is inferred. Signal shows the last received RSSI; SNR and sample times are in each node's details.</p>
                    <p>Updates run every 5 seconds and pause while this tab is hidden.</p>
                </div>
            </details>
        </div>

        <div data-lora-node-list aria-busy="true">
            @include('monitoring.partials.lora-node-list', [
                'loraNodes' => $loraNodes,
                'activeNodeCount' => $activeNodeCount,
                'latestNodeUpdate' => $latestNodeUpdate,
            ])
        </div>
    </section>

    <script>
        (() => {
            const list = document.querySelector('[data-lora-node-list]');
            const search = document.querySelector('[data-lora-search]');
            const lastChecked = document.querySelector('[data-lora-last-checked]');
            const refreshState = document.querySelector('[data-lora-refresh-state]');
            const refreshButton = document.querySelector('[data-lora-refresh]');
            const endpoint = @json(route('monitoring.lora-mesh.nodes'));
            let refreshing = false;

            const applySearch = () => {
                const term = (search?.value || '').trim().toLowerCase();
                let visible = 0;
                list?.querySelectorAll('[data-lora-node-card]').forEach((card) => {
                    const matches = !term || (card.dataset.search || '').includes(term);
                    card.hidden = !matches;
                    if (matches) visible++;
                });
                const empty = list?.querySelector('[data-lora-filter-empty]');
                if (empty) empty.hidden = !term || visible > 0;
            };

            const refreshNodes = async () => {
                if (refreshing || document.hidden || !list) return;
                refreshing = true;
                refreshButton.disabled = true;
                list.setAttribute('aria-busy', 'true');
                try {
                    const response = await fetch(endpoint, {
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        cache: 'no-store',
                    });
                    if (!response.ok || response.redirected) throw new Error('Monitor request failed');
                    const markup = await response.text();
                    const expanded = new Set(Array.from(list.querySelectorAll('[data-lora-node-card][open]'), node => node.dataset.nodeId));
                    const focusedNode = document.activeElement?.closest('[data-lora-node-card]')?.dataset.nodeId;
                    list.innerHTML = markup;
                    list.querySelectorAll('[data-lora-node-card]').forEach((node) => {
                        node.open = expanded.has(node.dataset.nodeId);
                        if (node.dataset.nodeId === focusedNode) node.querySelector('summary')?.focus({ preventScroll: true });
                    });
                    lastChecked.textContent = new Date().toLocaleTimeString();
                    refreshState.textContent = 'Live · refreshes every 5 sec';
                    refreshState.dataset.state = 'live';
                    applySearch();
                } catch (error) {
                    refreshState.textContent = 'Refresh failed · showing previous data';
                    refreshState.dataset.state = 'error';
                } finally {
                    list.setAttribute('aria-busy', 'false');
                    refreshing = false;
                    refreshButton.disabled = false;
                }
            };

            search?.addEventListener('input', applySearch);
            refreshButton?.addEventListener('click', refreshNodes);
            document.addEventListener('visibilitychange', () => {
                if (!document.hidden) refreshNodes();
            });
            window.setInterval(refreshNodes, 5000);
            refreshNodes();
        })();
    </script>
@endsection
