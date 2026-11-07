@php
    $labels = ['received' => 'Pending review', 'acknowledged' => 'Under review', 'dispatched' => 'Dispatched', 'responding' => 'Responding', 'resolved' => 'Done', 'rejected' => 'Cancelled'];
    $ingest = collect($report->coordination_log ?? [])->firstWhere('event', 'lora_alert_ingested') ?? [];
    $formatTime = static fn ($value) => $value?->timezone('Asia/Manila')->format('M d, Y h:i:s A') ?? 'Not available';
    $verificationLabels = ['unverified' => 'Unverified', 'confirmed' => 'Confirmed', 'needs_clarification' => 'Needs clarification', 'false_report' => 'False report'];
@endphp
    <section class="detail-card">
        <h3>Verify report authenticity</h3>
        <p class="section-copy">This records whether the report is credible. Rescue progress is managed separately below. Repeat submissions alone do not prove a false report.</p>
        @if ($report->lora_verified_at)
            <p>{{ $verificationLabels[$report->lora_verification] ?? 'Unverified' }} · {{ $formatTime($report->lora_verified_at) }}</p>
            <p>{{ $report->lora_verification_notes }}</p>
        @endif
        <form method="POST" action="{{ route('lora-reports.verification', $report) }}" class="stack">
            @csrf
            <input type="hidden" name="review_report_id" value="{{ $report->id }}">
            <label>Verification result<select name="verification" required>
                <option value="">Select after checking the report</option>
                @foreach (array_diff_key($verificationLabels, ['unverified' => true]) as $value => $label)
                    <option value="{{ $value }}" @selected($report->lora_verification === $value)>{{ $label }}</option>
                @endforeach
            </select></label>
            <label>Reason / clarification message<textarea class="textarea" name="verification_notes" rows="2" maxlength="500" required placeholder="Record what you checked or ask for the missing details.">{{ $report->lora_verification_notes }}</textarea></label>
            <button type="submit" class="btn btn-primary">Save verification</button>
        </form>
    </section>