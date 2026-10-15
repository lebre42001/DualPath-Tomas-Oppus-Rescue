@php($pwaAssetVersion = app(\App\Support\SystemVersion::class)->current())
<meta name="application-name" content="Bontoc Rescue Sender">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="Rescue Sender">
<meta name="mobile-web-app-capable" content="yes">
<meta name="theme-color" content="#c91c21">
<meta name="format-detection" content="telephone=no">
<link rel="manifest" href="{{ asset('manifest.webmanifest') }}?v={{ $pwaAssetVersion }}">
<link rel="apple-touch-icon" sizes="180x180" href="{{ asset('icons/icon-180.png') }}?v={{ $pwaAssetVersion }}">
<link rel="icon" type="image/png" sizes="64x64" href="{{ asset('icons/icon-64.png') }}?v={{ $pwaAssetVersion }}">
