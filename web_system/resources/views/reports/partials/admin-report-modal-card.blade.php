@php
    $tone = $tone ?? static fn ($severity) => match ($severity) { 'Critical', 'Fatal' => 'red', 'Serious', 'Needs review' => 'amber', 'Minor' => 'green', default => 'blue' };
    $statusDisplayLabel = $statusDisplayLabel ?? static fn ($status) => match ($status) { 'received' => 'Pending', 'acknowledged' => 'Under review', 'responding' => 'Responded', 'dispatched' => 'Response started', 'resolved' => 'Resolved', 'rejected' => 'Cancelled', default => ucfirst($status) };
    $statusDisplayTone = $statusDisplayTone ?? static fn ($status) => match ($status) { 'received' => 'amber', 'resolved' => 'green', 'rejected' => 'red', default => 'blue' };
    $priorityTimer = $priorityTimer ?? static fn ($item) => $item->created_at ? max(1, (int) ceil($item->created_at->diffInMinutes(now()))).' min since received' : 'Just received';
    $mapSearchUrl = $mapSearchUrl ?? static fn ($item) => 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($item->latitude !== null && $item->longitude !== null ? $item->latitude.','.$item->longitude : (string) $item->location_text);
    $dispatchSummary = $dispatchSummary ?? static fn ($severity) => 'Review the scene information before coordinating the response.';
    $reviewReturnTo = $reviewReturnTo ?? 'monitoring';
    $reviewTime = static fn ($value) => $value?->timezone('Asia/Manila')->format('M d, Y h:i:s A') ?? 'Not recorded';
    $historyTime = static function ($entry) {
        $value = $entry['occurred_at'] ?? $entry['at'] ?? null;
        try { return $value ? \Illuminate\Support\Carbon::parse($value)->timezone('Asia/Manila')->format('M d, Y h:i:s A') : 'Time not recorded'; }
        catch (\Throwable) { return 'Time not recorded'; }
    };
    $lastHandled = collect($report->coordination_log ?? [])->reverse()->first(fn ($entry) => in_array(strtolower($entry['actor_role'] ?? ''), ['admin', 'responder'], true) || ($entry['event'] ?? '') === 'lora_verification');
    $hasCoordinates = $report->latitude !== null && $report->longitude !== null;
    $isLoRa = $report->transmission_type === 'lora';
    $inlineMapPayload = $hasCoordinates ? [[
        'id' => 'modal-sender-'.$report->id,
        'incident_type' => $report->incident_type,
        'location_text' => $report->location_text,
        'severity' => $report->operational_severity,
        'severity_display' => $report->severity_display,
        'latitude' => (float) $report->latitude,
        'longitude' => (float) $report->longitude,
        'map_role' => $isLoRa ? 'incident' : 'sender',
        'readable_location' => $report->location_text,
        'google_maps_url' => $mapSearchUrl($report),
    ]] : [];
@endphp

