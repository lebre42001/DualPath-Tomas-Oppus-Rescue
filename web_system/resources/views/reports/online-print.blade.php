@php
    $includePhoto = $includePhoto ?? false;
    $includeLocation = $includeLocation ?? false;
    $includeContact = $includeContact ?? false;
    $autoPrint = $autoPrint ?? false;
    $coordinateOnly = $report->latitude !== null && $report->longitude !== null && preg_match('/^(?:GPS\s*)?[+-]?\d+(?:\.\d+)?\s*,\s*[+-]?\d+(?:\.\d+)?$/i', trim((string) $report->location_text));
@endphp
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ $report->report_code }} - Online report</title>@vite('resources/js/civilian-report-print.js')</head>
<body class="online-report-print" data-auto-print="{{ $autoPrint ? 'true' : 'false' }}">
    <form method="GET" action="{{ route('reports.print', $report) }}" class="print-toolbar print-options" data-report-print-options>
        <div><strong>Choose print details</strong><p>Include sensitive details only when the recipient needs them. Verification selfies are excluded.</p></div>
        <div class="print-options-choices">
            <label><input type="checkbox" name="include_location" value="1" @checked($includeLocation)> Exact location &amp; GPS</label>
            <label><input type="checkbox" name="include_contact" value="1" @checked($includeContact)> Reporter contact number</label>
            @if ($report->evidence_type === 'photo' && $report->evidence_path)<label><input type="checkbox" name="include_photo" value="1" @checked($includePhoto)> Scene photo</label>@endif
        </div>
        <div class="print-options-actions"><button type="submit">Update preview</button><button type="submit" name="print" value="1">Print selected details</button></div>
        <p data-print-feedback role="status" hidden></p>
    </form>
    <main>
        <header class="print-header"><p class="brand">Tomas Oppus Rescue</p><h1>Online report record</h1><p class="print-meta">Generated {{ $printedAt }} · Philippines time (Asia/Manila)</p></header>
        <article class="print-report">
            <header><h2>{{ $report->report_code }}</h2><p>{{ $report->incident_type }} · {{ $report->created_at?->timezone('Asia/Manila')->format('M d, Y h:i A') ?? 'Date not recorded' }}</p></header>
            <dl class="print-facts">
                <div><dt>Reporter</dt><dd>{{ $report->reporter_name ?: ($report->reporter?->name ?: 'Unknown reporter') }}</dd></div>
                @if ($includeContact)<div><dt>Contact</dt><dd>{{ $report->reporter_contact ?: 'Not recorded' }}</dd></div>@endif
                <div><dt>Status</dt><dd>{{ match ($report->status) { 'received' => 'Pending', 'acknowledged' => 'Under review', 'dispatched' => 'Response started', 'responding' => 'Responded', 'resolved' => 'Resolved', 'rejected' => 'Cancelled', default => ucfirst($report->status) } }}</dd></div>
                <div><dt>Current severity</dt><dd>{{ $report->current_severity_category }}@if ($report->ai_review_required || $report->operational_severity === null) <span class="print-note">— responder review required</span>@endif</dd></div>
                <div class="wide"><dt>Location</dt><dd>
                    @if (! $includeLocation)Omitted from this copy.
                    @else
                        @if (! $coordinateOnly){{ $report->location_text ?: 'Location not recorded.' }}@endif
                        @if ($report->latitude !== null && $report->longitude !== null){{ $coordinateOnly ? 'GPS: ' : '(GPS: ' }}{{ $report->latitude }}, {{ $report->longitude }}{{ $coordinateOnly ? '' : ')' }}@endif
                    @endif
                </dd></div>
            </dl>
            <section><h3>Description</h3><p class="print-copy">{{ $report->description ?: 'No description provided.' }}</p></section>
            @if ($report->ai_top_category)<p><strong>{{ in_array($report->ai_source, ['human_responder', 'manual_override', 'text_human_override'], true) ? 'Original AI analysis' : 'AI analysis' }}:</strong> {{ $report->ai_top_category['label'] }} — model score {{ number_format($report->ai_top_category['score'], 1) }}% (uncalibrated). @if ($report->isAwaitingTextSeverityConfirmation() || $report->operational_severity === null)Admin confirmation required.@endif</p>@endif
            <section><h3>Assessment</h3><p>{{ \App\Support\ReportAssessmentSummary::forReport($report) }}</p></section>
            @if ($report->reported_fatality_flag)<p>Reported fatality: reported by sender; requires verification.</p>@endif
            @if ($report->assignedResponder)<p><strong>Assigned responder:</strong> {{ $report->assignedResponder->name }}</p>@endif
            @if ($report->response_notes)<section><h3>Latest admin response</h3><p class="print-copy">{{ $report->response_notes }}</p></section>@endif
            @if ($report->resolved_at)<p><strong>Resolved:</strong> {{ $report->resolved_at->timezone('Asia/Manila')->format('M d, Y h:i A') }}</p>@endif
        </article>
        @if ($includePhoto)
            <figure class="print-scene-photo"><img src="{{ route('reports.evidence', $report) }}" alt="Scene photo for {{ $report->report_code }}" data-print-scene-photo><figcaption>Scene photo · {{ $report->report_code }}</figcaption></figure>
        @endif
        <p class="print-note">Personal incident information · For authorized incident handling.</p>
    </main>
</body></html>
