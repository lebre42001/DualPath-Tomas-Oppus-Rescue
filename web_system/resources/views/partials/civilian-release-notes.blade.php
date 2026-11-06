@forelse(config('civilian_releases', []) as $release)
    @if($loop->first)
        <article class="civilian-release-note" data-release-id="{{ $release['id'] }}">
            <strong>{{ $release['title'] }}</strong>
            <p class="civilian-release-meta">Release {{ $release['id'] }} · <time datetime="{{ $release['date'] }}">{{ \Illuminate\Support\Carbon::parse($release['date'])->format('M d, Y') }}</time></p>
            <ul>@foreach($release['changes'] as $change)<li>{{ $change }}</li>@endforeach</ul>
        </article>
    @else
        <details class="civilian-release-note" data-release-id="{{ $release['id'] }}">
            <summary>{{ $release['title'] }} <span class="civilian-release-meta">· {{ $release['id'] }} · <time datetime="{{ $release['date'] }}">{{ \Illuminate\Support\Carbon::parse($release['date'])->format('M d, Y') }}</time></span></summary>
            <ul>@foreach($release['changes'] as $change)<li>{{ $change }}</li>@endforeach</ul>
        </details>
    @endif
@empty
    <p>No release notes have been published yet.</p>
@endforelse