<div class="incident-card compact-live-card responder-feed-card incident-feed-expanded dashboard-modal-source admin-unified-review" data-admin-report-review="{{ $report->transmission_type }}">
    <div class="incident-rail severity-{{ $report->operational_severity }}"></div>
    <div class="incident-meta">
        <span class="incident-code">{{ $report->report_code }}</span>
        <a class="btn btn-secondary" href="{{ $isLoRa ? route('lora-reports.print', $report) : route('reports.print', $report) }}" target="_blank" rel="noopener">Print report</a>
        <strong class="incident-time">{{ $priorityTimer($report) }}</strong>
        <div class="tag-row">
            @if ($report->reported_fatality_flag)
                <span class="tag red">POSSIBLE FATALITY · VERIFY NOW</span>
            @endif
            <span class="tag {{ $tone($report->current_severity_category) }}" data-current-category-for="{{ $report->id }}">{{ $report->current_severity_category }}</span>
            <span class="tag {{ $statusDisplayTone($report->status) }}">{{ $statusDisplayLabel($report->status) }}</span>
            <span class="tag neutral">{{ strtoupper($report->transmission_type ?: 'online') }}</span>
        </div>
    </div>

    <div class="incident-content">
        <div class="incident-headline">
            <div>
                <h3 class="incident-title">{{ $report->incident_type }}</h3>
                <p class="detail-copy"><strong>{{ $report->location_text }}</strong> | {{ $report->reporter_name ?: $report->reporter?->name ?: 'Unknown reporter' }}</p>
            </div>
            <div class="tag-row">
                @if ($isLoRa)
                    <span class="tag neutral">Verification: {{ match ($report->lora_verification) { 'confirmed' => 'Confirmed', 'needs_clarification' => 'Needs clarification', 'false_report' => 'False report', default => 'Unverified' } }}</span>
                @endif
                @if ($report->evidence_path)
                    <span class="tag green">{{ ucfirst($report->evidence_type) }} Attached</span>
                @endif
                @if ($report->reporter_selfie_path)
                    <span class="tag neutral">Selfie Verified</span>
                @endif
                <span class="tag {{ $statusDisplayTone($report->status) }}">{{ $statusDisplayLabel($report->status) }}</span>
            </div>
        </div>

        <p class="incident-summary">{{ $report->description ?: 'No short description provided.' }}</p>

        <div class="preview-grid">
            @if ($isLoRa)
                <div class="preview-card" data-lora-description-analysis>
                    <strong>Severity assessment</strong>
                    @include('reports.partials.ai-analysis', ['report' => $report, 'compact' => false])
                </div>
            @else
                <div class="preview-card">
                    <strong>Severity assessment</strong>
                    @include('reports.partials.ai-analysis', ['report' => $report, 'compact' => false])
                    @include('reports.partials.photo-relevance-badges', ['report' => $report])
                </div>
            @endif

            <div class="preview-card">
                <strong>Evidence</strong>
                @if ($report->evidence_path)
                    @if ($report->evidence_type === 'photo')
                        <button type="button" class="media-trigger" data-media-viewer-trigger data-media-type="photo" data-media-src="{{ route('reports.evidence', $report) }}" data-media-title="{{ $report->report_code }} - {{ $report->evidence_original_name ?: 'Photo evidence' }}">
                            <img src="{{ route('reports.evidence', $report) }}" alt="Photo evidence for {{ $report->report_code }}" class="preview-thumb" loading="lazy" decoding="async" fetchpriority="low">
                            <span>Click to enlarge photo</span>
                        </button>
                    @elseif ($report->evidence_type === 'video')
                        <button type="button" class="media-trigger" data-media-viewer-trigger data-media-type="video" data-media-src="{{ route('reports.evidence', $report) }}" data-media-title="{{ $report->report_code }} - {{ $report->evidence_original_name ?: 'Video evidence' }}">
                            <video preload="none" playsinline muted class="preview-thumb"><source src="{{ route('reports.evidence', $report) }}">Your browser does not support embedded video playback.</video>
                            <span>Click to enlarge video</span>
                        </button>
                    @endif
                    <p>{{ $report->evidence_original_name ?: 'Attached file' }}</p>
                @elseif ($report->transmission_type === 'lora')
                    <p>Compact only. LoRa fallback reports do not include picture or video payloads.</p>
                @else
                    <p>None</p>
                @endif
            </div>

            <div class="preview-card">
                <strong>Verification Selfie</strong>
                @if ($report->reporter_selfie_path)
                    <button type="button" class="media-trigger" data-media-viewer-trigger data-media-type="photo" data-media-src="{{ route('reports.selfie', $report) }}" data-media-title="{{ $report->report_code }} - {{ $report->reporter_selfie_original_name ?: 'Verification selfie' }}">
                        <img src="{{ route('reports.selfie', $report) }}" alt="Verification selfie for {{ $report->report_code }}" class="preview-thumb" style="max-height:220px;" loading="lazy" decoding="async" fetchpriority="low">
                        <span>Click to enlarge selfie</span>
                    </button>
                    <p>{{ $report->reporter_selfie_original_name ?: 'Verification selfie' }}</p>
                @else
                    <p>{{ $isLoRa ? 'LoRa reports do not include a live verification selfie. Check report authenticity below.' : 'None' }}</p>
                @endif
            </div>

            <div class="preview-card">
                <strong>Map location</strong>
                @if ($hasCoordinates)
                    <div class="latest-inline-map incident-feed-modal-map" data-incident-map data-map-scope="single" data-report-id="{{ $report->id }}" data-latitude="{{ (float) $report->latitude }}" data-longitude="{{ (float) $report->longitude }}" data-location-text="{{ e($report->location_text) }}" data-points="{{ e(json_encode($inlineMapPayload)) }}"></div>
                    <p>{{ $isLoRa ? 'Registered node location' : 'GPS' }} {{ number_format((float) $report->latitude, 6) }}, {{ number_format((float) $report->longitude, 6) }}</p>
                    <button type="button" class="btn btn-secondary" data-report-map-trigger data-map-title="{{ e($report->report_code.' - '.$report->location_text) }}" data-report-id="{{ $report->id }}" data-latitude="{{ (float) $report->latitude }}" data-longitude="{{ (float) $report->longitude }}" data-location-text="{{ e($report->location_text) }}" data-points="{{ e(json_encode($inlineMapPayload)) }}">Open Map Pin</button>
                @else
                    <p>{{ $isLoRa ? 'No registered node coordinates are available. Check the official node location before using a map.' : 'No GPS pin was attached. Use the written location from the report.' }}</p>
                @endif
            </div>
        </div>

        @if ($isLoRa)
            <div class="dual-grid admin-review-lora-details">
                <section class="detail-card"><h3>LoRa delivery and source</h3><p class="section-copy">Website times use Philippines time (Asia/Manila). The original node timestamp is shown as supplied.</p>@include('lora-reports.partials.metadata', ['report' => $report])</section>
                @include('lora-reports.partials.verification', ['report' => $report])
            </div>
        @endif
        <div class="dual-grid responder-feed-actions-grid">
            <div class="detail-card">
                <strong>Admin process</strong>
                <div class="tag-row">
                    <span class="tag {{ $statusDisplayTone($report->status) }}">{{ $statusDisplayLabel($report->status) }}</span>
                    <span class="tag {{ $tone($report->severity_display) }}">{{ $report->severity_display }}</span>
                </div>
                <p>{{ $report->status === 'received' ? 'No admin has responded or cancelled this report yet.' : 'Current saved coordination status: '.$statusDisplayLabel($report->status).'.' }}</p>
                <div class="meta-list">
                    <div class="meta-row">
                        <span>Submitted</span>
                        <strong>{{ $reviewTime($report->created_at) }}</strong>
                    </div>
                    <div class="meta-row">
                        <span>Status updated</span>
                        <strong>{{ $reviewTime($report->status_updated_at) }}</strong>
                    </div>
                    <div class="meta-row">
                        <span>Last admin message</span>
                        <strong>{{ $report->response_notes ?: 'No response message yet.' }}</strong>
                    </div>
                    <div class="meta-row"><span>Last handled by</span><strong>{{ $lastHandled['actor_name'] ?? 'No recorded admin action yet' }}</strong></div>
                </div>
                <p class="section-copy">Philippines time (Asia/Manila). See System Log for officer activity and duty records.</p>
            </div>

            <div class="detail-card incident-actions">
                <strong>Admin decision</strong>
                @include('reports.partials.severity-review-form', ['report' => $report])
                <p>{{ $dispatchSummary($report->severity_display) }} Respond or cancel actions require a message so the sender receives a clear update.</p>
                @if ($isLoRa)<p class="section-copy">The user receives the response through NODE_1 and LoRa. A gateway broadcast does not confirm receipt by the originating node. Only the beginning of long messages is sent over LoRa; keep responses brief.</p>@endif
                <form method="POST" action="{{ route('reports.coordination', $report) }}" class="stack">
                    @csrf
                    <input type="hidden" name="return_to" value="{{ $reviewReturnTo }}">
                    <input type="hidden" name="review_report_id" value="{{ $report->id }}">
                    @if ((int) old('review_report_id') === $report->id && $errors->any())
                        <p class="flash error" role="alert">{{ $errors->first() }}</p>
                    @endif
                    <label>Message to the user<textarea name="response_notes" class="textarea" maxlength="1000" placeholder="Required message for Respond or Cancel">{{ (int) old('review_report_id') === $report->id ? old('response_notes', $report->response_notes) : $report->response_notes }}</textarea></label>
                    <div class="action-row">
                        @if ($report->transmission_type === 'lora' && $report->status === 'received')
                            <button type="submit" name="status" value="acknowledged" class="action-btn primary">Acknowledge</button>
                        @endif
                        <button type="submit" name="status" value="responding" class="action-btn secondary">Respond</button>
                        <button type="submit" name="status" value="resolved" class="action-btn primary">Set Done</button>
                        <button type="submit" name="status" value="rejected" class="action-btn danger">Cancel</button>
                        <button type="button" class="action-btn secondary" data-report-row-close>Close</button>
                    </div>
                </form>
                <div class="action-row">
                    <form method="POST" action="{{ route('reports.destroy', $report) }}" onsubmit="return confirm('Delete this report from the queue?');">
                        @csrf
                        @method('DELETE')
                        <input type="hidden" name="return_to" value="{{ $reviewReturnTo }}">
                        <button type="submit" class="action-btn danger">Delete Report</button>
                    </form>
                </div>
            </div>
        </div>
        @if (count($report->coordination_log ?? []))
            <details class="detail-card admin-review-history"><summary>Report history</summary>
                @foreach (array_reverse($report->coordination_log) as $entry)
                    <p><strong>{{ $entry['label'] ?? (($entry['event'] ?? '') === 'lora_alert_ingested' ? 'Received from '.$report->lora_sender_id : 'Report update') }}</strong><br>{{ $entry['actor_name'] ?? 'System / node' }} · {{ $historyTime($entry) }}@if (! empty($entry['response_notes']))<br>{{ $entry['response_notes'] }}@endif</p>
                @endforeach
            </details>
        @endif
    </div>
</div>
