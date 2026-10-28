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
    <title>{{ $documentTitle }} | AksyRes</title>
    @if ($appCss)<link rel="stylesheet" href="{{ asset('build/'.$appCss) }}">@endif
</head>
<body class="public-page legal-page">
    <a class="legal-skip" href="#legal-content">Skip to document</a>
    @include('partials.public-header', ['activePublicPage' => 'legal'])
    <main class="legal-main" id="legal-content">
        <header class="legal-heading">
            <p class="legal-kicker">AksyRes &middot; Your information and rights</p>
            <h1>{{ $documentTitle }}</h1>
            <p>{{ $documentSummary }}</p>
            <p class="legal-version">Updated {{ config('legal.updated_at') }} &middot; Version {{ config('legal.version') }}</p>
        </header>
        @include('partials.legal-links')
        <article class="legal-document" aria-label="{{ $documentTitle }}">
            @include('legal.'.$document)
            <section id="contact">
                <h2>Contact the operator</h2>
                <p><strong>{{ config('legal.operator_name') }}</strong></p>
                @if (filter_var(config('legal.contact_email'), FILTER_VALIDATE_EMAIL))
                    <p>For privacy questions, access/correction requests, or account assistance, email <a href="mailto:{{ config('legal.contact_email') }}">{{ config('legal.contact_email') }}</a>. Include only the details needed to identify your request; do not send your password or unnecessary incident photos.</p>
                @else
                    <p>A dedicated privacy contact has not yet been published. Ask the organization or administrator that provided this service for its privacy representative before sharing sensitive information.</p>
                @endif
                @if (config('legal.contact_address'))<p>{{ config('legal.contact_address') }}</p>@endif
            </section>
        </article>
        <div class="legal-actions">
            <a href="{{ auth()->check() ? route('settings.index') : route('login') }}">{{ auth()->check() ? 'Back to Settings' : 'Back to Login' }}</a>
            <button type="button" onclick="window.print()">Print this document</button>
        </div>
    </main>
    @include('partials.public-footer')
</body>
</html>
