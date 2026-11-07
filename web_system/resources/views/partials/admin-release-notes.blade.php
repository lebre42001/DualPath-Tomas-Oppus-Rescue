@foreach(config('admin_releases', []) as $release)
    <article class="civilian-release-note" data-release-id="{{ $release['id'] }}">
        <strong>{{ $release['title'] }}</strong>
        <p class="civilian-release-meta">Release {{ $release['id'] }} · {{ $release['date'] }}</p>
        <ul>@foreach($release['changes'] as $change)<li>{{ $change }}</li>@endforeach</ul>
    </article>
@endforeach
