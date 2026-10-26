@php
    $shortBrand = 'AksyRes';
    $systemName = 'AI-Powered LoRa Emergency Response and Severity Detection System with Intelligent Location Monitoring';
    $pwaAssetVersion = app(\App\Support\SystemVersion::class)->current();
    $manifestPath = public_path('build/manifest.json');
    $manifest = file_exists($manifestPath) ? json_decode(file_get_contents($manifestPath), true) : [];
    $appCss = $manifest['resources/css/app.css']['file'] ?? null;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @include('partials.pwa-head')
    <title>{{ $shortBrand }} | Emergency Response</title>
    @if ($appCss)<link rel="stylesheet" href="{{ asset('build/'.$appCss) }}">@endif
</head>
<body class="public-page public-landing">
    @include('partials.public-header', ['activePublicPage' => 'home'])
    <main class="public-home" id="main-content">
        <section class="public-home-hero">
            <img class="public-home-logo" src="{{ asset('icons/aksyres-logo.png') }}?v={{ $pwaAssetVersion }}" width="144" height="144" alt="AksyRes official logo">
            <h1>Report an emergency. Track the response.</h1>
            <p class="public-home-intro">Send a scene photo, verification selfie, location, and a short description. Keep your reference code to follow your report.</p>
            <div class="public-home-actions">
                <a href="{{ route('register') }}" class="public-button public-button-primary">Get Started</a>
                <a href="{{ route('login') }}" class="public-button">Login</a>
                <a href="{{ route('app.android.download') }}" class="public-button public-install-button" data-android-app-download aria-label="Download AksyRes Android APK">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 3v12m-5-5 5 5 5-5M5 16v5h14v-5"/></svg>
                    <span data-app-download-label>Download App</span>
                    <span class="public-download-platform" data-app-download-platform>Android APK</span>
                </a>
            </div>
        </section>
        <section class="public-how-it-works" aria-labelledby="how-it-works-title">
            <h2 id="how-it-works-title">How it works</h2>
            <ol>
                <li>Send emergency details</li>
                <li>Keep your reference code</li>
                <li>Follow response updates</li>
            </ol>
        </section>
    </main>
    @include('partials.public-footer')
    <script src="{{ asset('pwa-helper.js') }}?v={{ $pwaAssetVersion }}"></script>
</body>
</html>
