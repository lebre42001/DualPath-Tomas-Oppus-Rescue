@extends('layouts.app')

@section('title', 'LoRa Reports')
@section('page_label', 'Field reports')
@section('page_heading', 'LoRa Reports')
@section('page_subheading', 'All reports received from the node network. Select a report to review and acknowledge it.')

@section('content')
    @php
        $statusLabels = ['received' => 'Pending review', 'acknowledged' => 'Under review', 'dispatched' => 'Dispatched', 'responding' => 'Responding', 'resolved' => 'Done', 'rejected' => 'Cancelled'];
        $statusTones = ['received' => 'amber', 'acknowledged' => 'blue', 'dispatched' => 'blue', 'responding' => 'blue', 'resolved' => 'green', 'rejected' => 'red'];
    @endphp
    <section class="panel lora-report-history">
        <div class="panel-head">
            <div class="panel-heading">
                <p class="panel-kicker">Saved LoRa reports</p>
                <h2 class="panel-title">Node report history</h2>
                <p class="section-copy">{{ $reportCounts['all'] }} saved reports. Open a row for the full description and admin actions.</p>
            </div>
            <div class="action-row">
                <a class="btn btn-secondary" href="{{ route('monitoring') }}">Live Monitoring</a>
                <a class="btn btn-secondary" href="{{ request()->fullUrl() }}">Refresh</a>
            </div>
        </div>

        <nav class="lora-report-tabs" aria-label="Report status toggle">
            @foreach (['all' => 'All reports', 'active' => 'Active', 'closed' => 'Closed'] as $scope => $label)
                <a href="{{ route('lora-reports.index', array_merge(request()->except('page', 'review'), ['scope' => $scope])) }}"
                    class="{{ $filters['scope'] === $scope ? 'is-active' : '' }}"
                    @if ($filters['scope'] === $scope) aria-current="page" @endif>{{ $label }} <span>{{ $reportCounts[$scope] }}</span></a>
            @endforeach
        </nav>

        <form method="GET" action="{{ route('lora-reports.index') }}" class="lora-report-filters">
            <input type="hidden" name="scope" value="{{ $filters['scope'] }}">
            <label>Search reports<input type="search" name="search" value="{{ $filters['search'] }}" placeholder="Report ID, message ID, or sender" maxlength="100"></label>
            <label>Node<select name="node"><option value="">All nodes</option>@foreach (range(1, 10) as $number)<option value="NODE_{{ $number }}" @selected($filters['node'] === 'NODE_'.$number)>Node {{ $number }}</option>@endforeach</select></label>
            <label>Order<select name="sort"><option value="newest" @selected($filters['sort'] === 'newest')>Newest first</option><option value="oldest" @selected($filters['sort'] === 'oldest')>Oldest first</option></select></label>
            <label>Rows per page<select name="per_page">@foreach ([5, 10, 25] as $size)<option value="{{ $size }}" @selected((int) $filters['per_page'] === $size)>{{ $size }}</option>@endforeach</select></label>
            <button type="submit" class="btn btn-primary">Apply</button>
            <a href="{{ route('lora-reports.index') }}" class="btn btn-secondary">Reset</a>
        </form>

        <div class="lora-report-list">
            <div class="lora-report-columns" aria-hidden="true"><span>Report / incident</span><span>Node</span><span>Sender</span><span>Received</span><span>Status</span><span></span></div>
            @forelse ($reports as $report)
                <article id="incident-report-{{ $report->id }}" class="lora-report-row" data-report-modal-row data-report-id="{{ $report->id }}"
                    data-report-title="{{ $report->report_code }} — {{ $report->incident_type }}" @if ($reviewReportId === $report->id) data-report-open-on-load @endif>
                    <button type="button" class="lora-report-row-button" data-report-modal-trigger aria-label="Review {{ $report->report_code }} from {{ $report->lora_sender_id }}">
                        <span class="lora-report-row-title"><strong>{{ $report->report_code }}</strong><span>{{ $report->incident_type }}</span></span>
                        <span><span class="lora-report-mobile-label">Node</span>{{ $report->lora_sender_id ?: 'Unknown' }}</span>
                        <span><span class="lora-report-mobile-label">Sender</span>{{ $report->reporter_name ?: 'Unknown sender' }}</span>
                        <span><span class="lora-report-mobile-label">Received</span><time datetime="{{ $report->created_at?->toIso8601String() }}">{{ $report->created_at?->timezone(config('app.timezone'))->format('M d, Y H:i') }}</time></span>
                        <span class="tag {{ $statusTones[$report->status] ?? 'neutral' }}">{{ $statusLabels[$report->status] ?? ucfirst($report->status) }}</span>
                        <span class="lora-report-open-label">Review &rarr;</span>
                    </button>
                    @include('lora-reports.partials.review', ['report' => $report])
                </article>
            @empty
                <div class="data-empty">No LoRa reports match these filters.</div>
            @endforelse
        </div>

        <nav class="report-pagination lora-report-pagination" aria-label="LoRa report pagination">
            <span class="report-pagination-meta">{{ $reports->firstItem() ?? 0 }}–{{ $reports->lastItem() ?? 0 }} of {{ $reports->total() }} reports</span>
            <div class="action-row">
                @if ($reports->onFirstPage())<span class="btn btn-secondary is-disabled" aria-disabled="true">&larr; Previous</span>@else<a class="btn btn-secondary" href="{{ $reports->previousPageUrl() }}">&larr; Previous</a>@endif
                <form method="GET" action="{{ route('lora-reports.index') }}" class="lora-report-page-jump">
                    @foreach (request()->except('page', 'review') as $key => $value)
                        @if (is_scalar($value))<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endif
                    @endforeach
                    <label for="lora-report-page">Page</label>
                    <select id="lora-report-page" name="page">@for ($page = 1; $page <= $reports->lastPage(); $page++)<option value="{{ $page }}" @selected($reports->currentPage() === $page)>{{ $page }}</option>@endfor</select>
                    <span>of {{ $reports->lastPage() }}</span><button type="submit" class="btn btn-secondary">Go</button>
                </form>
                @if ($reports->hasMorePages())<a class="btn btn-secondary" href="{{ $reports->nextPageUrl() }}">Next &rarr;</a>@else<span class="btn btn-secondary is-disabled" aria-disabled="true">Next &rarr;</span>@endif
            </div>
        </nav>
    </section>
@endsection
