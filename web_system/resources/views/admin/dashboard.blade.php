@extends('layouts.app')

@php
    $tone = static fn (string $severity): string => match ($severity) {
        'Fatal', 'Critical' => 'red',
        'Serious', 'Needs review' => 'amber',
        default => 'green',
    };
    $statusTone = static fn (string $status): string => match ($status) {
        'rejected' => 'red',
        'resolved' => 'green',
        'responding', 'dispatched', 'acknowledged' => 'blue',
        default => 'neutral',
    };
    $priorityTimer = static function ($report): string {
        if ($report->created_at === null) {
            return 'Just received';
        }

        return max(1, (int) ceil($report->created_at->diffInMinutes(now()))).' min active';
    };
    $aiSourceLabel = static fn (?string $source): string => match ($source) {
        'text_model' => 'Description AI model',
        'text_model_experimental' => 'Experimental description AI',
        'text_review' => 'Description awaiting review',
        'text_human_override' => 'Responder severity; text AI advisory',
        'manual_only' => 'Manual responder review required',
        'human_responder' => 'Responder-assigned severity',
        'python_model', 'image_model' => 'Historical photo assessment',
        'description_fallback' => 'Historical description fallback',
        'manual_override' => 'Manual override',
        null, '' => 'Source unavailable',
        default => ucwords(str_replace('_', ' ', $source)),
    };
    $aiStatusLabel = static function ($report): string {
        if ($report->operational_ai_status === 'failed') {
            return 'AI unavailable';
        }

        if ($report->operational_ai_review_required) {
            return 'Admin review needed';
        }

        if ($report->operational_ai_status === 'fallback') {
            return 'Fallback mode';
        }

        if ($report->operational_ai_status === 'complete') {
            return 'Ready for triage';
        }

        return 'Pending AI review';
    };
    $aiStatusTone = static function ($report): string {
        if ($report->transmission_type === 'lora') {
            return 'neutral';
        }

        if ($report->operational_ai_status === 'failed' || $report->operational_ai_review_required) {
            return 'red';
        }

        if ($report->operational_ai_status === 'fallback') {
            return 'amber';
        }

        if ($report->operational_ai_status === 'complete') {
            return 'green';
        }

        return 'neutral';
    };
    $confidenceTone = static function ($confidence): string {
        if ($confidence === null) {
            return 'neutral';
        }

        if ($confidence >= 85) {
            return 'green';
        }

        if ($confidence >= 60) {
            return 'amber';
        }

        return 'red';
    };
    $reviewUrl = static fn ($report): string => route('reports.index', [
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
            <a href="{{ route('reports.index') }}" class="btn btn-secondary">Incident Reports</a>
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
                    <a href="{{ route('reports.index') }}" class="btn btn-secondary">All Reports</a>
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
                                @if ($report->reported_fatality_flag)
                                    <span class="tag red">POSSIBLE FATALITY · VERIFY NOW</span>
                                @endif
                                <span class="tag {{ $report->transmission_type === 'lora' ? 'amber' : 'blue' }}">{{ $report->transmission_type === 'lora' ? 'LoRa' : 'Online' }}</span>
                                <span class="tag {{ $tone($report->severity_display) }}">{{ $report->severity_display }}</span>
                                <span class="tag {{ $statusTone($report->status) }}">{{ ucfirst($report->status) }}</span>
                                @if ($report->transmission_type === 'lora')
                                    <span class="badge blue">Gateway {{ $report->lora_gateway_id ?: 'unknown' }}</span>
                                @else
                                    <span class="badge {{ $confidenceTone($report->operational_ai_confidence) }}">{{ $report->ai_score_display }}</span>
                                    <span class="badge blue">{{ $aiSourceLabel($report->operational_ai_source) }}</span>
                                @endif
                                <span class="badge {{ $aiStatusTone($report) }}">{{ $aiStatusLabel($report) }}</span>
                                @if ($report->transmission_type !== 'lora')
                                    @include('reports.partials.photo-relevance-badges', ['report' => $report])
                                @endif
                            </div>
                            <p class="detail-copy">{{ $report->transmission_type === 'lora' ? \Illuminate\Support\Str::limit($report->description, 140) : ($report->operational_ai_summary ?: 'AI summary is still pending for this item.') }}</p>
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
                <div class="panel-head command-panel-head">
                    <div class="panel-heading">
                        <p class="panel-kicker">Latest Incident</p>
                        <h2 class="panel-title">Latest incidents</h2>
                    </div>
                    <div class="command-pager" data-command-pager-controls hidden>
                        <button type="button" class="command-page-btn" data-command-page-action="prev" aria-label="Show previous incident reports" disabled>&larr;</button>
                        <span class="command-page-count" data-command-page-label>1 / 1</span>
                        <button type="button" class="command-page-btn" data-command-page-action="next" aria-label="Show next incident reports">&rarr;</button>
                    </div>
                </div>
                <div class="command-review-list compact command-paged-list" data-command-paged-list data-page-size="3">
                    @forelse ($recentReports as $report)
                        <a href="{{ $reviewUrl($report) }}" class="command-review-card compact{{ $loop->index >= 3 ? ' command-page-item-hidden' : '' }}" data-command-page-item>
                            <div class="command-review-main">
                                <span class="incident-code">{{ $report->report_code }}</span>
                                <h3>{{ $report->incident_type }}</h3>
                                <p>{{ $priorityTimer($report) }}</p>
                            </div>
                            <div class="command-review-meta">
                                <span class="tag {{ $report->transmission_type === 'lora' ? 'amber' : 'blue' }}">{{ $report->transmission_type === 'lora' ? 'LoRa' : 'Online' }}</span>
                                <span class="tag {{ $tone($report->severity_display) }}">{{ $report->severity_display }}</span>
                                <span class="tag {{ $statusTone($report->status) }}">{{ ucfirst($report->status) }}</span>
                                <span class="badge {{ $aiStatusTone($report) }}">{{ $aiStatusLabel($report) }}</span>
                            </div>
                            <p class="detail-copy">{{ $aiSourceLabel($report->operational_ai_source) }}</p>
                        </a>
                    @empty
                        <div class="command-empty-state compact">
                            <strong>No incidents yet.</strong>
                            <p>New reports will appear here.</p>
                        </div>
                    @endforelse
                </div>
            </article>

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
        <article class="panel command-compact-panel command-map-panel">
            <div class="panel-head command-panel-head">
                <div class="panel-heading">
                    <p class="panel-kicker">Coverage</p>
                    <h2 class="panel-title">Command map</h2>
                    <p class="section-copy">Incident locations at a glance.</p>
                </div>
                <a href="{{ route('monitoring') }}" class="btn btn-secondary">Live Monitoring</a>
            </div>
            <div class="map-shell command-map-shell">
                <div
                    class="map-canvas"
                    data-incident-map
                    data-points="{{ e(json_encode($mapPoints->values()->map(fn ($point) => [
                        'id' => $point->id,
                        'incident_type' => $point->incident_type,
                        'location_text' => $point->location_text,
                        'severity' => $point->operational_severity,
                        'severity_display' => $point->severity_display,
                        'latitude' => $point->latitude !== null ? (float) $point->latitude : null,
                        'longitude' => $point->longitude !== null ? (float) $point->longitude : null,
                    ])->all())) }}"
                ></div>
            </div>
        </article>

        <aside class="command-side-stack command-map-companion-stack">
            <article class="panel command-compact-panel">
                <div class="panel-head command-panel-head">
                    <div class="panel-heading">
                        <p class="panel-kicker">Civilian Registry</p>
                        <h2 class="panel-title">Recent civilian accounts</h2>
                    </div>
                    <a href="{{ route('civilian-accounts.index') }}" class="btn btn-secondary">Manage Accounts</a>
                </div>
                <div class="command-person-list">
                    @forelse ($recentCivilians as $civilian)
                        <div class="command-person-card">
                            <div>
                                <strong>{{ $civilian->name }}</strong>
                                <p>{{ $civilian->email }}</p>
                            </div>
                            <div class="tag-row">
                                <span class="tag blue">Civilian</span>
                                <span class="tag neutral">{{ $civilian->responderProfile?->phone ?? 'No contact number' }}</span>
                            </div>
                        </div>
                    @empty
                        <div class="command-empty-state"><strong>No civilians yet.</strong><p>New civilian registrations will appear here.</p></div>
                    @endforelse
                </div>
            </article>

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
                        <div><span>{{ $label }}</span><strong>{{ $count }}</strong></div>
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
        <article class="panel command-compact-panel command-audit-panel">
            <div class="panel-head command-panel-head">
                <div class="panel-heading">
                    <p class="panel-kicker">Incident Action History</p>
                    <h2 class="panel-title">Latest incident actions</h2>
                </div>
                <div class="command-pager" data-command-pager-controls hidden>
                    <button type="button" class="command-page-btn" data-command-page-action="prev" aria-label="Show previous audit entries" disabled>&larr;</button>
                    <span class="command-page-count" data-command-page-label>1 / 1</span>
                    <button type="button" class="command-page-btn" data-command-page-action="next" aria-label="Show next audit entries">&rarr;</button>
                </div>
            </div>
            <div class="command-table-wrap">
                <table class="command-audit-table" data-command-paged-list data-page-size="3">
                    <thead>
                        <tr>
                            <th>Action</th>
                            <th>Incident</th>
                            <th>Status</th>
                            <th>Actor</th>
                            <th>Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($auditTrail as $entry)
                            @php
                                $occurredAt = \Illuminate\Support\Carbon::parse($entry['occurred_at']);
                                $entryTitle = $entry['label'].' - '.$entry['report_code'];
                            @endphp
                            <tr
                                class="command-audit-row {{ $loop->index >= 3 ? 'command-page-item-hidden' : '' }}"
                                data-command-page-item
                                data-command-audit-row
                                data-command-audit-title="{{ e($entryTitle) }}"
                                tabindex="0"
                                role="button"
                                aria-label="View audit details for {{ $entryTitle }}"
                            >
                                <td data-label="Action">
                                    <strong>{{ $entry['label'] }}</strong>
                                    @if (! empty($entry['response_notes']))
                                        <span>{{ $entry['response_notes'] }}</span>
                                    @else
                                        <span>Click to view full action details.</span>
                                    @endif
                                </td>
                                <td data-label="Incident">
                                    <strong>{{ $entry['incident_type'] }}</strong>
                                    <span>{{ $entry['report_code'] }}</span>
                                </td>
                                <td data-label="Status">
                                    <span class="tag {{ $statusTone($entry['status']) }}">{{ ucfirst($entry['status']) }}</span>
                                </td>
                                <td data-label="Actor">
                                    <strong>{{ $entry['actor_name'] }}</strong>
                                    <span>{{ $entry['actor_role'] }}</span>
                                    @if (! empty($entry['assigned_responder_name']))
                                        <span class="command-audit-assignee">{{ $entry['assigned_responder_name'] }}</span>
                                    @endif
                                </td>
                                <td data-label="Time">
                                    <strong>{{ $occurredAt->diffForHumans() }}</strong>
                                    <span>{{ $occurredAt->format('M d, Y h:i A') }}</span>
                                    <template data-command-audit-template>
                                        <div class="command-audit-modal-grid">
                                            <article class="detail-card command-audit-modal-summary">
                                                <span class="incident-code">Action</span>
                                                <strong>{{ $entry['label'] }}</strong>
                                                <p>{{ $entry['response_notes'] ?: 'No response note was attached to this command action.' }}</p>
                                            </article>
                                            <article class="detail-card">
                                                <span class="incident-code">Incident</span>
                                                <strong>{{ $entry['incident_type'] }}</strong>
                                                <p>{{ $entry['report_code'] }}</p>
                                            </article>
                                            <article class="detail-card">
                                                <span class="incident-code">Status</span>
                                                <strong>{{ ucfirst($entry['status']) }}</strong>
                                                <p>Current command status recorded for this audit entry.</p>
                                            </article>
                                            <article class="detail-card">
                                                <span class="incident-code">Actor</span>
                                                <strong>{{ $entry['actor_name'] }}</strong>
                                                <p>{{ $entry['actor_role'] }}</p>
                                            </article>
                                            <article class="detail-card">
                                                <span class="incident-code">Assigned Receiver</span>
                                                <strong>{{ $entry['assigned_responder_name'] ?: 'Unassigned' }}</strong>
                                                <p>Admin/responder linked to the action at the time it was recorded.</p>
                                            </article>
                                            <article class="detail-card">
                                                <span class="incident-code">Time</span>
                                                <strong>{{ $occurredAt->format('M d, Y h:i A') }}</strong>
                                                <p>{{ $occurredAt->diffForHumans() }}</p>
                                            </article>
                                            <article class="detail-card">
                                                <span class="incident-code">Severity</span>
                                                <strong>{{ $entry['severity'] ?: 'Not classified' }}</strong>
                                                <p>AI-assisted severity attached to the related incident.</p>
                                            </article>
                                            <article class="detail-card">
                                                <span class="incident-code">Transmission</span>
                                                <strong>{{ strtoupper($entry['transmission_type'] ?: 'Unknown') }}</strong>
                                                <p>Source channel used by the related incident report.</p>
                                            </article>
                                            <article class="detail-card">
                                                <span class="incident-code">Reporter</span>
                                                <strong>{{ $entry['reporter_name'] ?: 'Unknown reporter' }}</strong>
                                                <p>Civilian or source account associated with the report.</p>
                                            </article>
                                            <article class="detail-card">
                                                <span class="incident-code">Location</span>
                                                <strong>{{ $entry['coordinates'] ?: 'No GPS coordinates' }}</strong>
                                                <p>{{ $entry['location_text'] ?: 'No readable location saved for this report.' }}</p>
                                            </article>
                                            <article class="detail-card command-audit-modal-wide">
                                                <span class="incident-code">Report Description</span>
                                                <strong>{{ $entry['description'] ?: 'No description provided.' }}</strong>
                                                <p>Original incident description connected to this audit action.</p>
                                            </article>
                                        </div>
                                        <div class="button-row command-audit-modal-actions">
                                            <a href="{{ $entry['detail_url'] }}" class="btn btn-primary">View Related Incident</a>
                                            <button type="button" class="btn btn-secondary" data-command-audit-modal-close>Close</button>
                                        </div>
                                    </template>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5">
                                    <div class="command-empty-state"><strong>No incident actions yet.</strong><p>Report and response activity will appear here.</p></div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </article>

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
