@php
    $shortBrand = 'Bontoc Rescue';
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
    <title>{{ $shortBrand }} | Register</title>
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
                <a href="{{ route('login') }}" class="rounded-md px-4 py-2 text-white transition hover:bg-white/15">Login</a>
                <a href="{{ route('register') }}" class="rounded-md bg-white px-4 py-2 text-primary">Register</a>
            </nav>
        </div>
    </header>

    <main class="mx-auto grid w-full min-w-0 max-w-7xl gap-8 px-5 py-8 sm:px-8 lg:grid-cols-[minmax(320px,0.78fr)_minmax(0,1fr)] lg:py-12">
        <section class="min-w-0 lg:sticky lg:top-8 lg:self-start">
            <p class="inline-flex rounded-md border border-primary/15 bg-primary-fixed px-3 py-2 text-xs font-black uppercase text-primary">
                Account setup
            </p>
            <h1 class="mt-5 break-words text-3xl font-black leading-tight text-on-surface sm:text-5xl">
                Create your civilian emergency account.
            </h1>
            <p class="mt-5 max-w-xl break-words text-base font-semibold leading-relaxed text-on-surface-variant">
                Use the sender app to report an emergency online, keep your reference code, and follow the response status from your phone.
            </p>

            <div class="mt-8 min-w-0 max-w-full rounded-md border border-outline-variant/50 bg-white p-5">
                <div class="flex items-center gap-4 border-b border-outline-variant/40 pb-4">
                    <img src="{{ asset('icons/icon-512.png') }}" alt="{{ $shortBrand }} app icon" class="h-16 w-16 rounded-md bg-primary-fixed p-2">
                    <div class="min-w-0">
                        <p class="text-xs font-black uppercase text-outline">After registration</p>
                        <h2 class="mt-1 break-words text-xl font-black">You go straight to the sender app</h2>
                    </div>
                </div>
                <div class="grid gap-4 pt-4">
                    <div class="grid grid-cols-[36px_minmax(0,1fr)] gap-3">
                        <span class="flex h-9 w-9 items-center justify-center rounded-md bg-primary text-sm font-black text-on-primary">C</span>
                        <p class="min-w-0 break-words text-sm font-semibold leading-relaxed text-on-surface-variant">
                            Send a scene photo, verification selfie, location, and short emergency description.
                        </p>
                    </div>
                    <div class="grid grid-cols-[36px_minmax(0,1fr)] gap-3">
                        <span class="flex h-9 w-9 items-center justify-center rounded-md bg-secondary text-sm font-black text-on-secondary">S</span>
                        <p class="min-w-0 break-words text-sm font-semibold leading-relaxed text-on-surface-variant">
                            Check only your submitted emergency reports and their current response status.
                        </p>
                    </div>
                </div>
            </div>
        </section>

        <section class="min-w-0 max-w-full rounded-md border border-outline-variant/50 bg-white p-5 shadow-xl shadow-slate-900/5 sm:p-8">
            <div class="mb-6">
                <p class="text-xs font-black uppercase text-primary">Register</p>
                <h2 class="mt-2 break-words text-2xl font-black text-on-surface sm:text-3xl">Civilian Account Registration</h2>
                <p class="mt-2 text-sm font-semibold leading-relaxed text-on-surface-variant">
                    This public registration creates a civilian sender account only.
                </p>
            </div>

            @if ($errors->any())
                <div class="mb-5 rounded-md bg-error-container px-4 py-3 text-sm font-semibold text-on-error-container">
                    {{ $errors->first() }}
                </div>
            @endif

            <form action="{{ route('register') }}" method="POST" class="grid gap-5">
                @csrf

                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <label for="name" class="text-sm font-black text-on-surface">Full name</label>
                        <input
                            id="name"
                            name="name"
                            value="{{ old('name') }}"
                            type="text"
                            required
                            autocomplete="name"
                            placeholder="Juan Dela Cruz"
                            class="mt-2 min-h-[52px] w-full rounded-md border border-outline-variant bg-surface-container-lowest px-4 py-3 text-base font-semibold text-on-surface outline-none transition focus:border-primary focus:ring-2 focus:ring-primary/15"
                        >
                        @error('name')
                            <p class="mt-2 text-sm font-semibold text-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="phone" class="text-sm font-black text-on-surface">Phone number</label>
                        <input
                            id="phone"
                            name="phone"
                            value="{{ old('phone') }}"
                            type="tel"
                            required
                            autocomplete="tel"
                            placeholder="0917 123 4567"
                            class="mt-2 min-h-[52px] w-full rounded-md border border-outline-variant bg-surface-container-lowest px-4 py-3 text-base font-semibold text-on-surface outline-none transition focus:border-primary focus:ring-2 focus:ring-primary/15"
                        >
                        @error('phone')
                            <p class="mt-2 text-sm font-semibold text-error">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div>
                    <label for="email" class="text-sm font-black text-on-surface">Email address</label>
                    <input
                        id="email"
                        name="email"
                        value="{{ old('email') }}"
                        type="email"
                        required
                        autocomplete="username"
                        placeholder="name@example.com"
                        class="mt-2 min-h-[52px] w-full rounded-md border border-outline-variant bg-surface-container-lowest px-4 py-3 text-base font-semibold text-on-surface outline-none transition focus:border-primary focus:ring-2 focus:ring-primary/15"
                    >
                    @error('email')
                        <p class="mt-2 text-sm font-semibold text-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <div class="flex items-center justify-between gap-3">
                            <label for="password" class="text-sm font-black text-on-surface">Password</label>
                            <span class="text-xs font-semibold text-on-surface-variant">Min. 6 characters required</span>
                        </div>
                        <div class="relative mt-2">
                            <input
                                id="password"
                                name="password"
                                type="password"
                                required
                                minlength="6"
                                autocomplete="new-password"
                                placeholder="Create password"
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

                    <div>
                        <label for="password_confirmation" class="text-sm font-black text-on-surface">Confirm password</label>
                        <div class="relative mt-2">
                            <input
                                id="password_confirmation"
                                name="password_confirmation"
                                type="password"
                                required
                                minlength="6"
                                autocomplete="new-password"
                                placeholder="Repeat password"
                                data-auth-password-input
                                class="min-h-[52px] w-full rounded-md border border-outline-variant bg-surface-container-lowest px-4 py-3 pr-20 text-base font-semibold text-on-surface outline-none transition focus:border-primary focus:ring-2 focus:ring-primary/15"
                            >
                            <button type="button" data-auth-toggle-target="password_confirmation" aria-pressed="false" class="absolute right-3 top-1/2 -translate-y-1/2 rounded-md px-3 py-2 text-xs font-black text-primary transition hover:bg-primary-fixed">
                                Show
                            </button>
                        </div>
                    </div>
                </div>

                <button type="submit" class="inline-flex min-h-[52px] items-center justify-center rounded-md bg-primary px-6 py-3 text-sm font-black text-on-primary shadow-lg shadow-primary/20 transition hover:bg-primary-container">
                    Create Account
                </button>
            </form>

            <div class="mt-6 grid gap-3 sm:grid-cols-2">
                <a href="{{ route('login') }}" class="inline-flex min-h-[48px] items-center justify-center rounded-md border border-outline-variant bg-white px-5 py-3 text-sm font-black text-primary transition hover:bg-primary-fixed">
                    Log in
                </a>
                <button type="button" data-open-pwa-helper class="inline-flex min-h-[48px] items-center justify-center rounded-md border border-secondary/20 bg-secondary-fixed px-5 py-3 text-sm font-black text-secondary transition hover:bg-secondary hover:text-on-secondary">
                    Install App
                </button>
            </div>
        </section>
    </main>

    <script src="{{ asset('pwa-helper.js') }}?v={{ $pwaAssetVersion }}"></script>
    <script src="{{ asset('auth-password.js') }}?v={{ $pwaAssetVersion }}"></script>
</body>
</html>
