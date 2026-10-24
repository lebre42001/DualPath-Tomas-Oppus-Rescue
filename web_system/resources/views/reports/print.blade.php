@php
    $reports = $reports ?? collect([$report]);
    $includePhoto = $includePhoto ?? false;
    $includeLocation = $includeLocation ?? false;
    $includeContact = $includeContact ?? false;
    $autoPrint = ($autoPrint ?? false) && $reports->isNotEmpty();
    $printAction = $printAction ?? route('reports.print', $reports->first());
    $hasScenePhoto = $reports->contains(fn ($item) => $item->evidence_type === 'photo' && filled($item->evidence_path));
    $pageTitle = $reports->count() === 1 ? $reports->first()->report_code.' - Online report' : 'Online report records';
@endphp
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ $pageTitle }}</title>@vite('resources/js/civilian-report-print.js')</head>
<body class="online-report-print" data-auto-print="{{ $autoPrint ? 'true' : 'false' }}">
    <form method="GET" action="{{ $printAction }}" class="print-toolbar print-options" data-report-print-options>
        @if (isset($selectedReportId))<input type="hidden" name="report" value="{{ $selectedReportId }}">@endif
        <div><strong>Choose print details</strong><p>Include sensitive details only when the recipient needs them. Verification selfies are excluded.</p></div>
        <div class="print-options-choices">
            <label><input type="checkbox" name="include_location" value="1" @checked($includeLocation)> Exact location &amp; GPS</label>
            <label><input type="checkbox" name="include_contact" value="1" @checked($includeContact)> Reporter contact number</label>
            @if ($hasScenePhoto)<label><input type="checkbox" name="include_photo" value="1" @checked($includePhoto)> Scene photo</label>@endif
        </div>
        <div class="print-options-actions"><button type="submit" @disabled($reports->isEmpty())>Update preview</button><button type="submit" name="print" value="1" @disabled($reports->isEmpty())>Print selected details</button></div>
        <p data-print-feedback role="status" hidden></p>
    </form>
    <main>
        <header class="print-header"><p class="brand">ResQMesh</p><h1>Online report record</h1>
            @if (isset($accountName))<p>{{ $accountName }} · {{ $reports->count() }} {{ $reports->count() === 1 ? 'report' : 'reports' }}</p>@endif
            <p class="print-meta">Generated {{ $printedAt }} · Philippines time (Asia/Manila)</p>
        </header>
        @forelse ($reports as $report)
            @include('reports.partials.print-record', ['report' => $report])
        @empty
            <p>This civilian has not submitted any reports.</p>
        @endforelse
        <p class="print-note">Personal incident information · For authorized incident handling.</p>
    </main>
</body></html>
