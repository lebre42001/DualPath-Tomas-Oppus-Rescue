@php
    $labels = ['received' => 'Pending review', 'acknowledged' => 'Under review', 'dispatched' => 'Dispatched', 'responding' => 'Responding', 'resolved' => 'Done', 'rejected' => 'Cancelled'];
    $ingest = collect($report->coordination_log ?? [])->firstWhere('event', 'lora_alert_ingested') ?? [];
    $formatTime = static fn ($value) => $value?->timezone(config('app.timezone'))->format('M d, Y H:i:s T') ?? 'Not available';
@endphp
<div class="incident-feed-expanded dashboard-modal-source lora-review-detail">
    <div class="lora-review-heading">
        <p class="panel-kicker">{{ $report->lora_sender_id ?: 'LoRa node' }} · {{ $report->report_code }}</p>
        <h2>{{ $report->incident_type }}</h2>
        <div class="tag-row"><span class="tag blue">{{ $labels[$report->status] ?? ucfirst($report->status) }}</span><span class="tag neutral">{{ $report->severity_display }}</span></div>
    </div>
    <section class="detail-card">
        <h3>Emergency description</h3>
        <p class="lora-review-description">{{ $report->description ?: 'No description provided.' }}</p>
    </section>
    <dl class="lora-review-metadata">
        <div><dt>Sender</dt><dd>{{ $report->reporter_name ?: 'Unknown sender' }}</dd></div>
        <div><dt>Message ID</dt><dd>{{ $report->lora_sequence ?: 'Not available' }}</dd></div>
        <div><dt>Origin node</dt><dd>{{ $report->lora_sender_id ?: 'Unknown' }}</dd></div>
        <div><dt>Gateway</dt><dd>{{ $report->lora_gateway_id ?: 'Unknown' }}</dd></div>
        <div><dt>Received on website</dt><dd>{{ $formatTime($report->created_at) }}</dd></div>
        <div><dt>Original submitted time</dt><dd>{{ $ingest['created_at'] ?? 'Not supplied by node' }}</dd></div>
        <div><dt>Location</dt><dd>{{ $report->location_text ?: 'Location not configured' }}</dd></div>
        <div><dt>Coordinates</dt><dd>@if ($report->latitude !== null && $report->longitude !== null){{ $report->latitude }}, {{ $report->longitude }} <a href="https://www.google.com/maps/search/?api=1&amp;query={{ rawurlencode($report->latitude.','.$report->longitude) }}" target="_blank" rel="noopener">Open map</a>@else Not registered @endif</dd></div>
        <div><dt>Route / hops</dt><dd>{{ $ingest['route'] ?? 'Not supplied' }} / {{ $ingest['hop'] ?? 'Unknown' }}</dd></div>
        <div><dt>Battery at submission</dt><dd>{{ ($ingest['battery'] ?? null) && ! in_array(strtoupper($ingest['battery']), ['NA', 'N/A']) ? $ingest['battery'] : 'No voltage reading' }}</dd></div>
        <div><dt>Gateway radio signal</dt><dd>RSSI {{ $report->lora_gateway_rssi ?? '—' }} dBm · SNR {{ $report->lora_gateway_snr ?? '—' }} dB</dd></div>
        <div><dt>Status updated</dt><dd>{{ $formatTime($report->status_updated_at) }}</dd></div>
        <div><dt>Acknowledgment queued</dt><dd>{{ $report->lora_ack_queued_at ? $formatTime($report->lora_ack_queued_at) : 'Awaiting admin action' }}</dd></div>
        <div><dt>Gateway broadcast</dt><dd>{{ $report->lora_ack_delivered_at ? $formatTime($report->lora_ack_delivered_at) : 'Not broadcast yet' }}</dd></div>
    </dl>
    <section class="detail-card">
        <h3>Admin response</h3>
        <p>{{ $report->response_notes ?: 'No admin message saved yet.' }}</p>
        <p class="section-copy">The user receives the response through NODE_1 and LoRa. A gateway broadcast does not confirm receipt by the originating node.</p>
        <form method="POST" action="{{ route('reports.coordination', $report) }}" class="stack">
            @csrf
            <input type="hidden" name="review_report_id" value="{{ $report->id }}">
            @if ((int) old('review_report_id') === $report->id && $errors->any())
                <p class="flash error" role="alert">{{ $errors->first() }}</p>
            @endif
            <label>Message to the user<textarea name="response_notes" class="textarea" maxlength="1000" rows="3" placeholder="Optional for acknowledgment; required for Respond or Cancel">{{ (int) old('review_report_id') === $report->id ? old('response_notes', $report->response_notes) : $report->response_notes }}</textarea></label>
            <p class="section-copy">Only the beginning of long messages is sent over LoRa. Keep your response brief.</p>
            <div class="action-row">
                @if ($report->status === 'received')<button type="submit" name="status" value="acknowledged" class="btn btn-primary">Acknowledge</button>@endif
                <button type="submit" name="status" value="responding" class="btn btn-secondary">Respond</button>
                <button type="submit" name="status" value="resolved" class="btn btn-secondary">Set Done</button>
                <button type="submit" name="status" value="rejected" class="btn btn-danger">Cancel report</button>
                <button type="button" class="btn btn-secondary" data-report-row-close>Close</button>
            </div>
        </form>
    </section>
    @if (count($report->coordination_log ?? []))
        <details class="detail-card"><summary>Report history</summary>
            @foreach ($report->coordination_log as $entry)
                <p>{{ $entry['label'] ?? (($entry['event'] ?? '') === 'lora_alert_ingested' ? 'Received from '.$report->lora_sender_id : 'Report update') }} · {{ $entry['occurred_at'] ?? $entry['at'] ?? '' }}@if (! empty($entry['response_notes']))<br>{{ $entry['response_notes'] }}@endif</p>
            @endforeach
        </details>
    @endif
</div>
