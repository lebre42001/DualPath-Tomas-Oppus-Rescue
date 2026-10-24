@php
    $category = $report->current_severity_category;
    $categoryTone = match ($category) { 'Critical' => 'red', 'Serious', 'Needs review' => 'amber', 'Minor' => 'green', default => 'blue' };
    $statusLabel = match ($report->status) { 'received' => 'Pending', 'acknowledged' => 'Under review', 'dispatched' => 'Response started', 'responding' => 'Responded', 'resolved' => 'Resolved', 'rejected' => 'Cancelled', default => ucfirst($report->status) };
    $hasCoordinates = $report->latitude !== null && $report->longitude !== null;
@endphp
<article class="online-report-detail">
    <header class="online-report-overview">
        <div><h2>{{ $report->incident_type }}</h2><p>{{ $report->reporter_name ?: ($report->reporter?->name ?: 'Unknown reporter') }}</p><small>Submitted {{ $report->created_at?->timezone('Asia/Manila')->format('M d, Y h:i A') ?? 'Not recorded' }} · Philippines time</small></div>
        <div class="online-report-overview-actions">
            <span class="tag neutral">{{ $statusLabel }}</span>
            <span class="tag {{ $categoryTone }}" data-current-category-for="{{ $report->id }}">{{ $category }}</span>
            <a class="btn btn-secondary" href="{{ route('reports.print', $report) }}" target="_blank" rel="noopener">Print report</a>
        </div>
    </header>
    <section class="online-report-section"><h3>Description</h3><p class="online-report-copy">{{ $report->description ?: 'No description provided.' }}</p></section>
    <section class="online-report-section"><h3>Location &amp; contact</h3>
        <p>{{ $report->location_text ?: 'Location not recorded.' }}</p>
        @if ($hasCoordinates)<p>GPS {{ $report->latitude }}, {{ $report->longitude }} <button type="button" class="btn btn-secondary" data-report-map-trigger data-report-id="{{ $report->id }}" data-map-title="{{ $report->report_code }} — Location" data-latitude="{{ $report->latitude }}" data-longitude="{{ $report->longitude }}" data-location-text="{{ $report->location_text }}">View map</button></p>@endif
        @if ($report->reporter_contact)<p>Reporter contact: {{ $report->reporter_contact }}</p>@endif
        @if ($report->assignedResponder)<p>Assigned responder: {{ $report->assignedResponder->name }}</p>@endif
    </section>
    <section class="online-report-section"><h3>Severity / AI analysis</h3>@include('reports.partials.ai-analysis', ['report' => $report, 'compact' => false])</section>
    @if ($report->reported_fatality_flag)<p class="online-report-section">Reported fatality: reported by sender; requires verification.</p>@endif
    @if ($report->response_notes)<section class="online-report-section"><h3>Latest admin response</h3><p class="online-report-copy">{{ $report->response_notes }}</p></section>@endif
    @if ($report->evidence_path || $report->reporter_selfie_path)
        <details class="online-report-section"><summary>Attachments</summary><div class="online-report-attachments">
            @if ($report->evidence_path)
                <button type="button" class="media-trigger" data-media-viewer-trigger data-media-type="{{ $report->evidence_type === 'video' ? 'video' : 'photo' }}" data-media-src="{{ route('reports.evidence', $report) }}" data-media-title="{{ $report->report_code }} — Scene evidence">
                    @if ($report->evidence_type !== 'video')<img src="{{ route('reports.evidence', $report) }}" alt="Scene evidence" loading="lazy">@endif<span>View scene {{ $report->evidence_type === 'video' ? 'video' : 'photo' }}</span>
                </button>
            @endif
            @if ($report->reporter_selfie_path)<button type="button" class="media-trigger" data-media-viewer-trigger data-media-type="photo" data-media-src="{{ route('reports.selfie', $report) }}" data-media-title="{{ $report->report_code }} — Verification selfie"><img src="{{ route('reports.selfie', $report) }}" alt="Verification selfie" loading="lazy"><span>View verification selfie</span></button>@endif
        </div></details>
    @endif
    <details class="online-report-section online-report-admin-actions" @if ((int) old('severity_review_report_id') === (int) $report->id || (int) old('review_report_id') === (int) $report->id) open @endif><summary>Assessment &amp; admin actions</summary>
        @include('reports.partials.severity-review-form', ['report' => $report])
        <form method="POST" action="{{ route('reports.coordination', $report) }}" class="stack">
            @csrf
            <input type="hidden" name="review_report_id" value="{{ $report->id }}">
            <label>Response message<textarea name="response_notes" class="textarea" placeholder="Required for Respond or Cancel">{{ old('review_report_id') == $report->id ? old('response_notes', $report->response_notes) : $report->response_notes }}</textarea></label>
            @if ((int) old('review_report_id') === (int) $report->id) @error('response_notes')<p role="alert">{{ $message }}</p>@enderror @endif
            <div class="action-row"><button type="submit" name="status" value="responding" class="action-btn secondary">Respond</button><button type="submit" name="status" value="resolved" class="action-btn primary">Set Done</button><button type="submit" name="status" value="rejected" class="action-btn danger">Cancel</button></div>
        </form>
        <form method="POST" action="{{ route('reports.destroy', $report) }}" onsubmit="return confirm('Delete this report from the queue?');">@csrf @method('DELETE')<button type="submit" class="action-btn danger">Delete report</button></form>
    </details>
</article>
