@php
    $reviewLabels = ['received' => 'Pending review', 'acknowledged' => 'Under review', 'dispatched' => 'Dispatched', 'responding' => 'Responding', 'resolved' => 'Done', 'rejected' => 'Cancelled'];
    $reviewReturnTo = $reviewReturnTo ?? 'monitoring';
    $formatTime = static fn ($value) => $value?->timezone('Asia/Manila')->format('M d, Y h:i:s A') ?? 'Not available';
    $historyTime = static function ($entry) {
        $value = $entry['occurred_at'] ?? $entry['at'] ?? null;
        try { return $value ? \Illuminate\Support\Carbon::parse($value)->timezone('Asia/Manila')->format('M d, Y h:i:s A') : 'Time not recorded'; }
        catch (\Throwable) { return 'Time not recorded'; }
    };
    $lastHandled = collect($report->coordination_log ?? [])->reverse()->first(fn ($entry) => in_array(strtolower($entry['actor_role'] ?? ''), ['admin', 'responder'], true));
    $hasCoordinates = $report->latitude !== null && $report->longitude !== null;
    $inlineMapPayload = $hasCoordinates ? [[
        'id' => 'modal-sender-'.$report->id,
        'incident_type' => $report->incident_type,
        'location_text' => $report->location_text,
        'severity' => $report->operational_severity,
        'severity_display' => $report->severity_display,
        'latitude' => (float) $report->latitude,
        'longitude' => (float) $report->longitude,
        'map_role' => 'sender',
        'readable_location' => $report->location_text,
        'google_maps_url' => 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($report->latitude.','.$report->longitude),
    ]] : [];
