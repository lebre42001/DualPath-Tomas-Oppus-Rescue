@php
    $publicAdmin = $isResponderPortal ?? false;
    $activePublicPage = $activePublicPage ?? 'home';
@endphp
<header class="public-header">
    <div class="public-header-inner">
        <a href="{{ route('welcome') }}" class="public-brand" title="{{ $systemName }}" aria-label="Tomas Oppus Rescue home">
            <img src="{{ asset('icons/rescue-logo.svg') }}?v={{ $pwaAssetVersion }}" width="48" height="48" alt="Tomas Oppus Rescue app logo">
            <span><strong>Tomas Oppus Rescue</strong><small>Emergency reporting</small></span>
        </a>
        <nav aria-label="Public navigation">
            <a href="{{ $publicAdmin ? route('admin.login') : route('login') }}" @if ($activePublicPage === 'login') aria-current="page" @endif>{{ $publicAdmin ? 'Admin Login' : 'Login' }}</a>
            @unless ($publicAdmin)
                <a href="{{ route('register') }}" class="public-register-link" @if ($activePublicPage === 'register') aria-current="page" @endif>Register</a>
            @endunless
        </nav>
    </div>
</header>
