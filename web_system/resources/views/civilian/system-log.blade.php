@extends('layouts.app')

@section('title', 'System Log')
@section('page_label', 'System Log')
@section('page_heading', 'My System Log')
@section('page_subheading', 'Your account activity, report actions, and app version history. Times are Philippine time (UTC+8).')

@section('content')
    <section class="panel stack civilian-system-log" data-civilian-system-log>
        <div class="civilian-version-card">
            <h2>App version on this page</h2>
            <dl><dt>Running build</dt><dd data-app-running>{{ app(\App\Support\SystemVersion::class)->current() }}</dd><dt>Previous build on this device</dt><dd data-app-previous>Not recorded</dd></dl>
            <p class="section-copy">Open the floating Updates badge to check the latest server build or apply an update. Build IDs identify app versions; device-reported app events are labelled separately from server-recorded report actions.</p>
        </div>
        <form method="GET" action="{{ route('civilian.system-log') }}" class="civilian-log-filters">
            <div class="field"><label for="log-search">Search</label><input class="input" id="log-search" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="120" placeholder="Report code, action, or app build"></div>
            <div class="field"><label for="log-from">From date</label><input class="input" id="log-from" type="date" name="from" value="{{ $filters['from'] ?? '' }}"></div>
            <div class="field"><label for="log-to">To date</label><input class="input" id="log-to" type="date" name="to" value="{{ $filters['to'] ?? '' }}"></div>
            <div class="field"><label for="log-type">Activity</label><select class="input" id="log-type" name="type"><option value="">All activity</option>@foreach(['account' => 'Sign in / sign out', 'report' => 'Reports and admin actions', 'app' => 'App updates / versions'] as $value => $label)<option value="{{ $value }}" @selected(($filters['type'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></div>
            <div class="button-row"><button class="btn btn-primary" type="submit">Search logs</button><a class="btn btn-secondary" href="{{ route('civilian.system-log') }}">Reset</a></div>
        </form>
        <p class="section-copy">Recorded report history is included where timestamps and ownership are known. Sign-in/sign-out and device update history begin with this feature. Closing the app, a shutdown, or loss of internet is not a recorded sign-out.</p>
        <div class="button-row"><button type="button" class="btn btn-secondary" data-system-log-copy-button @disabled($events->isEmpty())>Copy text</button><span class="section-copy">Copies the {{ $events->count() }} entries on this filtered page.</span></div>
        <p data-system-log-copy-status role="status" aria-live="polite"></p>
        @php
            $copyText = "AksyRes — My System Log (Philippine time, UTC+8)\nRunning build: ".app(\App\Support\SystemVersion::class)->current()."\n";
        @endphp
        <ol class="civilian-log-list">
            @forelse($events as $event)
                @php
                    $time = $event->occurred_at->timezone('Asia/Manila')->format('Y-m-d h:i:s A');
                    $copyText .= "\n".$time.' — '.$event->title."\n".$event->message.($event->app_version ? "\nDevice-reported build: ".$event->app_version : '')."\n";
                @endphp
                <li class="civilian-log-entry"><time datetime="{{ $event->occurred_at->toIso8601String() }}">{{ $time }}</time><strong>{{ $event->title }}</strong><p>{{ $event->message }}</p>
                    @if($event->report_code)<span class="tag neutral">{{ $event->report_code }}</span>@endif
                    @if($event->app_version)<p class="section-copy">Device-reported build: <code>{{ $event->app_version }}</code></p>@endif
                </li>
            @empty
                <li class="data-empty">No activity matches these filters.</li>
            @endforelse
        </ol>
        <textarea class="textarea" data-system-log-copy readonly hidden aria-label="System log text to copy">{{ $copyText }}</textarea>
        {{ $events->links() }}
    </section>
@endsection
