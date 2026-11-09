<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>AksyRes - {{ $personalLogs ? 'My Admin Logs' : 'Admin System Log' }}</title>@vite('resources/js/civilian-report-print.js')</head>
<body class="admin-system-log-print" data-auto-print="{{ $autoPrint ? 'true' : 'false' }}">
    <form method="GET" action="{{ route($logPrintRoute) }}" class="print-toolbar print-options" data-report-print-options>
        @foreach ($filters as $name => $value)
            @if (! in_array($name, ['page', 'print']))<input type="hidden" name="{{ $name }}" value="{{ $value }}">@endif
        @endforeach
        <div><strong>{{ $personalLogs ? 'My Admin Logs' : 'Admin System Log' }}</strong><p>{{ $entries->count() }} matching entries across all pages. Use your browser's Save as PDF destination to save a PDF copy.</p></div>
        <div class="print-options-actions"><button type="submit">Refresh preview</button><button type="submit" name="print" value="1" @disabled($entries->isEmpty())>Print / Save as PDF</button></div>
    </form>
    <main>
        <header class="print-header"><p class="brand">AksyRes</p><h1>{{ $personalLogs ? 'My Admin Logs' : 'Admin System Log' }}</h1>@if ($personalLogs)<p>Officer: {{ $officer->name }} · #{{ $officer->id }}</p>@endif<p class="print-meta">Generated {{ $printedAt }} · Philippines time (Asia/Manila)</p><p>Dates: {{ filled($filters['from'] ?? null) ? $filters['from'] : 'Any start' }} to {{ filled($filters['to'] ?? null) ? $filters['to'] : 'Any end' }} (inclusive)</p>
            @foreach (['q' => 'Search', 'officer' => 'Officer ID', 'category' => 'Activity', 'source' => 'Source'] as $key => $label)
                @if (filled($filters[$key] ?? null))<p class="print-meta">{{ $label }}: {{ $filters[$key] }}</p>@endif
            @endforeach
        </header>
        @forelse ($entries as $entry)
            <article class="print-report" data-printed-log="{{ $entry->id }}"><header><h2>{{ $entry->label }}</h2><p>{{ $entry->target_label ?: 'System activity' }}</p></header>
                <dl class="print-facts"><div><dt>Officer</dt><dd>{{ $entry->actor_name }}@if ($entry->actor_reference) · #{{ $entry->actor_reference }}@endif</dd></div><div><dt>Date &amp; time (PH)</dt><dd>{{ $entry->occurred_at?->timezone('Asia/Manila')->format('M d, Y h:i:s A') ?? 'Not recorded' }}</dd></div><div><dt>Source / activity</dt><dd>{{ $entry->source === 'lora' ? 'LoRa' : ucfirst($entry->source) }} · {{ ucfirst($entry->category) }}</dd></div><div><dt>Log ID</dt><dd>#{{ $entry->id }}@if ($entry->imported) · Historical import @endif</dd></div></dl>
                <p class="print-copy">{{ $entry->summary }}</p>@if ($entry->route)<p class="print-meta">{{ $entry->route }}</p>@endif
            </article>
        @empty<p>No entries match these filters.</p>@endforelse
        <p class="print-note">Internal officer activity record · For authorized administrative use. Entries remain stored when the daily view changes.</p>
    </main>
</body></html>
