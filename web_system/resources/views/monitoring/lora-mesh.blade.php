@extends('layouts.app')

@section('title', 'LoRa Mesh Monitor')
@section('page_label', 'Field Network')
@section('page_heading', 'LoRa Mesh Monitor')
@section('page_subheading', 'Track node health, battery voltage, website round-trip delay, routes, and radio signal.')

@section('hero')
    <section class="hero-card lora-mesh-hero">
        <div class="hero-grid">
            <div class="hero-copy">
                <p class="eyebrow">Live node health</p>
                <h2>Field network status</h2>
                <p>This page refreshes node telemetry every five seconds. A node is ACTIVE when its latest heartbeat or emergency packet arrived within the last 30 seconds; after that it is UNREACHABLE until a new packet is received.</p>
                <div class="hero-actions">
                    <a href="{{ route('reports.index', ['transmission' => 'lora']) }}" class="btn btn-primary">View LoRa reports</a>
                    <a href="{{ route('monitoring') }}" class="btn btn-secondary">Back to Live Monitoring</a>
                    <button type="button" class="btn btn-primary" data-lora-refresh>Refresh now</button>
                </div>
            </div>
            <div class="hero-metrics lora-mesh-hero-metrics">
                <article class="metric-card"><span>Live refresh</span><strong>Every 5 sec</strong><p>Pauses while this tab is hidden.</p></article>
                <article class="metric-card"><span>Last checked</span><strong data-lora-last-checked>Loading…</strong><p data-lora-refresh-state role="status" aria-live="polite">Connecting to the monitor.</p></article>
            </div>
        </div>
    </section>
@endsection

@section('content')
    <section class="panel lora-mesh-panel">
        <div class="panel-head lora-mesh-toolbar">
            <div class="panel-heading">
                <p class="panel-kicker">Heartbeat and route telemetry</p>
                <h2 class="panel-title">Registered field nodes</h2>
                <p class="section-copy">A node with no update for more than 30 seconds is shown as UNREACHABLE, which means it needs connectivity inspection, not that the hardware is automatically defective.</p>
                <p class="section-copy">Delay is a measured round trip to the website and back, including LoRa relays for field nodes. It is not one-way delivery time or upload speed. Battery voltage requires an installed voltage divider; no battery percentage is inferred.</p>
            </div>
            <label class="lora-node-search">
                <span>Find a node</span>
                <input type="search" data-lora-search placeholder="Node, route, or location" autocomplete="off">
            </label>
        </div>

        <div data-lora-node-list aria-live="polite" aria-busy="true">
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
                list.setAttribute('aria-busy', 'true');
                try {
                    const response = await fetch(endpoint, {
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        cache: 'no-store',
                    });
                    if (!response.ok || response.redirected) throw new Error('Monitor request failed');
                    list.innerHTML = await response.text();
                    lastChecked.textContent = new Date().toLocaleTimeString();
                    refreshState.textContent = 'Connected · updates are current';
                    applySearch();
                } catch (error) {
                    refreshState.textContent = 'Could not refresh · showing the last received data';
                } finally {
                    list.setAttribute('aria-busy', 'false');
                    refreshing = false;
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
