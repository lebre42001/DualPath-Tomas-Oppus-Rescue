<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ $report->report_code }} - Online report</title>@vite('resources/js/civilian-report-print.js')</head>
<body class="online-report-print">
    <div class="print-toolbar"><p>Print this record or choose <strong>Save as PDF</strong> in the print dialog.</p><button type="button" data-print-reports>Print report</button></div>
    <main>
        <header class="print-header"><p class="brand">Tomas Oppus Rescue</p><h1>Online report record</h1><p class="print-meta">Generated {{ $printedAt }} · Philippines time (Asia/Manila)</p></header>
        <article class="print-report">
            <header><h2>{{ $report->report_code }}</h2><p>{{ $report->incident_type }} · {{ $report->created_at?->timezone('Asia/Manila')->format('M d, Y h:i A') ?? 'Date not recorded' }}</p></header>
            <dl class="print-facts">
                <div><dt>Reporter</dt><dd>{{ $report->reporter_name ?: ($report->reporter?->name ?: 'Unknown reporter') }}</dd></div>
                <div><dt>Contact</dt><dd>{{ $report->reporter_contact ?: 'Not recorded' }}</dd></div>
                <div><dt>Status</dt><dd>{{ match ($report->status) { 'received' => 'Pending', 'acknowledged' => 'Under review', 'dispatched' => 'Response started', 'responding' => 'Responded', 'resolved' => 'Resolved', 'rejected' => 'Cancelled', default => ucfirst($report->status) } }}</dd></div>
                <div><dt>Current severity</dt><dd>{{ $report->current_severity_category }}@if ($report->ai_review_required || $report->operational_severity === null) <span class="print-note">— responder review required</span>@endif</dd></div>
                <div class="wide"><dt>Location</dt><dd>{{ $report->location_text ?: 'Not recorded' }}@if ($report->latitude !== null && $report->longitude !== null) (GPS: {{ $report->latitude }}, {{ $report->longitude }})@endif</dd></div>
            </dl>
            <section><h3>Description</h3><p class="print-copy">{{ $report->description ?: 'No description provided.' }}</p></section>
            @if ($report->ai_top_category)<p><strong>{{ in_array($report->ai_source, ['human_responder', 'manual_override', 'text_human_override'], true) ? 'Original AI analysis' : 'AI analysis' }}:</strong> {{ $report->ai_top_category['label'] }} — model score {{ number_format($report->ai_top_category['score'], 1) }}% (uncalibrated). @if ($report->isAwaitingTextSeverityConfirmation() || $report->operational_severity === null)Admin confirmation required.@endif</p>@endif
            @if ($report->operational_ai_summary)<section><h3>Assessment summary</h3><p class="print-copy">{{ $report->operational_ai_summary }}</p></section>@endif
            @if ($report->reported_fatality_flag)<p>Reported fatality: reported by sender; requires verification.</p>@endif
            @if ($report->assignedResponder)<p><strong>Assigned responder:</strong> {{ $report->assignedResponder->name }}</p>@endif
            @if ($report->response_notes)<section><h3>Latest admin response</h3><p class="print-copy">{{ $report->response_notes }}</p></section>@endif
            @if ($report->resolved_at)<p><strong>Resolved:</strong> {{ $report->resolved_at->timezone('Asia/Manila')->format('M d, Y h:i A') }}</p>@endif
        </article>
    </main>
</body></html>
