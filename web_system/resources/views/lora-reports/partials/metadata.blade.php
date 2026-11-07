@php
    $labels = ['received' => 'Pending review', 'acknowledged' => 'Under review', 'dispatched' => 'Dispatched', 'responding' => 'Responding', 'resolved' => 'Done', 'rejected' => 'Cancelled'];
    $ingest = collect($report->coordination_log ?? [])->firstWhere('event', 'lora_alert_ingested') ?? [];
    $formatTime = static fn ($value) => $value?->timezone('Asia/Manila')->format('M d, Y h:i:s A') ?? 'Not available';
    $verificationLabels = ['unverified' => 'Unverified', 'confirmed' => 'Confirmed', 'needs_clarification' => 'Needs clarification', 'false_report' => 'False report'];
@endphp
    <dl class="lora-review-metadata">
        <div><dt>Sender</dt><dd>{{ $report->reporter_name ?: 'Unknown sender' }}</dd></div>
        <div><dt>Message ID</dt><dd>{{ $report->lora_sequence ?: 'Not available' }}</dd></div>
        <div><dt>Origin node</dt><dd>{{ $report->lora_sender_id ?: 'Unknown' }}</dd></div>
        <div><dt>Gateway</dt><dd>{{ $report->lora_gateway_id ?: 'Unknown' }}</dd></div>
        <div><dt>Received on website</dt><dd>{{ $formatTime($report->created_at) }}</dd></div>
        <div><dt>Original submitted time</dt><dd>{{ $ingest['created_at'] ?? 'Not supplied by node' }}</dd></div>
        <div><dt>Location</dt><dd>{{ $report->location_text ?: 'Location not configured' }}</dd></div>
        <div><dt>Actual accident landmark</dt><dd>{{ $report->lora_incident_landmark ?: 'Not supplied by older firmware' }}</dd></div>
        <div><dt>Radio authentication</dt><dd>{{ $report->lora_radio_auth === 'hmac-sha256-v2' ? 'Origin tag verified by gateway (v2)' : 'Legacy — origin tag not verified' }}</dd></div>
        <div><dt>Coordinates</dt><dd>@if ($report->latitude !== null && $report->longitude !== null){{ $report->latitude }}, {{ $report->longitude }} <a href="https://www.google.com/maps/search/?api=1&amp;query={{ rawurlencode($report->latitude.','.$report->longitude) }}" target="_blank" rel="noopener">Open map</a>@else Not registered @endif</dd></div>
        <div><dt>Route / hops</dt><dd>{{ $ingest['route'] ?? 'Not supplied' }} / {{ $ingest['hop'] ?? 'Unknown' }}</dd></div>
        <div><dt>Battery at submission</dt><dd>{{ ($ingest['battery'] ?? null) && ! in_array(strtoupper($ingest['battery']), ['NA', 'N/A']) ? $ingest['battery'] : 'No voltage reading' }}</dd></div>
        <div><dt>Gateway radio signal</dt><dd>RSSI {{ $report->lora_gateway_rssi ?? '—' }} dBm · SNR {{ $report->lora_gateway_snr ?? '—' }} dB</dd></div>
        <div><dt>Status updated</dt><dd>{{ $formatTime($report->status_updated_at) }}</dd></div>
        <div><dt>Acknowledgment queued</dt><dd>{{ $report->lora_ack_queued_at ? $formatTime($report->lora_ack_queued_at) : 'Awaiting admin action' }}</dd></div>
        <div><dt>Gateway broadcast</dt><dd>{{ $report->lora_ack_delivered_at ? $formatTime($report->lora_ack_delivered_at) : 'Not broadcast yet' }}</dd></div>
    </dl>