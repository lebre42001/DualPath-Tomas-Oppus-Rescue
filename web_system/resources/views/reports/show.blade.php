@extends('layouts.app')

@php
    $isCivilian = ($viewerMode ?? null) === 'civilian' || auth()->user()?->isCivilian();
    $clientStatus = match ($report->status) {
        'resolved' => ['label' => 'Resolved', 'copy' => 'The admin receiver marked this emergency as resolved.'],
        'rejected' => ['label' => 'Closed after review', 'copy' => 'The admin receiver reviewed and closed this report.'],
        'responding' => ['label' => 'Responded', 'copy' => 'The admin receiver responded to your report.'],
        'dispatched' => ['label' => 'Admin dispatched', 'copy' => 'An admin receiver has started the response workflow.'],
        'acknowledged' => ['label' => 'Under review', 'copy' => 'The admin receiver acknowledged and is reviewing your report.'],
        default => ['label' => 'Pending admin response', 'copy' => 'Your emergency report is safely stored and waiting for admin review.'],
    };
    $tone = static fn (string $severity): string => match ($severity) {
        'Fatal', 'Critical' => 'red',
        'Serious', 'Needs review', 'Awaiting responder' => 'amber',
        default => 'green',
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
        if ($report->operational_ai_source === 'manual_only') {
            return 'Awaiting responder severity';
        }

        if ($report->operational_ai_status === 'fallback') {
            return 'Fallback mode';
        }

        if ($report->operational_ai_status === 'failed') {
            return 'AI unavailable';
        }

        if ($report->operational_ai_review_required) {
            return 'Admin review needed';
        }

        if ($report->operational_ai_status === 'complete') {
            return 'Ready for triage';
        }

        return 'Pending AI review';
    };
    $aiStatusTone = static function ($report): string {
        if ($report->operational_ai_status === 'failed') {
            return 'red';
        }

        if ($report->operational_ai_review_required) {
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
    $evidenceUrl = $report->evidence_path ? route('reports.evidence', $report) : null;
    $downloadUrl = $report->evidence_path ? route('reports.evidence', ['incidentReport' => $report, 'download' => 1]) : null;
    $selfieUrl = $report->reporter_selfie_path ? route('reports.selfie', $report) : null;
    $selfieDownloadUrl = $report->reporter_selfie_path ? route('reports.selfie', ['incidentReport' => $report, 'download' => 1]) : null;
    $priorityTimer = static function ($report): string {
        if ($report->created_at === null) {
            return 'Just received';
        }

        return max(1, (int) ceil($report->created_at->diffInMinutes(now()))).' min active';
    };
    $adminProcessState = static function ($report): array {
        return match ($report->status) {
            'received' => ['label' => 'Pending admin response', 'tone' => 'amber'],
            'acknowledged' => ['label' => 'Under admin review', 'tone' => 'blue'],
            'dispatched' => ['label' => 'Response started', 'tone' => 'blue'],
            'responding' => ['label' => 'Admin responded', 'tone' => 'blue'],
            'resolved' => ['label' => 'Resolved', 'tone' => 'green'],
            'rejected' => ['label' => 'Cancelled', 'tone' => 'red'],
            default => ['label' => ucfirst((string) $report->status), 'tone' => 'neutral'],
        };
    };
    $adminState = $adminProcessState($report);
    $statusDisplayLabel = static fn (string $status): string => match ($status) {
        'received' => 'Pending',
        'acknowledged' => 'Under review',
        'dispatched' => 'Response started',
        'responding' => 'Responded',
        'resolved' => 'Resolved',
        'rejected' => 'Cancelled',
        default => ucfirst($status),
    };
@endphp

@section('title', 'Incident Details')
@section('page_label', $isCivilian ? 'Report History' : 'Incident Details')
@section('page_heading', $report->incident_type)
@section('page_subheading', $isCivilian ? 'Review your submitted emergency and its current response status.' : 'Review severity, transmission details, evidence, verification selfie, audit trail, and coordination actions from one command view.')

@section('hero')
    @if ($isCivilian)
        <section class="hero-card civilian-simple-hero">
            <div class="civilian-simple-hero-grid">
                <div class="hero-copy civilian-simple-copy">
                    <p class="eyebrow">My Emergency Report</p>
                    <h2>{{ $clientStatus['label'] }}</h2>
                    <p>{{ $clientStatus['copy'] }}</p>
                    <div class="hero-actions civilian-simple-actions">
                        <a href="{{ route('reports.index') }}" class="btn btn-secondary">Back to My Reports</a>
                        <a href="{{ route('reports.create') }}" class="btn btn-primary">Send New Report</a>
                    </div>
                </div>
                <div class="civilian-simple-stats" aria-label="Report status summary">
                    <article><span>Reference</span><strong>{{ $report->report_code }}</strong></article>
                    <article><span>Status</span><strong>{{ $clientStatus['label'] }}</strong></article>
                    <article><span>Updated</span><strong>{{ optional($report->status_updated_at)->format('M d, h:i A') ?? 'Pending' }}</strong></article>
                </div>
            </div>
        </section>
    @else
    <section class="hero-card">
        <div class="hero-grid">
            <div class="hero-copy">
                <p class="eyebrow">{{ $isCivilian ? 'My Report Details' : 'Incident Details' }}</p>
                <h2>{{ $report->report_code }} • {{ $report->incident_type }}</h2>
                <p>
                    Reported by <strong>{{ $report->reporter_name ?: 'Unknown reporter' }}</strong> at <strong>{{ $report->location_text }}</strong>.
                    {{ $isCivilian ? 'This page is focused on your submission history only, so admin coordination controls are hidden from the civilian account view.' : (\App\Models\IncidentReport::manualOnlyMode() ? 'This detail view keeps responder severity review, transmission status, evidence access, and admin coordination in a single operational screen.' : 'This detail view keeps AI review, transmission status, evidence access, and admin coordination in a single operational screen.') }}
                </p>
                <div class="hero-actions">
                    @unless ($isCivilian)
                        <a href="{{ route('reports.severity', $report) }}" class="btn btn-primary">{{ \App\Models\IncidentReport::manualOnlyMode() ? 'Open Severity Review' : 'Open AI Severity' }}</a>
                    @endunless
                    <a href="{{ route('reports.transmissions', $report) }}" class="btn btn-secondary">Open Transmission Status</a>
                    <a href="{{ route('reports.index') }}" class="btn btn-secondary">{{ $isCivilian ? 'Back To History' : 'Back To Feed' }}</a>
                    <form method="POST" action="{{ route('reports.destroy', $report) }}" onsubmit="return confirm('Delete this report?');" style="display:inline-flex;">
                        @csrf
                        @method('DELETE')
                        <button class="btn btn-danger" type="submit">Delete Report</button>
                    </form>
                </div>
            </div>
            <div class="hero-metrics">
                <article class="metric-card"><span>Severity</span><strong>{{ $report->severity_display }}</strong><p>{{ $report->ai_source === 'text_review' ? 'Awaiting responder assessment.' : 'Check the assessment source and review state below.' }}</p></article>
                <article class="metric-card"><span>Status</span><strong>{{ $adminState['label'] }}</strong><p>{{ $isCivilian ? 'Current progress of your submitted incident.' : 'Most recent admin response state in the queue.' }}</p></article>
                <article class="metric-card"><span>Transmission</span><strong>{{ strtoupper($report->transmission_type ?: 'online') }}</strong><p>{{ $report->channel ?: 'Internet' }}</p></article>
                <article class="metric-card"><span>Priority timer</span><strong>{{ $priorityTimer($report) }}</strong><p>Elapsed active time since the report was first created.</p></article>
            </div>
        </div>
    </section>
    @endif
@endsection

@section('content')
    @if ($isCivilian)
        <section class="civilian-shell">
            <article class="panel">
                <div class="panel-head">
                    <div class="panel-heading">
                        <p class="panel-kicker">Current Status</p>
                        <h2 class="panel-title">{{ $clientStatus['label'] }}</h2>
                        <p class="section-copy">{{ $clientStatus['copy'] }}</p>
                    </div>
                    <span class="tag {{ $adminState['tone'] }}">{{ $adminState['label'] }}</span>
                </div>
                <div class="civilian-progress-block">
                    <div class="civilian-progress-track">
                        <div class="civilian-progress-fill {{ $report->status === 'resolved' ? 'is-complete' : ($report->status === 'rejected' ? 'is-rejected' : '') }}" style="width:{{ match ($report->status) { 'resolved', 'rejected' => 100, 'responding' => 82, 'dispatched' => 64, 'acknowledged' => 42, default => 20 } }}%;"></div>
                    </div>
                </div>
            </article>

            <section class="dual-grid">
                <article class="panel">
                    <div class="panel-head"><div class="panel-heading"><p class="panel-kicker">Submitted Report</p><h2 class="panel-title">Emergency details</h2></div></div>
                    <div class="stack">
                        <div class="detail-card"><strong>Reference code</strong><p>{{ $report->report_code }}</p></div>
                        <div class="detail-card"><strong>Emergency type</strong><p>{{ $report->incident_type }}</p></div>
                        <div class="detail-card"><strong>Description</strong><p>{{ $report->description }}</p></div>
                        <div class="detail-card"><strong>Submitted location</strong><p>{{ $report->location_text }}</p></div>
                        <div class="detail-card">
                            <strong>Submitted evidence</strong>
                            @if ($evidenceUrl && $report->evidence_type === 'photo')
                                <button type="button" class="media-trigger" data-media-viewer-trigger data-media-type="photo" data-media-src="{{ $evidenceUrl }}" data-media-title="{{ $report->report_code }} - {{ $report->evidence_original_name ?: 'Photo evidence' }}">
                                    <img src="{{ $evidenceUrl }}" alt="Evidence photo for {{ $report->report_code }}" class="detail-media">
                                    <span>Click to enlarge evidence photo</span>
                                </button>
                                <p>{{ $report->evidence_original_name ?: 'Photo evidence attached' }}</p>
                            @elseif ($evidenceUrl && $report->evidence_type === 'video')
                                <button type="button" class="media-trigger" data-media-viewer-trigger data-media-type="video" data-media-src="{{ $evidenceUrl }}" data-media-title="{{ $report->report_code }} - {{ $report->evidence_original_name ?: 'Video evidence' }}">
                                    <video preload="metadata" playsinline muted class="detail-media"><source src="{{ $evidenceUrl }}">Your browser does not support embedded video playback.</video>
                                    <span>Click to enlarge evidence video</span>
                                </button>
                                <p>{{ $report->evidence_original_name ?: 'Video evidence attached' }}</p>
                            @else
                                <p>No evidence photo or video was attached to this report.</p>
                            @endif
                        </div>
                    </div>
                </article>

                <article class="panel">
                    <div class="panel-head"><div class="panel-heading"><p class="panel-kicker">Timeline</p><h2 class="panel-title">Report activity</h2></div></div>
                    <div class="stack">
                        <div class="detail-card"><strong>Submitted</strong><p>{{ optional($report->created_at)->format('M d, Y h:i A') ?? 'Pending' }}</p></div>
                        <div class="detail-card"><strong>Current status</strong><p>{{ $clientStatus['label'] }}</p></div>
                        <div class="detail-card"><strong>Last status update</strong><p>{{ optional($report->status_updated_at)->format('M d, Y h:i A') ?? 'No admin update yet' }}</p></div>
                    </div>
                </article>
            </section>
        </section>
    @else
    @if ($report->reported_fatality_flag)
        <section class="reported-fatality-alert" role="alert">
            <strong>Critical priority — possible fatality reported</strong>
            <p>The description contains “patay”. A responder must verify urgently. This flag does not confirm a death and does not replace responder-assigned severity.</p>
        </section>
    @endif
    <section class="dual-grid">
        <article class="panel">
            <div class="panel-head"><div class="panel-heading"><p class="panel-kicker">Incident Data</p><h2 class="panel-title">Core details</h2></div></div>
            <div class="stack">
                <div class="detail-card"><strong>Severity</strong><span class="tag {{ $tone($report->severity_display) }}">{{ $report->severity_display }}</span></div>
                <div class="detail-card"><strong>Admin response state</strong><span class="tag {{ $adminState['tone'] }}">{{ $adminState['label'] }}</span><p>{{ $report->status === 'received' ? 'No admin has responded or cancelled this report yet.' : 'Current saved coordination status: '.$adminState['label'].'.' }}</p></div>
                <div class="detail-card"><strong>Description</strong><p>{{ $report->description }}</p></div>
                <div class="detail-card"><strong>Reporter</strong><p>{{ $report->reporter_name ?: 'Unknown' }}<br>{{ $report->reporter_contact ?: 'No contact provided' }}</p></div>
                <div class="detail-card"><strong>{{ $report->transmission_type === 'lora' ? 'Registered node coordinates' : 'GPS coordinates' }}</strong><p>@if ($report->latitude !== null && $report->longitude !== null){{ number_format((float) $report->latitude, 6) }}, {{ number_format((float) $report->longitude, 6) }}@else Coordinates not available @endif</p></div>
                <div class="detail-card">
                    <strong>{{ in_array($report->operational_ai_source, ['manual_only', 'human_responder'], true) ? 'Severity assessment' : 'AI summary' }}</strong>
                    <div class="tag-row">
                        <span class="tag {{ $tone($report->severity_display) }}">{{ $report->severity_display }} output</span>
                        <span class="badge {{ $confidenceTone($report->operational_ai_confidence) }}">{{ $report->ai_score_display }}</span>
                        <span class="badge blue">{{ $aiSourceLabel($report->operational_ai_source) }}</span>
                        <span class="badge {{ $aiStatusTone($report) }}">{{ $aiStatusLabel($report) }}</span>
                        @include('reports.partials.photo-relevance-badges', ['report' => $report])
                    </div>
                    <p>
                        {{ $report->operational_ai_summary ?: 'No AI summary generated.' }}
                    </p>
                    @if ($report->isLegacyUnreviewedTextSuggestion() && ! $isCivilian)
                        <p class="panel-note">The prior AI prediction is excluded from live severity, alert counts, queues, and dispatch. <a href="{{ route('reports.severity', $report) }}">Open restricted audit history</a> to inspect the saved non-operational suggestion.</p>
                    @endif
                    <div class="meta-list">
                        <div class="meta-row">
                            <span>{{ $report->operational_ai_source === 'manual_only' ? 'Assessment status' : 'AI status' }}</span>
                            <strong>{{ $aiStatusLabel($report) }}</strong>
                        </div>
                        <div class="meta-row">
                            <span>{{ in_array($report->operational_ai_source, ['manual_only', 'human_responder'], true) ? 'Severity source' : 'AI source' }}</span>
                            <strong>{{ $aiSourceLabel($report->operational_ai_source) }}</strong>
                        </div>
                        <div class="meta-row">
                            <span>AI model</span>
                            <strong>{{ $report->operational_ai_model_name ? $report->operational_ai_model_name.($report->operational_ai_model_version ? ' v'.$report->operational_ai_model_version : '') : 'Model unavailable' }}</strong>
                        </div>
                        @if ($report->photo_relevance_label !== null || $report->photo_relevance_confidence !== null)
                            <div class="meta-row">
                                <span>Historical photo relevance</span>
                                <strong>{{ $report->photo_relevance_label ? ucwords(str_replace('_', ' ', $report->photo_relevance_label)) : 'Not checked' }}{{ $report->photo_relevance_confidence !== null ? ' - '.$report->photo_relevance_confidence.'%' : '' }}</strong>
                            </div>
                        @endif
                        <div class="meta-row">
                            <span>Processed</span>
                            <strong>{{ $report->isOperationalSeveritySuppressed() ? 'Not used operationally' : ($report->ai_processed_at ? $report->ai_processed_at->format('M d, Y h:i A') : 'Pending processing') }}</strong>
                        </div>
                    </div>
                    @if (\App\Support\AiSeverityMapper::humanizeErrorMessage($report->ai_error_message))
                        <div class="panel-note">
                            <strong>Fallback note</strong>
                            <p>{{ \App\Support\AiSeverityMapper::humanizeErrorMessage($report->ai_error_message) }}</p>
                        </div>
                    @endif
                </div>
            </div>
        </article>

        <article class="panel">
            @if ($isCivilian)
                <div class="panel-head"><div class="panel-heading"><p class="panel-kicker">Submission Status</p><h2 class="panel-title">Your report progress</h2></div></div>
                <div class="stack">
                    <div class="detail-card"><strong>Current status</strong><p>{{ $adminState['label'] }}</p></div>
                    <div class="detail-card"><strong>Assigned admin receiver</strong><p>{{ $report->assignedResponder?->name ?? 'Not yet assigned' }}</p></div>
                    <div class="detail-card"><strong>Status updated</strong><p>{{ optional($report->status_updated_at)->format('M d, Y h:i A') ?? 'Not yet updated' }}</p></div>
                    <div class="detail-card"><strong>Transmission channel</strong><p>{{ $report->channel ?: 'Internet' }}</p></div>
                </div>
            @else
                <div class="panel-head"><div class="panel-heading"><p class="panel-kicker">Emergency Coordination</p><h2 class="panel-title">Assign admin receiver and update status</h2></div></div>
                <form method="POST" action="{{ route('reports.coordination', $report) }}" class="stack">
                    @csrf
                    <div class="field"><label for="assigned_responder_id">Assigned admin receiver</label><select id="assigned_responder_id" name="assigned_responder_id"><option value="">Unassigned</option>@foreach ($responders as $responder)<option value="{{ $responder->id }}" @selected(old('assigned_responder_id', $report->assigned_responder_id) == $responder->id)>{{ $responder->name }} - Admin</option>@endforeach</select></div>
                    <div class="field"><label for="status">Response status</label><select id="status" name="status">@foreach (['received', 'acknowledged', 'dispatched', 'responding', 'resolved', 'rejected'] as $status)<option value="{{ $status }}" @selected(old('status', $report->status) === $status)>{{ $statusDisplayLabel($status) }}</option>@endforeach</select></div>
                    @if ($report->ai_source === 'manual_only' || $report->ai_source === 'human_responder' || str_starts_with((string) $report->ai_source, 'text_'))
                        <div class="field"><label for="reviewed-severity">Responder severity assessment</label><select id="reviewed-severity" name="reviewed_severity"><option value="">Keep current assessment</option>@foreach (['Minor', 'Serious', 'Fatal'] as $severity)<option value="{{ $severity }}" @selected(old('reviewed_severity', $report->severity) === $severity)>{{ $severity }}</option>@endforeach</select><p>Select a severity only after reviewing this report. Saving a selection records your assessment and adds an audit entry.</p></div>
                    @endif
                    <div class="field"><label for="response_notes">Response notes</label><textarea id="response_notes" class="textarea" name="response_notes" placeholder="Admin actions, route notes, or follow-up details">{{ old('response_notes', $report->response_notes) }}</textarea></div>
                    <button class="btn btn-primary" type="submit">Save Coordination Update</button>
                </form>
                <div class="divider"></div>
                <div class="detail-card incident-actions">
                    <strong>Admin decision</strong>
                    <p>Choose Respond or Cancel after reviewing the full civilian report, evidence, AI analysis, and map pin. Both decision buttons require a message so the civilian sender can see what happened.</p>
                    <div class="decision-grid">
                        <form method="POST" action="{{ route('reports.coordination', $report) }}" class="decision-card">
                            @csrf
                            <input type="hidden" name="assigned_responder_id" value="{{ auth()->id() }}">
                            <input type="hidden" name="status" value="responding">
                            <strong>Respond to incident</strong>
                            <textarea class="textarea" name="response_notes" required placeholder="Message to civilian sender, e.g. Admin responded and is coordinating assistance.">{{ old('response_notes') }}</textarea>
                            <button type="submit" class="btn btn-primary">Respond</button>
                        </form>

                        <form method="POST" action="{{ route('reports.coordination', $report) }}" class="decision-card">
                            @csrf
                            <input type="hidden" name="assigned_responder_id" value="{{ auth()->id() }}">
                            <input type="hidden" name="status" value="rejected">
                            <strong>Cancel report</strong>
                            <textarea class="textarea" name="response_notes" required placeholder="Message to civilian sender, e.g. Report cancelled after admin review because...">{{ old('response_notes') }}</textarea>
                            <button type="submit" class="btn btn-danger">Cancel Report</button>
                        </form>

                        <form method="POST" action="{{ route('reports.destroy', $report) }}" class="decision-card" onsubmit="return confirm('Delete this specific incident report and attached files?');">
                            @csrf
                            @method('DELETE')
                            <strong>Delete specific report</strong>
                            <p>Remove this incident report and its attached evidence/selfie files from the system.</p>
                            <button type="submit" class="btn btn-danger">Delete Report</button>
                        </form>
                    </div>
                </div>
                <div class="divider"></div>
                <div class="detail-card">
                    <span class="eyebrow">Dispatch Recommendation</span>
                    <strong>{{ $dispatchRecommendation['priority'] }}</strong>
                    <p>{{ $dispatchRecommendation['summary'] }}</p>
                    <p><strong>Recommended admin:</strong> {{ $dispatchRecommendation['recommended_responder'] }}</p>
                    <p><strong>Response type:</strong> {{ $dispatchRecommendation['response_type'] }}</p>
                    <p><strong>Priority timer:</strong> {{ $dispatchRecommendation['timer_label'] }}</p>
                </div>
            @endif
        </article>
    </section>

    <section class="dual-grid">
        <article class="panel">
            <div class="panel-head"><div class="panel-heading"><p class="panel-kicker">Map Tracking</p><h2 class="panel-title">Incident location</h2></div></div>
            <div class="preview-grid">
                <div class="detail-card">
                    <strong>Incident location</strong>
                    <p>{{ $locationMeta['readable_location'] }}</p>
                    <p>
                        @if ($report->latitude !== null && $report->longitude !== null)
                            {{ $report->transmission_type === 'lora' ? 'Fixed node location' : 'GPS' }} {{ number_format((float) $report->latitude, 6) }}, {{ number_format((float) $report->longitude, 6) }}
                        @else
                            {{ $report->transmission_type === 'lora' ? 'This sender node has no coordinates configured yet.' : 'GPS coordinates are not available for this report.' }}
                        @endif
                    </p>
                    <p><strong>Barangay / town:</strong> {{ $locationMeta['barangay_town'] ?: 'Not available yet' }}</p>
                    <p><strong>Submitted text:</strong> {{ $report->location_text }}</p>
                </div>
                <div class="detail-card">
                    <strong>Command center route</strong>
                    <p>{{ $locationMeta['distance_label'] ?: ($report->transmission_type === 'lora' ? 'Awaiting registered node coordinates' : 'Awaiting GPS coordinates') }}</p>
                    <p><strong>Travel time:</strong> {{ $locationMeta['travel_time_label'] ?: 'Awaiting route estimate' }}</p>
                    <p><strong>Command center:</strong> {{ $commandCenter['location_text'] }}</p>
                    <p>
                        @if ($locationMeta['route_status'] === 'live')
                            Live road route is ready from {{ $commandCenter['name'] }} to the civilian sender location.
                        @else
                            {{ $locationMeta['route_notice'] ?: 'Live road routing is not available right now, so the system is showing a safe fallback estimate.' }}
                        @endif
                    </p>
                </div>
                <div class="detail-card">
                    <strong>Map actions</strong>
                    <p>{{ $isCivilian ? 'Open your submitted incident location in Google Maps if you need to review the exact pin placement.' : 'Open the civilian sender location in Google Maps for admin route planning.' }}</p>
                    <p><strong>Red pin:</strong> Civilian sender location<br><strong>Blue pin:</strong> {{ $commandCenter['name'] }}</p>
                    <div class="action-row">
                        <a href="{{ $locationMeta['google_maps_url'] }}" class="btn btn-primary" target="_blank" rel="noopener">Open in Google Maps</a>
                        <a href="{{ $locationMeta['directions_url'] }}" class="btn btn-secondary" target="_blank" rel="noopener">Open Route Directions</a>
                        <a href="{{ $commandCenter['google_maps_url'] }}" class="btn btn-secondary" target="_blank" rel="noopener">Open Command Center</a>
                    </div>
                </div>
            </div>
            <div class="divider"></div>
            @if ($mapPoints->isNotEmpty())
                <div class="detail-card map-shell">
                    <div class="map-canvas" data-incident-map data-latitude="{{ $report->latitude !== null ? (float) $report->latitude : '' }}" data-longitude="{{ $report->longitude !== null ? (float) $report->longitude : '' }}" data-location-text="{{ e($report->location_text) }}" data-points="{{ e(json_encode($mapPoints->values()->map(fn ($point) => [
                        'id' => $point['id'],
                        'incident_type' => $point['incident_type'],
                        'location_text' => $point['location_text'],
                        'severity' => $point['severity'],
                        'severity_display' => $point['severity_display'] ?? $point['severity'],
                        'latitude' => $point['latitude'] !== null ? (float) $point['latitude'] : null,
                        'longitude' => $point['longitude'] !== null ? (float) $point['longitude'] : null,
                        'map_role' => $point['map_role'] ?? null,
                        'readable_location' => $point['readable_location'] ?? null,
                        'barangay_town' => $point['barangay_town'] ?? null,
                        'distance_from_command_center' => $point['distance_from_command_center'] ?? null,
                        'travel_time_from_command_center' => $point['travel_time_from_command_center'] ?? null,
                        'google_maps_url' => $point['google_maps_url'] ?? null,
                        'directions_url' => $point['directions_url'] ?? null,
                        'route_status' => $point['route_status'] ?? null,
                        'route_provider' => $point['route_provider'] ?? null,
                    ])->all())) }}" data-route="{{ e(json_encode($routeData)) }}"></div>
                </div>
            @else
                <div class="data-empty">Coordinates are not available for this report.</div>
            @endif
        </article>

        <article class="panel">
            <div class="panel-head"><div class="panel-heading"><p class="panel-kicker">Transmission</p><h2 class="panel-title">Delivery details</h2></div></div>
            <div class="stack">
                <div class="detail-card"><strong>Transmission type</strong><p>{{ strtoupper($report->transmission_type ?: 'online') }}</p></div>
                <div class="detail-card"><strong>Channel</strong><p>{{ $report->channel ?: 'Internet' }}</p></div>
                <div class="detail-card"><strong>Transmitted at</strong><p>{{ optional($report->transmitted_at)->format('M d, Y h:i A') ?? 'Pending' }}</p></div>
                <div class="detail-card"><strong>Status updated</strong><p>{{ optional($report->status_updated_at)->format('M d, Y h:i A') ?? 'Not yet updated' }}</p></div>
                @if ($report->transmission_type === 'lora')
                    <div class="detail-card"><strong>LoRa sender</strong><p>{{ $report->lora_sender_id ?: 'Unknown sender' }} @if($report->lora_sequence)| Seq {{ $report->lora_sequence }}@endif</p></div>
                    <div class="detail-card"><strong>Gateway</strong><p>{{ $report->lora_gateway_id ?: 'Unknown gateway' }}</p></div>
                    <div class="detail-card"><strong>Signal</strong><p>GW RSSI {{ $report->lora_gateway_rssi ?? 'n/a' }} / RX RSSI {{ $report->lora_receiver_rssi ?? 'n/a' }}</p></div>
                    <div class="detail-card"><strong>Responder ACK to field node</strong><p>{{ $report->lora_ack_delivered_at ? 'Broadcast by NODE_1 at '.$report->lora_ack_delivered_at->format('M d, Y h:i A') : ($report->lora_ack_queued_at ? 'Queued; waiting for NODE_1 gateway broadcast.' : 'Waiting for a responder to acknowledge this report.') }}</p></div>
                @endif
            </div>
        </article>
    </section>

    <section class="dual-grid">
        <article class="panel">
            <div class="panel-head"><div class="panel-heading"><p class="panel-kicker">Reporter Verification</p><h2 class="panel-title">Realtime selfie capture</h2></div></div>
            <div class="stack">
                @if ($selfieUrl)
                    <div class="detail-card">
                        <strong>Verification selfie</strong>
                        <p>The mobile app required a live front-camera selfie before this report was sent.@if ($report->reporter_selfie_captured_at)<br>Captured: {{ $report->reporter_selfie_captured_at->format('M d, Y h:i A') }}@endif</p>
                        <button type="button" class="media-trigger" data-media-viewer-trigger data-media-type="photo" data-media-src="{{ $selfieUrl }}" data-media-title="{{ $report->report_code }} - {{ $report->reporter_selfie_original_name ?: 'Verification selfie' }}">
                            <img src="{{ $selfieUrl }}" alt="Verification selfie for {{ $report->report_code }}" class="detail-media">
                            <span>Click to enlarge verification selfie</span>
                        </button>
                        <p>{{ $report->reporter_selfie_original_name ?: 'verification-selfie.jpg' }}</p>
                        <div class="action-row"><a href="{{ $selfieUrl }}" class="btn btn-secondary" target="_blank" rel="noopener">Open Selfie</a><a href="{{ $selfieDownloadUrl }}" class="btn btn-primary">Download Selfie</a></div>
                    </div>
                @else
                    <div class="data-empty">No verification selfie is attached to this report.</div>
                @endif
            </div>
        </article>

        <article class="panel">
            <div class="panel-head"><div class="panel-heading"><p class="panel-kicker">Evidence</p><h2 class="panel-title">Attached proof</h2></div></div>
            <div class="stack">
                @if ($evidenceUrl && $report->evidence_type === 'photo')
                    <div class="detail-card">
                        <strong>Photo preview</strong>
                        <p>{{ $isCivilian ? 'You can review the uploaded image attached to your report.' : 'Admins can review the uploaded image directly inside the report section.' }}</p>
                        <button type="button" class="media-trigger" data-media-viewer-trigger data-media-type="photo" data-media-src="{{ $evidenceUrl }}" data-media-title="{{ $report->report_code }} - {{ $report->evidence_original_name ?: 'Photo evidence' }}">
                            <img src="{{ $evidenceUrl }}" alt="Photo evidence for {{ $report->report_code }}" class="detail-media">
                            <span>Click to enlarge photo</span>
                        </button>
                    </div>
                @elseif ($evidenceUrl && $report->evidence_type === 'video')
                    <div class="detail-card">
                        <strong>Video preview</strong>
                        <p>{{ $isCivilian ? 'You can replay the uploaded video attached to your report.' : 'Admins can play the uploaded video evidence without leaving the report page.' }}</p>
                        <button type="button" class="media-trigger" data-media-viewer-trigger data-media-type="video" data-media-src="{{ $evidenceUrl }}" data-media-title="{{ $report->report_code }} - {{ $report->evidence_original_name ?: 'Video evidence' }}">
                            <video preload="metadata" playsinline muted class="detail-media"><source src="{{ $evidenceUrl }}">Your browser does not support embedded video playback.</video>
                            <span>Click to enlarge video</span>
                        </button>
                    </div>
                @endif

                <div class="detail-card">
                    <strong>Evidence type</strong>
                    <p>{{ ucfirst($report->evidence_type ?: 'none') }}</p>
                    <p><strong>File:</strong> {{ $report->evidence_original_name ?: 'No file attached' }}</p>
                    @if ($evidenceUrl)
                        <div class="action-row"><a href="{{ $evidenceUrl }}" class="btn btn-secondary" target="_blank" rel="noopener">Open Evidence</a><a href="{{ $downloadUrl }}" class="btn btn-primary">Download File</a></div>
                    @else
                        <p>{{ $report->transmission_type === 'lora' ? 'LoRa fallback reports send compact data only, so no media evidence is attached.' : 'No picture or video evidence was attached to this report.' }}</p>
                    @endif
                </div>
            </div>
        </article>
    </section>

        <section class="panel">
            <div class="panel-head"><div class="panel-heading"><p class="panel-kicker">Audit Trail</p><h2 class="panel-title">Coordination history</h2><p class="section-copy">Track who assigned, rejected, marked done, or reopened the incident.</p></div></div>
            <div class="timeline">
                @foreach ($auditTrail as $entry)
                    <div class="timeline-item">
                        <div class="timeline-marker"></div>
                        <div class="timeline-copy">
                            <strong class="timeline-label">{{ $entry['label'] ?? 'Incident updated' }}</strong>
                            <span class="meta">{{ $entry['actor_name'] ?? 'System' }} • {{ $entry['actor_role'] ?? 'Admin' }} • {{ isset($entry['occurred_at']) ? \Carbon\Carbon::parse($entry['occurred_at'])->format('M d, Y h:i A') : 'Timestamp unavailable' }}</span>
                            <p><strong>Status:</strong> {{ $statusDisplayLabel($entry['status'] ?? $report->status) }}</p>
                            @if (!empty($entry['assigned_responder_name']))
                                <p><strong>Assigned admin:</strong> {{ $entry['assigned_responder_name'] }}</p>
                            @endif
                            @if (!empty($entry['response_notes']))
                                <p><strong>Notes:</strong> {{ $entry['response_notes'] }}</p>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    @endif
@endsection
