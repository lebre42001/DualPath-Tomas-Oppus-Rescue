@extends('layouts.app')

@php
    $statusTone = static fn (string $status): string => match ($status) {
        'rejected' => 'red',
        'resolved' => 'green',
        'responding', 'dispatched', 'acknowledged' => 'blue',
        default => 'neutral',
    };
    $reviewUrl = static fn ($report): string => route($report->transmission_type === 'lora' ? 'lora-reports.index' : 'reports.index', [
        'review' => $report->id,
    ]).'#incident-report-'.$report->id;
@endphp

@section('title', 'Overview')
@section('page_label', 'Admin Response')
@section('page_heading', 'Overview')
@section('page_subheading', 'Review urgent reports, response activity, and system readiness.')

@section('content')
    <section class="command-stat-grid admin-overview-stats" aria-label="Response summary">
        <article class="command-stat urgent">
            <span>Needs attention</span>
            <strong>{{ $stats['unassigned_reports'] }}</strong>
            <p>Unassigned incidents</p>
        </article>
        <article class="command-stat">
            <span>Active reports</span>
            <strong data-live-stat="active">{{ $stats['active_reports'] }}</strong>
            <p>Open incidents</p>
        </article>
        <article class="command-stat danger">
            <span>Critical / Fatal</span>
            <strong data-live-stat="fatal">{{ $stats['fatal_alerts'] }}</strong>
            <p>Highest-priority cases</p>
        </article>
        <article class="command-stat">
            <span>Resolved today</span>
            <strong>{{ $stats['resolved_today'] }}</strong>
            <p>Closed today</p>
        </article>
    </section>

    <div class="admin-overview-toolbar">
        <nav class="button-row" aria-label="Response shortcuts">
            <a href="{{ route('monitoring') }}" class="btn btn-primary">Live Monitoring</a>
            <a href="{{ route('reports.index') }}" class="btn btn-secondary">Online Report</a>
            <a href="{{ route('lora-reports.index') }}" class="btn btn-secondary">LoRa Reports</a>
        </nav>
        <div class="admin-account-counts" aria-label="Registered accounts">
            <a href="{{ route('civilian-accounts.index') }}"><strong>{{ $stats['civilians'] }}</strong> Civilians</a>
            <span><strong>{{ $stats['responders'] }}</strong> Admins</span>
        </div>
    </div>

    <section class="command-dashboard-grid">
        <article class="panel command-focus-panel">
            <div class="panel-head command-panel-head">
                <div class="panel-heading">
                    <p class="panel-kicker">Review First</p>
                    <h2 class="panel-title">Reports needing review</h2>
                    <p class="section-copy">Review these reports first.</p>
                </div>
                <div class="command-review-toolbar">
                    <div class="command-pager" data-command-pager-controls hidden>
                        <button type="button" class="command-page-btn" data-command-page-action="prev" aria-label="Show previous review reports" disabled>&larr;</button>
                        <span class="command-page-count" data-command-page-label>1 / 1</span>
                        <button type="button" class="command-page-btn" data-command-page-action="next" aria-label="Show next review reports">&rarr;</button>
                    </div>
                    <div class="command-review-controls" data-command-review-controls hidden>
                        <button type="button" class="command-review-nav" data-command-review-nav="up" aria-label="Show previous review reports" disabled>&uarr;</button>
                        <button type="button" class="command-review-nav" data-command-review-nav="down" aria-label="Show next review reports">&darr;</button>
                    </div>
                    <a href="{{ route('reports.index') }}" class="btn btn-secondary">Online Report</a>
                </div>
            </div>

            <div class="command-review-window" data-command-review-window data-page-size="2">
                <div class="command-review-list" data-command-review-list data-command-paged-list data-page-size="2">
                    @forelse ($moderationQueue as $report)
                        <article class="command-review-card" data-command-review-item data-command-page-item>
                            <div class="command-review-main">
                                <span class="incident-code">{{ $report->report_code }}</span>
                                <h3>{{ $report->incident_type }}</h3>
                                <p><strong>{{ $report->reporter_name ?: 'Unknown sender' }}</strong></p>
                                <p>{{ $report->location_text }}</p>
                            </div>
                            <div class="command-review-meta">
                                @include('reports.partials.ai-analysis', ['report' => $report, 'compact' => true])
                                @if ($report->reported_fatality_flag)
                                    <span class="tag red">POSSIBLE FATALITY · VERIFY NOW</span>
                                @endif
                                <span class="tag {{ $report->transmission_type === 'lora' ? 'amber' : 'blue' }}">{{ $report->transmission_type === 'lora' ? 'LoRa' : 'Online' }}</span>
                                <span class="tag {{ $statusTone($report->status) }}">{{ ucfirst($report->status) }}</span>
                                @if ($report->transmission_type === 'lora')
                                    <span class="badge blue">Gateway {{ $report->lora_gateway_id ?: 'unknown' }}</span>
                                @endif
                            </div>
                            <p class="detail-copy">{{ \Illuminate\Support\Str::limit($report->description ?: 'No description provided.', 110) }}</p>
                            <div class="command-review-footer">
                                <span>{{ $report->assignedResponder?->name ?? 'Unassigned admin receiver' }}</span>
                                <a href="{{ $reviewUrl($report) }}" class="btn btn-primary">Review</a>
                            </div>
                        </article>
                    @empty
                        <div class="command-empty-state">
                            <strong>No command review needed.</strong>
                            <p>The moderation queue is clear right now.</p>
                        </div>
                    @endforelse
                </div>
            </div>
        </article>

        <aside class="command-side-stack">
            <article class="panel command-compact-panel">
                <div class="panel-heading">
                    <p class="panel-kicker">Status Mix</p>
                    <h2 class="panel-title">Queue by status</h2>
                </div>
                <div class="command-mini-metrics">
                    @foreach ($statusBreakdown as $label => $count)
                        <div><span>{{ $label === 'needs_review' ? 'Needs review' : ($label === 'Fatal' ? 'Critical / Fatal' : $label) }}</span><strong>{{ $count }}</strong></div>
                    @endforeach
                </div>
            </article>

            <article class="panel command-compact-panel">
                <div class="panel-heading">
                    <p class="panel-kicker">Severity Mix</p>
                    <h2 class="panel-title">Load by severity</h2>
                </div>
                <div class="command-mini-metrics">
                    @foreach ($severityBreakdown as $label => $count)
                        <div><span>{{ $label === 'needs_review' ? 'Needs review' : $label }}</span><strong>{{ $count }}</strong></div>
                    @endforeach
                </div>
            </article>

            <article class="panel command-compact-panel">
                <div class="panel-heading">
                    <p class="panel-kicker">Transmission</p>
                    <h2 class="panel-title">Online / LoRa</h2>
                </div>
                <div class="command-mini-metrics two">
                    <div><span>Online</span><strong>{{ $transmissionBreakdown['online'] }}</strong></div>
                    <div><span>LoRa</span><strong>{{ $transmissionBreakdown['lora'] }}</strong></div>
                </div>
            </article>
        </aside>
        <article class="panel command-compact-panel command-map-panel" id="command-map">
            <div class="panel-head command-panel-head">
                <div class="panel-heading">
                    <p class="panel-kicker">Coverage</p>
                    <h2 class="panel-title">Command map</h2>
                    <p class="section-copy">Installed LoRa node locations.</p>
                </div>
                <div class="command-map-actions">
                    <a href="{{ route('admin.node-locations.index') }}" class="btn btn-secondary">Node locations</a>
                    <a href="{{ route('monitoring') }}" class="btn btn-secondary">Live Monitoring</a>
                </div>
            </div>
            <div class="map-shell command-map-shell">
                <div
                    class="map-canvas"
                    data-incident-map
                    data-map-scope="nodes"
                    data-node-points="{{ $nodeMapPoints->toJson() }}"
                    data-node-points-url="{{ route('admin.node-locations.points') }}"
                    data-points="[]"
                ></div>
            </div>
            <div class="node-map-caption">
                <p data-node-map-status role="status">{{ $nodeMapPoints->count() }} of 10 node locations set.</p>
                <p>Square pins mark installed nodes, including offline nodes.</p>
            </div>
        </article>

        <aside class="command-side-stack command-map-companion-stack">
            <article class="panel command-compact-panel">
                <div class="panel-heading">
                    <p class="panel-kicker">System</p>
                    <h2 class="panel-title">Readiness</h2>
                </div>
                <div class="command-readiness-list">
                    <div><strong>Online</strong><span>{{ $connectivity['online_status'] }}</span></div>
                    <div><strong>LoRa</strong><span>{{ $connectivity['lora_status'] }}</span></div>
                    <div><strong>Internet</strong><span>{{ $connectivity['internet_status'] }}</span></div>
                    <div><strong>Gateway</strong><span>{{ $connectivity['gateway_status'] }}</span></div>
                </div>
            </article>
        </aside>
        @include('admin.partials.report-audit')

    </section>

    <div class="command-audit-modal" data-command-audit-modal hidden>
        <div class="command-audit-modal-shell" role="dialog" aria-modal="true" aria-labelledby="command-audit-modal-title">
            <div class="command-audit-modal-head">
                <div class="command-audit-modal-title">
                    <p class="eyebrow">Incident Action Details</p>
                    <strong id="command-audit-modal-title" data-command-audit-modal-title>Incident action details</strong>
                </div>
                <button type="button" class="command-audit-modal-close" data-command-audit-modal-close aria-label="Close audit details">&times;</button>
            </div>
            <div class="command-audit-modal-body" data-command-audit-modal-body></div>
        </div>
    </div>
@endsection
