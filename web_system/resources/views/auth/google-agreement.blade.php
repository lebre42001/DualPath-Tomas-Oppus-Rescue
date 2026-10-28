@php
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
    <title>Continue with Google | AksyRes</title>
    @if ($appCss)<link rel="stylesheet" href="{{ asset('build/'.$appCss) }}">@endif
</head>
<body class="public-page legal-page">
    @include('partials.public-header', ['activePublicPage' => 'login'])
    <main class="legal-main legal-signup">
        <header class="legal-heading">
            <p class="legal-kicker">Civilian account access</p>
            <h1>Before Google sign-in</h1>
            <p>Google shares your verified email, name, account identifier, and available profile image. If this is your first sign-in, AksyRes creates a civilian account.</p>
        </header>
        @if (session('status'))<p class="legal-notice" role="status">{{ session('status') }}</p>@endif
        <form action="{{ route('auth.google.authorize') }}" method="POST" class="legal-document">
            @csrf
            @include('partials.legal-acknowledgment')
            <button type="submit" class="btn btn-primary">Continue with Google</button>
            <a class="public-text-link" href="{{ route('login') }}">Back to Login</a>
        </form>
    </main>
    @include('partials.public-footer')
</body>
</html>
