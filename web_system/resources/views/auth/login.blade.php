@php
    $shortBrand = 'Bontoc Rescue';
    $systemName = 'AI-Powered LoRa Emergency Response and Severity Detection System with Intelligent Location Monitoring';
    $isResponderPortal = in_array(($portal ?? 'civilian'), ['admin', 'responder'], true);
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
    <title>{{ $shortBrand }} | {{ $isResponderPortal ? 'Admin Receiver Login' : 'Civilian Sender Login' }}</title>
    @if ($appCss)
        <link rel="stylesheet" href="{{ asset('build/'.$appCss) }}">
    @endif
</head>
<body class="min-h-screen overflow-x-hidden bg-surface text-on-surface antialiased">
    <header class="border-b border-primary-container/40 text-white shadow-sm" style="background-color: #af101a;">
        <div class="mx-auto flex max-w-7xl flex-col gap-4 px-5 py-4 sm:px-8 lg:flex-row lg:items-center lg:justify-between">
            <a href="{{ route('welcome') }}" class="flex w-full min-w-0 max-w-full items-center gap-3 lg:w-auto">
                <img src="{{ asset('icons/icon-192.png') }}" alt="{{ $shortBrand }} app icon" class="h-11 w-11 shrink-0 rounded-md bg-white p-1">
                <span class="min-w-0 flex-1">
                    <span class="inline-flex rounded-sm bg-white px-2 py-1 text-base font-black uppercase text-[#af101a]" style="color: #af101a;">{{ $shortBrand }}</span>
                    <span class="mt-1 block max-w-[250px] break-words text-[11px] font-semibold uppercase leading-relaxed text-white/90 sm:max-w-2xl sm:text-xs">{{ $systemName }}</span>
                </span>
            </a>

            <nav class="flex flex-wrap items-center gap-2 text-sm font-bold">
                <a href="{{ route('welcome') }}" class="rounded-md px-4 py-2 text-white transition hover:bg-white/15">Home</a>
                <a href="{{ $isResponderPortal ? route('admin.login') : route('login') }}" class="rounded-md bg-white px-4 py-2 text-primary">{{ $isResponderPortal ? 'Admin Login' : 'Login' }}</a>
                @unless ($isResponderPortal)
                    <a href="{{ route('register') }}" class="rounded-md px-4 py-2 text-white transition hover:bg-white/15">Register</a>
                @endunless
            </nav>
        </div>
    </header>

    <main class="mx-auto grid min-h-[calc(100vh-89px)] max-w-7xl items-center gap-8 px-5 py-8 sm:px-8 lg:grid-cols-[minmax(0,0.88fr)_minmax(380px,1fr)] lg:py-12">
        <section class="order-2 lg:order-1">
            <p class="inline-flex rounded-md border border-secondary/15 bg-secondary-fixed px-3 py-2 text-xs font-black uppercase text-secondary">
                {{ $isResponderPortal ? 'Admin receiver access' : 'Civilian sender access' }}
            </p>
            <h1 class="mt-5 break-words text-3xl font-black leading-tight text-on-surface sm:text-5xl">
                {{ $isResponderPortal ? 'Open the admin receiver portal.' : 'Report an emergency and check its status.' }}
            </h1>
            <p class="mt-5 max-w-xl text-base font-semibold leading-relaxed text-on-surface-variant">
                {{ $isResponderPortal
                    ? 'Admins receive civilian reports, review AI-assisted severity, use incident coordinates and maps, and update response status.'
                    : 'The civilian app is focused on online emergency reporting, your own report history, and clear response-status updates.' }}
            </p>

            <div class="mt-8 rounded-md border border-outline-variant/50 bg-white p-5">
                <div class="flex items-center gap-4 border-b border-outline-variant/40 pb-4">
                    <img src="{{ asset('icons/icon-512.png') }}" alt="{{ $shortBrand }} app icon" class="h-16 w-16 rounded-md bg-primary-fixed p-2">
                    <div class="min-w-0">
                        <p class="text-xs font-black uppercase text-outline">{{ $isResponderPortal ? 'Receiver portal' : 'Sender app' }}</p>
                        <h2 class="mt-1 break-words text-xl font-black">{{ $isResponderPortal ? 'Live reports, AI review, and map context' : 'Fast reporting and simple status tracking' }}</h2>
                    </div>
                </div>
                <div class="grid gap-4 pt-4 sm:grid-cols-3">
                    <div>
                        <p class="text-xs font-black uppercase text-outline">Reports</p>
                        <p class="mt-1 font-black">{{ $isResponderPortal ? 'Live queue' : 'My reports' }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-black uppercase text-outline">{{ $isResponderPortal ? 'AI' : 'Progress' }}</p>
                        <p class="mt-1 font-black">{{ $isResponderPortal ? 'Severity review' : 'Status updates' }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-black uppercase text-outline">{{ $isResponderPortal ? 'Location' : 'App' }}</p>
                        <p class="mt-1 font-black">{{ $isResponderPortal ? 'Coordinates' : 'Installable' }}</p>
                    </div>
                </div>
            </div>
        </section>

        <section class="order-1 rounded-md border border-outline-variant/50 bg-white p-5 shadow-xl shadow-slate-900/5 sm:p-8 lg:order-2">
            <div class="mb-6">
                <p class="text-xs font-black uppercase text-primary">{{ $isResponderPortal ? 'Admin Receiver Login' : 'Civilian Sender Login' }}</p>
                <h2 class="mt-2 break-words text-2xl font-black text-on-surface sm:text-3xl">{{ $isResponderPortal ? 'Access the server workspace' : 'Access your emergency account' }}</h2>
                <p class="mt-2 text-sm font-semibold leading-relaxed text-on-surface-variant">
                    {{ $isResponderPortal ? 'Only pre-authorized admin accounts can continue here.' : 'Use your civilian account to send reports and check their status.' }}
                </p>
            </div>

            @if ($errors->any())
                <div class="mb-5 rounded-md bg-error-container px-4 py-3 text-sm font-semibold text-on-error-container">
                    {{ $errors->first() }}
                </div>
            @endif

            @if (session('status'))
                <div class="mb-5 rounded-md bg-surface-container-high px-4 py-3 text-sm font-semibold text-secondary">
                    {{ session('status') }}
                </div>
            @endif

            <form action="{{ $isResponderPortal ? route('admin.login.store') : route('login') }}" method="POST" class="grid gap-5">
                @csrf

                <div>
                    <label for="identity" class="text-sm font-black text-on-surface">Email address</label>
                    <input
                        id="identity"
                        name="identity"
                        value="{{ old('identity') }}"
                        type="email"
                        required
                        autofocus
                        autocomplete="username"
                        placeholder="name@example.com"
                        class="mt-2 min-h-[52px] w-full rounded-md border border-outline-variant bg-surface-container-lowest px-4 py-3 text-base font-semibold text-on-surface outline-none transition focus:border-primary focus:ring-2 focus:ring-primary/15"
                    >
                    @error('identity')
                        <p class="mt-2 text-sm font-semibold text-error">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <div class="flex items-center justify-between gap-3">
                        <label for="password" class="text-sm font-black text-on-surface">Password</label>
                        <span class="text-xs font-semibold text-on-surface-variant">Minimum of 6 characters.</span>
                    </div>
                    <div class="relative mt-2">
                        <input
                            id="password"
                            name="password"
                            type="password"
                            required
                            minlength="6"
                            autocomplete="current-password"
                            placeholder="Enter your password"
                            data-auth-password-input
                            class="min-h-[52px] w-full rounded-md border border-outline-variant bg-surface-container-lowest px-4 py-3 pr-20 text-base font-semibold text-on-surface outline-none transition focus:border-primary focus:ring-2 focus:ring-primary/15"
                        >
                        <button type="button" data-auth-toggle-target="password" aria-pressed="false" class="absolute right-3 top-1/2 -translate-y-1/2 rounded-md px-3 py-2 text-xs font-black text-primary transition hover:bg-primary-fixed">
                            Show
                        </button>
                    </div>
                    @error('password')
                        <p class="mt-2 text-sm font-semibold text-error">{{ $message }}</p>
                    @enderror
                </div>

                <label class="flex items-center gap-3 rounded-md border border-outline-variant/50 bg-surface-container-lowest px-4 py-3 text-sm font-bold text-on-surface-variant">
                    <input id="remember" name="remember" value="1" type="checkbox" @checked(old('remember')) class="h-4 w-4 rounded border-outline text-primary focus:ring-primary">
                    <span>Keep me signed in on this device</span>
                </label>

                <button type="submit" class="inline-flex min-h-[52px] items-center justify-center rounded-md bg-primary px-6 py-3 text-sm font-black text-on-primary shadow-lg shadow-primary/20 transition hover:bg-primary-container">
                    {{ $isResponderPortal ? 'Open Admin Receiver Portal' : 'Open Civilian Sender App' }}
                </button>
            </form>

            @if ($isResponderPortal)
                <div class="mt-6">
                    <a href="{{ route('login') }}" class="inline-flex min-h-[48px] w-full items-center justify-center rounded-md border border-outline-variant bg-white px-5 py-3 text-sm font-black text-primary transition hover:bg-primary-fixed">
                        Return to Civilian Login
                    </a>
                </div>
            @else
                <div class="my-6 flex items-center gap-3 text-xs font-black uppercase tracking-[0.18em] text-outline">
                    <span class="h-px flex-1 bg-outline-variant/60"></span>
                    <span>or</span>
                    <span class="h-px flex-1 bg-outline-variant/60"></span>
                </div>

                <a href="{{ route('auth.google.redirect') }}" class="inline-flex min-h-[52px] w-full items-center justify-center gap-3 rounded-md border border-outline-variant bg-white px-6 py-3 text-sm font-black text-on-surface shadow-sm transition hover:bg-primary-fixed">
                    <span class="inline-flex h-7 w-7 items-center justify-center rounded-full border border-outline-variant bg-white text-base font-black text-primary">G</span>
                    <span>Sign in with Google account</span>
                </a>

                <div class="mt-6 grid gap-3 sm:grid-cols-2">
                    <a href="{{ route('register') }}" class="inline-flex min-h-[48px] items-center justify-center rounded-md border border-outline-variant bg-white px-5 py-3 text-sm font-black text-primary transition hover:bg-primary-fixed">
                        Register Civilian Account
                    </a>
                    <button type="button" data-open-pwa-helper class="inline-flex min-h-[48px] items-center justify-center rounded-md border border-secondary/20 bg-secondary-fixed px-5 py-3 text-sm font-black text-secondary transition hover:bg-secondary hover:text-on-secondary">
                        Install App
                    </button>
                </div>
            @endif

            <p class="mt-5 text-center text-sm font-semibold text-on-surface-variant">
                @if ($isResponderPortal)
                    Admin receiver accounts are provisioned by the system administrator and cannot be created from public registration.
                @else
                    New civilian reporter? Register first to create your emergency reporting account.
                @endif
            </p>
        </section>
    </main>

    @unless ($isResponderPortal)
        <script src="{{ asset('pwa-helper.js') }}?v={{ $pwaAssetVersion }}"></script>
    @endunless
    <script src="{{ asset('auth-password.js') }}?v={{ $pwaAssetVersion }}"></script>
</body>
</html>
