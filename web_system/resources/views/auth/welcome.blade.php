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
    <title>{{ $shortBrand }} | Emergency Response</title>
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
                <a href="{{ route('welcome') }}" class="rounded-md bg-white px-4 py-2 text-primary">Home</a>
                <a href="{{ route('login') }}" class="rounded-md px-4 py-2 text-white transition hover:bg-white/15">Login</a>
                <a href="{{ route('register') }}" class="rounded-md px-4 py-2 text-white transition hover:bg-white/15">Register</a>
            </nav>
        </div>
    </header>

    <main>
        <section class="border-b border-outline-variant/30 bg-surface">
            <div class="mx-auto grid max-w-7xl items-center gap-8 px-5 py-10 sm:px-8 lg:grid-cols-[minmax(0,1fr)_minmax(360px,0.86fr)] lg:py-14">
                <div class="max-w-3xl">
                    <p class="inline-flex rounded-md border border-primary/15 bg-primary-fixed px-3 py-2 text-xs font-black uppercase text-primary">
                        Civilian sender app
                    </p>

                    <h1 class="mt-5 break-words text-3xl font-black leading-[1.08] text-on-surface sm:text-5xl lg:text-6xl">
                        Report emergencies online. Track response status.
                    </h1>

                    <p class="mt-6 max-w-2xl text-lg font-semibold leading-relaxed text-on-surface-variant">
                        Send a protected emergency report with a scene photo, verification selfie, location, and short description. Keep your reference code and check the current response status from the same account.
                    </p>

                    <div class="mt-8 flex flex-wrap gap-3">
                        <a href="{{ route('register') }}" class="inline-flex min-h-[52px] items-center justify-center rounded-md bg-primary px-7 py-3 text-sm font-black text-on-primary shadow-lg shadow-primary/20 transition hover:bg-primary-container">
                            Get Started
                        </a>
                        <a href="{{ route('login') }}" class="inline-flex min-h-[52px] items-center justify-center rounded-md bg-secondary px-7 py-3 text-sm font-black text-on-secondary transition hover:bg-on-secondary-fixed-variant">
                            Login
                        </a>
                        <button type="button" data-open-pwa-helper class="inline-flex min-h-[52px] items-center justify-center rounded-md border border-outline-variant bg-white px-7 py-3 text-sm font-black text-primary transition hover:bg-primary-fixed">
                            Install App
                        </button>
                    </div>

                    <div class="mt-10 grid gap-3 sm:grid-cols-3">
                        <div class="rounded-md border border-outline-variant/50 bg-white p-4">
                            <p class="text-xs font-black uppercase text-outline">Report</p>
                            <p class="mt-2 text-2xl font-black">Scene Photo</p>
                            <p class="mt-1 text-sm font-semibold text-on-surface-variant">Attach clear evidence to your emergency report.</p>
                        </div>
                        <div class="rounded-md border border-outline-variant/50 bg-white p-4">
                            <p class="text-xs font-black uppercase text-outline">Location</p>
                            <p class="mt-2 text-2xl font-black">GPS Ready</p>
                            <p class="mt-1 text-sm font-semibold text-on-surface-variant">Share GPS or a clear manual landmark.</p>
                        </div>
                        <div class="rounded-md border border-outline-variant/50 bg-white p-4">
                            <p class="text-xs font-black uppercase text-outline">Progress</p>
                            <p class="mt-2 text-2xl font-black">Status Updates</p>
                            <p class="mt-1 text-sm font-semibold text-on-surface-variant">Review only your own submitted reports.</p>
                        </div>
                    </div>
                </div>

                <aside class="rounded-md border border-outline-variant/50 bg-white p-5 shadow-xl shadow-slate-900/5">
                    <div class="flex flex-col gap-4 border-b border-outline-variant/40 pb-5 sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-w-0">
                            <p class="text-xs font-black uppercase text-outline">Online report flow</p>
                            <h2 class="mt-1 break-words text-2xl font-black">Civilian Sender</h2>
                            <p class="mt-2 max-w-sm text-sm font-semibold leading-relaxed text-on-surface-variant">
                                One focused path for sending an emergency and following its response progress.
                            </p>
                        </div>
                        <img src="{{ asset('icons/icon-512.png') }}" alt="Bontoc Rescue app icon" class="h-24 w-24 rounded-md bg-primary-fixed p-3">
                    </div>

                    <div class="grid gap-4 py-5">
                        <div class="grid grid-cols-[44px_minmax(0,1fr)] gap-4">
                            <span class="flex h-11 w-11 items-center justify-center rounded-md bg-primary text-sm font-black text-on-primary">1</span>
                            <div>
                                <p class="font-black">Submit emergency details</p>
                                <p class="text-sm font-semibold text-on-surface-variant">Scene photo, verification selfie, GPS or landmark, and description.</p>
                            </div>
                        </div>
                        <div class="grid grid-cols-[44px_minmax(0,1fr)] gap-4">
                            <span class="flex h-11 w-11 items-center justify-center rounded-md bg-secondary text-sm font-black text-on-secondary">2</span>
                            <div>
                                <p class="font-black">Receive a reference code</p>
                                <p class="text-sm font-semibold text-on-surface-variant">The server confirms that your report was safely received.</p>
                            </div>
                        </div>
                        <div class="grid grid-cols-[44px_minmax(0,1fr)] gap-4">
                            <span class="flex h-11 w-11 items-center justify-center rounded-md bg-tertiary text-sm font-black text-on-tertiary">3</span>
                            <div>
                                <p class="font-black">Check the response status</p>
                                <p class="text-sm font-semibold text-on-surface-variant">See whether your report is pending, under review, responded, cancelled, or resolved.</p>
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-3 gap-3 border-t border-outline-variant/40 pt-4 text-center">
                        <div>
                            <p class="text-xs font-black uppercase text-outline">Path</p>
                            <p class="mt-1 font-black text-on-surface">Online</p>
                        </div>
                        <div>
                            <p class="text-xs font-black uppercase text-outline">Account</p>
                            <p class="mt-1 font-black text-on-surface">Civilian</p>
                        </div>
                        <div>
                            <p class="text-xs font-black uppercase text-outline">Status</p>
                            <p class="mt-1 font-black text-on-surface">Ready</p>
                        </div>
                    </div>
                </aside>
            </div>
        </section>

        <section class="bg-white">
            <div class="mx-auto grid max-w-7xl gap-6 px-5 py-8 sm:px-8 lg:grid-cols-3">
                <div class="border-l-4 border-primary pl-4">
                    <h2 class="text-lg font-black">Focused reporting</h2>
                    <p class="mt-2 text-sm font-semibold leading-relaxed text-on-surface-variant">The public app keeps emergency submission and status tracking easy to find.</p>
                </div>
                <div class="border-l-4 border-secondary pl-4">
                    <h2 class="text-lg font-black">Private history</h2>
                    <p class="mt-2 text-sm font-semibold leading-relaxed text-on-surface-variant">Civilian accounts can open only the emergency reports they submitted.</p>
                </div>
                <div class="border-l-4 border-tertiary pl-4">
                    <h2 class="text-lg font-black">Installable sender</h2>
                    <p class="mt-2 text-sm font-semibold leading-relaxed text-on-surface-variant">Add the sender app to Android, iPhone, or desktop for quicker access.</p>
                </div>
            </div>
        </section>
    </main>

    <script src="{{ asset('pwa-helper.js') }}?v={{ $pwaAssetVersion }}"></script>
</body>
</html>
