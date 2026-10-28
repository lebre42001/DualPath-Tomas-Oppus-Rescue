@php
    $isLoRaReport = strtolower(trim((string) $report->transmission_type)) === 'lora';
    $ingest = $isLoRaReport ? (collect($report->coordination_log ?? [])->firstWhere('event', 'lora_alert_ingested') ?? []) : [];
    $coordinateName = $isLoRaReport ? 'Registered coordinates' : 'GPS';
    $formatTime = static fn ($value) => $value?->timezone('Asia/Manila')->format('M d, Y h:i:s A') ?? 'Not recorded';
    $coordinateOnly = $report->latitude !== null && $report->longitude !== null && preg_match('/^(?:GPS\s*)?[+-]?\d+(?:\.\d+)?\s*,\s*[+-]?\d+(?:\.\d+)?$/i', trim((string) $report->location_text));
@endphp
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
                        @if ($report->latitude !== null && $report->longitude !== null){{ $coordinateOnly ? $coordinateName.': ' : '('.$coordinateName.': ' }}{{ $report->latitude }}, {{ $report->longitude }}{{ $coordinateOnly ? '' : ')' }}@endif
                    @endif
                </dd></div>
                @if ($isLoRaReport)
                    <div><dt>Verification</dt><dd>{{ ['confirmed' => 'Confirmed', 'needs_clarification' => 'Needs clarification', 'false_report' => 'False report'][$report->lora_verification] ?? 'Unverified' }}</dd></div>
                    @if ($includeLocation)<div class="wide"><dt>Actual accident landmark</dt><dd>{{ $report->lora_incident_landmark ?: 'Not supplied by older firmware' }}</dd></div>@endif
                    <div><dt>Message ID</dt><dd>{{ $report->lora_sequence ?: 'Not recorded' }}</dd></div>
                    <div><dt>Origin node</dt><dd>{{ $report->lora_sender_id ?: 'Not recorded' }}</dd></div>
                    <div><dt>Gateway</dt><dd>{{ $report->lora_gateway_id ?: 'Not recorded' }}</dd></div>
                    <div><dt>Original node timestamp</dt><dd>{{ $ingest['created_at'] ?? 'Not supplied by node' }}</dd></div>
                    <div class="wide"><dt>Route / hops</dt><dd>{{ $ingest['route'] ?? 'Not supplied' }} / {{ $ingest['hop'] ?? 'Unknown' }}</dd></div>
                    <div><dt>Battery at submission</dt><dd>{{ ($ingest['battery'] ?? null) && ! in_array(strtoupper($ingest['battery']), ['NA', 'N/A']) ? $ingest['battery'] : 'No voltage reading' }}</dd></div>
                    <div><dt>Gateway radio signal</dt><dd>@if ($report->lora_gateway_rssi !== null)RSSI {{ $report->lora_gateway_rssi }} dBm @else RSSI not recorded @endif · @if ($report->lora_gateway_snr !== null)SNR {{ $report->lora_gateway_snr }} dB @else SNR not recorded @endif</dd></div>
                    <div><dt>Status updated</dt><dd>{{ $formatTime($report->status_updated_at) }}</dd></div>
                    <div><dt>Acknowledgment queued</dt><dd>{{ $report->lora_ack_queued_at ? $formatTime($report->lora_ack_queued_at) : 'Awaiting admin action' }}</dd></div>
                    <div><dt>Gateway broadcast</dt><dd>{{ $report->lora_ack_delivered_at ? $formatTime($report->lora_ack_delivered_at) : 'Not broadcast yet' }}</dd></div>
                @endif
            </dl>
            @if ($isLoRaReport)
                <p class="print-note">LoRa intake does not run automatic severity AI. A gateway broadcast does not confirm acknowledgment receipt by the originating node.</p>
                @unless (in_array($report->ai_source, ['human_responder', 'manual_override', 'text_human_override'], true))<p class="print-note">The intake severity category is not a confirmed medical assessment.</p>@endunless
            @endif
            <section><h3>Description</h3><p class="print-copy">{{ $report->description ?: 'No description provided.' }}</p></section>
            @if ($isLoRaReport && $report->lora_verified_at)<section><h3>Admin verification notes</h3><p class="print-copy">{{ $report->lora_verification_notes }}</p></section>@endif
            @if ($report->ai_top_category)<p><strong>{{ in_array($report->ai_source, ['human_responder', 'manual_override', 'text_human_override'], true) ? 'Original AI analysis' : 'AI analysis' }}:</strong> {{ $report->ai_top_category['label'] }} — model score {{ number_format($report->ai_top_category['score'], 1) }}% (uncalibrated). @if ($report->isAwaitingTextSeverityConfirmation() || $report->operational_severity === null)Admin confirmation required.@endif</p>@endif
            <section><h3>Assessment</h3><p>{{ \App\Support\ReportAssessmentSummary::forReport($report) }}</p></section>
            @if ($report->reported_fatality_flag)<p>Reported fatality: reported by sender; requires verification.</p>@endif
            @if ($report->assignedResponder)<p><strong>Assigned responder:</strong> {{ $report->assignedResponder->name }}</p>@endif
            @if ($report->response_notes)<section><h3>Latest admin response</h3><p class="print-copy">{{ $report->response_notes }}</p></section>@endif
            @if ($report->resolved_at)<p><strong>Resolved:</strong> {{ $report->resolved_at->timezone('Asia/Manila')->format('M d, Y h:i A') }}</p>@endif
        </article>
        @if ($includePhoto && $report->evidence_type === 'photo' && filled($report->evidence_path))
            <figure class="print-scene-photo"><img src="{{ route('reports.evidence', $report) }}" alt="Scene photo for {{ $report->report_code }}" data-print-scene-photo><figcaption>Scene photo · {{ $report->report_code }}</figcaption></figure>
        @endif
