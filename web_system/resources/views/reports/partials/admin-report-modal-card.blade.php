@if ($report->transmission_type === 'lora')
    @include('lora-reports.partials.review', ['report' => $report])
@else
    @include('reports.partials.online-review', ['report' => $report])
@endif