@endphp
<div class="incident-feed-expanded dashboard-modal-source lora-review-detail online-review-detail" data-admin-report-review="online">
    <div class="lora-review-heading">
        <div class="lora-review-heading-copy">
            <p class="panel-kicker">Online · {{ $report->report_code }}</p>
            <h2>{{ $report->incident_type }}</h2>
            <div class="tag-row">
                <span class="tag blue">{{ $reviewLabels[$report->status] ?? ucfirst($report->status) }}</span>
                <span class="tag neutral" data-current-category-for="{{ $report->id }}">{{ $report->severity_display }}</span>
                @if ($report->reported_fatality_flag)<span class="tag red">POSSIBLE FATALITY · VERIFY NOW</span>@endif
            </div>
            <p><strong>Sender:</strong> {{ $report->reporter_name ?: $report->reporter?->name ?: 'Unknown sender' }}</p>
            <p class="section-copy">Website times use Philippines time (Asia/Manila).</p>
        </div>
        <a class="btn btn-secondary" href="{{ route('reports.print', $report) }}" target="_blank" rel="noopener">Print report</a>
    </div>
    <section class="detail-card">
        <h3>Emergency description</h3>
        <p class="lora-review-description">{{ $report->description ?: 'No description provided.' }}</p>
    </section>
    <section class="detail-card">
        <h3>Description analysis and severity review</h3>
        <p class="section-copy">Severity is assessed from the submitted description. An admin must confirm or correct the assessment before it is final.</p>
        @include('reports.partials.ai-analysis', ['report' => $report, 'compact' => false])
        @include('reports.partials.photo-relevance-badges', ['report' => $report])
        @include('reports.partials.severity-review-form', ['report' => $report])
    </section>
    <dl class="lora-review-metadata">
        <div><dt>Sender</dt><dd>{{ $report->reporter_name ?: $report->reporter?->name ?: 'Unknown sender' }}</dd></div>
        <div><dt>Report ID</dt><dd>{{ $report->report_code }}</dd></div>
        <div><dt>Received on website</dt><dd>{{ $formatTime($report->created_at) }}</dd></div>
        <div><dt>Status updated</dt><dd>{{ $formatTime($report->status_updated_at) }}</dd></div>
        <div><dt>Location</dt><dd>{{ $report->location_text ?: 'Location not supplied' }}</dd></div>
        <div><dt>Coordinates</dt><dd>@if ($hasCoordinates){{ $report->latitude }}, {{ $report->longitude }}@else Not supplied @endif</dd></div>
        <div><dt>Last handled by</dt><dd>{{ $lastHandled['actor_name'] ?? 'No recorded admin action yet' }}</dd></div>
        <div><dt>Current status</dt><dd>{{ $reviewLabels[$report->status] ?? ucfirst($report->status) }}</dd></div>
    </dl>
    <section class="detail-card">
        <h3>Evidence</h3>
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
        @else
            <p>No evidence attached.</p>
        @endif
    </section>
    <section class="detail-card">
        <h3>Verification Selfie</h3>
        @if ($report->reporter_selfie_path)
            <div class="tag-row"><span class="tag neutral">Selfie Verified</span></div>
            <button type="button" class="media-trigger" data-media-viewer-trigger data-media-type="photo" data-media-src="{{ route('reports.selfie', $report) }}" data-media-title="{{ $report->report_code }} - {{ $report->reporter_selfie_original_name ?: 'Verification selfie' }}">
                <img src="{{ route('reports.selfie', $report) }}" alt="Verification selfie for {{ $report->report_code }}" class="preview-thumb" loading="lazy" decoding="async" fetchpriority="low">
                <span>Click to enlarge selfie</span>
            </button>
            <p>{{ $report->reporter_selfie_original_name ?: 'Verification selfie' }}</p>
        @else
            <p>No verification selfie attached.</p>
        @endif
    </section>
    <section class="detail-card">
        <h3>Map location</h3>
        @if ($hasCoordinates)
            <div class="latest-inline-map incident-feed-modal-map" data-incident-map data-map-scope="single" data-report-id="{{ $report->id }}" data-latitude="{{ (float) $report->latitude }}" data-longitude="{{ (float) $report->longitude }}" data-location-text="{{ e($report->location_text) }}" data-points="{{ e(json_encode($inlineMapPayload)) }}"></div>
            <p>GPS {{ number_format((float) $report->latitude, 6) }}, {{ number_format((float) $report->longitude, 6) }}</p>
            <button type="button" class="btn btn-secondary" data-report-map-trigger data-map-title="{{ e($report->report_code.' - '.$report->location_text) }}" data-report-id="{{ $report->id }}" data-latitude="{{ (float) $report->latitude }}" data-longitude="{{ (float) $report->longitude }}" data-location-text="{{ e($report->location_text) }}" data-points="{{ e(json_encode($inlineMapPayload)) }}">Open Map Pin</button>
        @else
            <p>No GPS pin was attached. Use the written location from the report.</p>
        @endif
    </section>
    <section class="detail-card">
        <h3>Admin response</h3>
        <p>{{ $report->response_notes ?: 'No admin message saved yet.' }}</p>
        <p class="section-copy">The civilian receives saved response and status updates in their account. Respond or cancel actions require a message.</p>
        <form method="POST" action="{{ route('reports.coordination', $report) }}" class="stack">
            @csrf
            <input type="hidden" name="return_to" value="{{ $reviewReturnTo }}">
            <input type="hidden" name="review_report_id" value="{{ $report->id }}">
            @if ((int) old('review_report_id') === $report->id && $errors->any())
                <p class="flash error" role="alert">{{ $errors->first() }}</p>
            @endif
            <label>Message to the user<textarea name="response_notes" class="textarea" maxlength="1000" rows="3" placeholder="Required message for Respond or Cancel">{{ (int) old('review_report_id') === $report->id ? old('response_notes', $report->response_notes) : $report->response_notes }}</textarea></label>
            <div class="action-row">
                <button type="submit" name="status" value="responding" class="btn btn-secondary">Respond</button>
                <button type="submit" name="status" value="resolved" class="btn btn-secondary">Set Done</button>
                <button type="submit" name="status" value="rejected" class="btn btn-danger">Cancel report</button>
                <button type="button" class="btn btn-secondary" data-report-row-close>Close</button>
            </div>
        </form>
        <form method="POST" action="{{ route('reports.destroy', $report) }}" onsubmit="return confirm('Delete this report from the queue?');">
            @csrf
            @method('DELETE')
            <input type="hidden" name="return_to" value="{{ $reviewReturnTo }}">
            <button type="submit" class="btn btn-danger">Delete Report</button>
        </form>
    </section>
    @if (count($report->coordination_log ?? []))
        <details class="detail-card"><summary>Report history</summary>
            @foreach (array_reverse($report->coordination_log) as $entry)
                <p><strong>{{ $entry['label'] ?? 'Report update' }}</strong><br>{{ $entry['actor_name'] ?? 'System' }} · {{ $historyTime($entry) }}@if (! empty($entry['response_notes']))<br>{{ $entry['response_notes'] }}@endif</p>
            @endforeach
        </details>
    @endif
</div>
