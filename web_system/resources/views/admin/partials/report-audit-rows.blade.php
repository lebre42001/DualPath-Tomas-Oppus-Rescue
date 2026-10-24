@forelse ($auditTrail as $entry)
    @php
        $at = $entry->occurred_at?->timezone('Asia/Manila');
        $entryTitle = $entry->label.' - '.$entry->report_code;
        $statusLabels = ['received' => 'Pending', 'acknowledged' => 'Under review', 'dispatched' => 'Dispatched', 'responding' => 'Responded', 'resolved' => 'Done', 'rejected' => 'Cancelled', 'deleted' => 'Deleted'];
        $before = $statusLabels[$entry->status_before] ?? ($entry->status_before ?: 'Not recorded');
        $after = $statusLabels[$entry->status_after] ?? ($entry->status_after ?: 'Not recorded');
    @endphp
    <tr class="command-audit-row" data-command-audit-row data-command-audit-title="{{ $entryTitle }}" tabindex="0" role="button" aria-label="View audit details for {{ $entryTitle }}">
        <td data-label="Action"><strong>{{ $entry->label }}</strong><span>{{ \Illuminate\Support\Str::limit($entry->notes ?: 'View action details', 80) }}</span></td>
        <td data-label="Incident"><strong>{{ $entry->incident_type }}</strong><span>{{ $entry->report_code }}</span>@unless ($entry->report)<span>Report deleted</span>@endunless</td>
        <td data-label="Source"><span class="tag {{ $entry->transmission_type === 'lora' ? 'amber' : 'blue' }}">{{ $entry->transmission_type === 'lora' ? 'LoRa' : 'Online' }}</span>@if ($entry->node_id)<span>{{ $entry->node_id }}</span>@endif</td>
        <td data-label="Status"><strong>{{ $after }}</strong>@if ($entry->status_before && $entry->status_before !== $entry->status_after)<span>From {{ $before }}</span>@endif</td>
        <td data-label="Actor"><strong>{{ $entry->actor_name }}</strong><span>{{ $entry->actor_role }}{{ $entry->actor_id ? ' #'.$entry->actor_id : '' }}</span></td>
        <td data-label="Time"><strong>{{ $at?->diffForHumans() ?? 'Not recorded' }}</strong><span>{{ $at?->format('M d, Y h:i:s A T') }}</span>
            <template data-command-audit-template>
                <div class="command-audit-modal-grid">
                    <article class="detail-card"><span class="incident-code">Action</span><strong>{{ $entry->label }}</strong><p>{{ $entry->report_code }} · {{ $entry->incident_type }}</p></article>
                    <article class="detail-card"><span class="incident-code">Admin</span><strong>{{ $entry->actor_name }}</strong><p>{{ $entry->actor_role }}{{ $entry->actor_id ? ' #'.$entry->actor_id : ' · ID not recorded' }}</p></article>
                    <article class="detail-card"><span class="incident-code">Source</span><strong>{{ $entry->transmission_type === 'lora' ? 'LoRa / Nodes' : 'Online' }}</strong><p>{{ $entry->node_id }}</p></article>
                    <article class="detail-card"><span class="incident-code">Recorded time</span><strong>{{ $at?->format('M d, Y h:i:s A T') ?? 'Not recorded' }}</strong></article>
                    <article class="detail-card"><span class="incident-code">Status change</span><strong>{{ $before }} → {{ $after }}</strong></article>
                    <article class="detail-card"><span class="incident-code">Severity at this action</span><strong>{{ $entry->severity_before ?: 'Not recorded' }} → {{ $entry->severity_after ?: 'Not recorded' }}</strong></article>
                    @if ($entry->context['assigned_responder_name'] ?? null)<article class="detail-card"><span class="incident-code">Assigned admin</span><strong>{{ $entry->context['assigned_responder_name'] }}</strong></article>@endif
                    @if ($entry->context['review_method'] ?? null)<article class="detail-card"><span class="incident-code">Review</span><strong>{{ str_replace('_', ' ', $entry->context['review_method']) }}</strong></article>@endif
                    <article class="detail-card command-audit-modal-wide"><span class="incident-code">Recorded note</span><p>{{ $entry->notes ?: 'No note recorded for this action.' }}</p></article>
                    @if ($entry->imported)<p class="section-copy command-audit-modal-wide">Imported from the saved report log. Missing historical details are shown as Not recorded.</p>@endif
                </div>
                <div class="button-row command-audit-modal-actions">
                    @if ($entry->report)<a class="btn btn-primary" href="{{ route($entry->transmission_type === 'lora' ? 'lora-reports.index' : 'reports.index', ['review' => $entry->incident_report_id]).'#incident-report-'.$entry->incident_report_id }}">{{ $entry->transmission_type === 'lora' ? 'View LoRa report' : 'View online report' }}</a>@else<span class="section-copy">The report was deleted. This audit entry is retained.</span>@endif
                    <button type="button" class="btn btn-secondary" data-command-audit-modal-close>Close</button>
                </div>
            </template>
        </td>
    </tr>
@empty
    <tr><td colspan="6"><div class="command-empty-state"><strong>No matching admin actions.</strong><p>Admin responses, severity reviews, AI requests and deletions will appear here.</p></div></td></tr>
@endforelse
