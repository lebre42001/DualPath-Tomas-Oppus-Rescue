@php
    $statusLabels = ['received' => 'Pending', 'acknowledged' => 'Under review', 'dispatched' => 'Dispatched', 'responding' => 'Responded', 'resolved' => 'Resolved', 'rejected' => 'Cancelled', 'deleted' => 'Deleted'];
    $actionLabels = ['coordination' => 'Response / status', 'severity_review' => 'Severity review', 'ai_analysis' => 'AI analysis requested', 'deletion' => 'Deleted report'];
@endphp
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>AksyRes - Admin audit history</title>@vite('resources/js/civilian-report-print.js')</head>
<body class="audit-history-print" data-auto-print="{{ $autoPrint ? 'true' : 'false' }}">
    <form method="GET" action="{{ route('admin.report-audit.print') }}" class="print-toolbar print-options" data-report-print-options>
        @foreach ($filters as $name => $value)
            @if ($name !== 'audit_page' && filled($value))<input type="hidden" name="{{ $name }}" value="{{ $value }}">@endif
        @endforeach
        @if ($selectedIds !== [])<input type="hidden" name="audit_ids" value="{{ implode(',', $selectedIds) }}">@endif
        <div><strong>Choose print details</strong><p>{{ $entries->count() }} {{ $entries->count() === 1 ? 'action' : 'actions' }} · {{ $selectedIds !== [] ? 'Selected audit entries' : 'All matching filters, including every page' }}</p></div>
        <div class="print-options-choices"><label><input type="checkbox" name="include_notes" value="1" @checked($includeNotes)> Include recorded notes</label></div>
        <p>Notes may contain personal incident information. Include them only when the recipient needs them.</p>
        <div class="print-options-actions"><button type="submit">Update preview</button><button type="submit" name="print" value="1" @disabled($entries->isEmpty())>Print selected details</button></div>
    </form>
    <main>
        <header class="print-header"><p class="brand">AksyRes</p><h1>Admin audit history</h1>
            <p>{{ $entries->count() }} {{ $entries->count() === 1 ? 'action' : 'actions' }} · {{ $selectedIds !== [] ? 'Selected entries' : 'All matching filters' }}</p>
            <p class="print-meta">Generated {{ $printedAt }} · Philippines time (Asia/Manila)</p>
            @if (filled($filters['audit_from'] ?? null) || filled($filters['audit_to'] ?? null))<p class="print-meta">Audit dates: {{ $filters['audit_from'] ?? 'Any start date' }} to {{ $filters['audit_to'] ?? 'Any end date' }} (inclusive)</p>@endif
            @if (filled($filters['audit_search'] ?? null))<p class="print-meta">Search: {{ $filters['audit_search'] }}</p>@endif
            @if (filled($filters['audit_admin'] ?? null))<p class="print-meta">Admin: {{ $filters['audit_admin'] }}</p>@endif
            @if (filled($filters['audit_source'] ?? null))<p class="print-meta">Source: {{ $filters['audit_source'] === 'lora' ? 'LoRa' : 'Online' }}</p>@endif
            @if (filled($filters['audit_action'] ?? null))<p class="print-meta">Action: {{ $actionLabels[$filters['audit_action']] }}</p>@endif
        </header>
        @forelse ($entries as $entry)
            <article class="print-report" data-printed-audit="{{ $entry->id }}">
                <header><h2>{{ $entry->label }}</h2><p>{{ $entry->report_code }} · {{ $entry->incident_type }}</p></header>
                <dl class="print-facts">
                    <div><dt>Admin</dt><dd>{{ $entry->actor_name ?: 'Not recorded' }}@if ($entry->actor_role) · {{ $entry->actor_role }}@endif</dd></div>
                    <div><dt>Audit time</dt><dd>{{ $entry->occurred_at?->timezone('Asia/Manila')->format('M d, Y h:i:s A') ?? 'Not recorded' }}</dd></div>
                    <div><dt>Source</dt><dd>{{ $entry->transmission_type === 'lora' ? 'LoRa' : 'Online' }}@if ($entry->node_id) · {{ $entry->node_id }}@endif</dd></div>
                    <div><dt>Audit ID</dt><dd>#{{ $entry->id }}</dd></div>
                    <div><dt>Status change</dt><dd>{{ $statusLabels[$entry->status_before] ?? ($entry->status_before ?: 'Not recorded') }} → {{ $statusLabels[$entry->status_after] ?? ($entry->status_after ?: 'Not recorded') }}</dd></div>
                    <div><dt>Severity at this action</dt><dd>{{ $entry->severity_before ?: 'Not recorded' }} → {{ $entry->severity_after ?: 'Not recorded' }}</dd></div>
                </dl>
                @if ($includeNotes)<section><h3>Recorded note</h3><p class="print-copy">{{ $entry->notes ?: 'No note recorded for this action.' }}</p></section>@endif
                @unless ($entry->report)<p class="print-note">Original report deleted. This audit entry is retained.</p>@endunless
                @if ($entry->imported)<p class="print-note">Imported historical entry. Missing information is shown as Not recorded.</p>@endif
            </article>
        @empty
            <p>No audit actions match these filters.</p>
        @endforelse
        <p class="print-note">Internal audit information · For authorized administrative use.</p>
    </main>
</body></html>
