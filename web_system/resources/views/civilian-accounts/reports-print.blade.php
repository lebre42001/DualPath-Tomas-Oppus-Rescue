<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $civilianAccount->name }} - Civilian reports</title>
    @vite('resources/js/civilian-report-print.js')
</head>
<body>
    <div class="print-toolbar">
        <p>Print these records or choose <strong>Save as PDF</strong> in the print dialog.</p>
        <button type="button" data-print-reports @disabled($reports->isEmpty())>Print {{ $reports->count() === 1 ? 'report' : 'all reports' }}</button>
    </div>
    <main>
        <header class="print-header">
            <p class="brand">Tomas Oppus Rescue</p>
            <h1>Civilian report record</h1>
            <p><strong>{{ $civilianAccount->name }}</strong> &middot; {{ $civilianAccount->email }}</p>
            <p class="print-meta">{{ $reports->count() }} {{ $reports->count() === 1 ? 'report' : 'reports' }} &middot; Generated {{ $printedAt }} &middot; Philippines time (Asia/Manila)</p>
        </header>
        @forelse ($reports as $report)
            <article class="print-report">
                <header>
                    <h2>{{ $report['code'] }}</h2>
                    <p>{{ $report['type'] }} &middot; {{ $report['submittedAt'] }}</p>
                </header>
                <dl class="print-facts">
                    <div><dt>Status</dt><dd>{{ $report['status'] }}</dd></div>
                    <div><dt>Severity</dt><dd>{{ $report['severity'] }}@if ($report['reviewRequired']) <span class="print-note">&mdash; responder review required</span>@endif</dd></div>
                    <div class="wide"><dt>Location</dt><dd>{{ $report['location'] }}@if ($report['coordinates']) <span class="print-note">(GPS: {{ $report['coordinates'] }})</span>@endif</dd></div>
                </dl>
                <section><h3>Description</h3><p class="print-copy">{{ $report['description'] }}</p></section>
                @if ($report['reportedFatality'])
                    <p class="print-note"><strong>Reported fatality:</strong> Reported by sender; requires verification.</p>
                @endif
                @if ($report['responder'])
                    <p><strong>Assigned responder:</strong> {{ $report['responder'] }}</p>
                @endif
                @if ($report['responseNotes'])
                    <section><h3>Response notes</h3><p class="print-copy">{{ $report['responseNotes'] }}</p></section>
                @endif
                @if ($report['resolvedAt'])
                    <p><strong>Resolved:</strong> {{ $report['resolvedAt'] }}</p>
                @endif
            </article>
        @empty
            <p>This civilian has not submitted any reports.</p>
        @endforelse
        <footer class="print-footer">Account report history &middot; Tomas Oppus Rescue</footer>
    </main>
</body>
</html>
