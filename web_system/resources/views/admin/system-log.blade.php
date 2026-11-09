@extends('layouts.app')
@section('title', $personalLogs ? 'My Admin Logs' : 'Admin System Log')
@section('page_label', $personalLogs ? 'Admin Profile' : 'Officer accountability')
@section('page_heading', $personalLogs ? 'My Admin Logs' : 'System Log')
@section('page_subheading', $personalLogs ? 'Your duty shifts, sign-ins, report decisions and account activity.' : 'Officer duty, sign-ins, report decisions and administrative activity.')
@section('content')
    <section class="panel stack admin-system-log" data-admin-system-log data-daily-view="{{ $dailyView ? 'true' : 'false' }}" data-log-day="{{ now()->timezone('Asia/Manila')->toDateString() }}">
        @if ($personalLogs)
            <div class="panel-head"><div><h2>{{ auth()->user()->name }} <small>Officer #{{ auth()->id() }}</small></h2><p class="section-copy">Your recorded activity across Online reports, LoRa reports and administrative pages.</p></div><a class="btn btn-secondary" href="{{ route('profile.show') }}">Back to Profile</a></div>
        @endif
        <div class="panel-head"><div><h2>{{ $personalLogs ? 'My monitoring duty' : 'Monitoring duty' }}</h2><p class="section-copy">Use Start duty at the beginning of your shift and End duty when handing over. Signing out ends your declared duty. An open duty record shows the declaration and last server activity; it does not guarantee the officer is currently online.</p></div>
            <form method="POST" action="{{ route($logDutyRoute) }}">@csrf<input type="hidden" name="action" value="{{ $myShift ? 'end' : 'start' }}"><button type="submit" class="btn {{ $myShift ? 'btn-secondary' : 'btn-primary' }}">{{ $myShift ? 'End duty' : 'Start duty' }}</button></form>
        </div>
        <div class="admin-duty-list">
            @forelse ($shifts as $shift)
                <article class="detail-card"><strong>{{ $shift->actor_name }} <small>Officer #{{ $shift->actor_reference }}</small></strong><p>Declared on duty: {{ $shift->started_at->timezone('Asia/Manila')->format('M d, Y h:i A') }}</p><p class="section-copy">Last server activity: {{ $shift->last_activity_at->timezone('Asia/Manila')->format('M d, Y h:i:s A') }}@if ($shift->last_activity_at->lt(now()->subMinutes(10))) · No recent activity; confirm availability.@endif</p></article>
            @empty<p class="section-copy">{{ $personalLogs ? 'You have no open declared duty shift. Use Start duty at the beginning of your shift.' : 'No officer has an open declared duty shift. Use Start duty to record yours.' }}</p>@endforelse
        </div>
        <div class="panel-head"><div><h2>Activity history</h2><p class="section-copy">Default view: today's 24-hour calendar day, 12:00 AM–11:59 PM in Philippines time (Asia/Manila). The view starts a new day at midnight; saved entries are retained. Use dates to find previous days. Historical imports show only recorded information.</p></div></div>
        <form method="GET" action="{{ route($logRoute) }}" class="admin-log-filters" data-admin-log-filters>
            <label>Search<input class="input" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="120" placeholder="{{ $personalLogs ? 'Report code, activity or details' : 'Officer, report code or activity' }}"></label>
            <label>From date<input class="input" type="date" name="from" value="{{ $filters['from'] ?? '' }}"></label>
            <label>To date<input class="input" type="date" name="to" value="{{ $filters['to'] ?? '' }}"></label>
            @unless ($personalLogs)
                <label>Officer<select class="input" name="officer"><option value="">All officers</option>@foreach ($officers as $officer)<option value="{{ $officer->actor_reference }}" @selected((string)($filters['officer'] ?? '') === (string)$officer->actor_reference)>{{ $officer->actor_name }} · #{{ $officer->actor_reference }}</option>@endforeach</select></label>
            @endunless
            <label>Activity<select class="input" name="category"><option value="">All activities</option>@foreach (['account' => 'Sign-in / sign-out', 'duty' => 'Duty shifts', 'report' => 'Report actions', 'navigation' => 'Pages opened', 'system' => 'Account / settings / print'] as $value => $label)<option value="{{ $value }}" @selected(($filters['category'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></label>
            <label>Source<select class="input" name="source"><option value="">All sources</option>@foreach (['online' => 'Online', 'lora' => 'LoRa', 'system' => 'System'] as $value => $label)<option value="{{ $value }}" @selected(($filters['source'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></label>
            <div class="button-row"><button class="btn btn-primary" type="submit">Search logs</button><a class="btn btn-secondary" href="{{ route($logRoute) }}">Today / Reset</a><a class="btn btn-secondary" href="{{ route($logRoute, ['from' => '', 'to' => '']) }}">All dates</a></div>
        </form>
        <div class="button-row"><a class="btn btn-secondary" href="{{ route($logRoute, collect($filters)->except($dailyView ? ['from', 'to', 'print'] : ['print'])->all()) }}">Refresh logs</a><a class="btn btn-primary" href="{{ route($logPrintRoute, collect($filters)->except(['page', 'print'])->all()) }}" target="_blank" rel="noopener">Print / Save as PDF</a>@unless ($personalLogs)<a class="btn btn-secondary" href="{{ route('admin.report-audit') }}">Report audit details</a>@endunless</div>
        <p class="section-copy" data-admin-log-status role="status">{{ $events->total() }} matching entries. Refreshes every 30 seconds while visible; editing filters pauses refresh. Print includes every matching page, up to 2,000 entries per copy. For PDF, choose Save as PDF in your browser's print dialog.</p>
        <div class="admin-log-table-wrap" tabindex="0" role="region" aria-label="{{ $personalLogs ? 'Your admin activity table, scroll horizontally on small screens' : 'Admin activity table, scroll horizontally on small screens' }}">
            <table class="admin-log-table"><thead><tr><th>Date &amp; time (PH)</th><th>Officer</th><th>Activity</th><th>Report / target</th><th>Details</th></tr></thead><tbody>
                @forelse ($events as $event)
                    <tr data-admin-log-entry="{{ $event->id }}"><td>{{ $event->occurred_at?->timezone('Asia/Manila')->format('M d, Y h:i:s A') ?? 'Time not recorded' }}<small>#{{ $event->id }}@if ($event->imported) · Historical import @endif</small></td><td><strong>{{ $event->actor_name }}</strong><small>{{ $event->actor_reference ? 'Officer #'.$event->actor_reference : 'Identity not recorded' }}</small></td><td><strong>{{ $event->label }}</strong><small>{{ ucfirst($event->category) }}</small></td><td>{{ $event->target_label ?: '—' }}<small>{{ $event->source === 'lora' ? 'LoRa' : ucfirst($event->source) }}</small></td><td>{{ $event->summary }}@if ($event->route)<small>{{ $event->route }}</small>@endif</td></tr>
                @empty<tr><td colspan="5">No activity matches these filters. Earlier records remain available using another date range.</td></tr>@endforelse
            </tbody></table>
        </div>
        <div data-admin-log-pagination>{{ $events->links() }}</div>
    </section>
@endsection
