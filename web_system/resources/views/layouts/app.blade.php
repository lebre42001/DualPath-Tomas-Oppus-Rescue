<!DOCTYPE html>
<html lang="en" class="{{ auth()->user()?->isCivilian() ? 'civilian-workspace' : 'admin-workspace' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @include('partials.pwa-head')
    <title>@yield('title', 'Emergency App') | Tomas Oppus Rescue</title>
    @include('partials.theme-preference')
    @include('partials.legacy-assets')
    @vite(['resources/css/app.css', 'resources/css/operations.css', 'resources/js/app.js'])



</head>
<body data-pwa-shell class="{{ auth()->user()?->isCivilian() ? 'civilian-portal' : 'admin-portal' }} {{ auth()->user()?->isCivilian() && request()->routeIs('dashboard') ? 'civilian-home-page' : '' }} {{ auth()->user()?->isCivilian() && request()->routeIs('reports.create') ? 'civilian-report-page' : '' }} {{ auth()->user()?->isCivilian() && request()->routeIs('profile.show') ? 'civilian-profile-page' : '' }} {{ auth()->user()?->isCivilian() && request()->routeIs('settings.index') ? 'civilian-settings-page' : '' }} {{ auth()->user()?->isCivilian() && request()->routeIs('settings.readiness') ? 'civilian-readiness-page' : '' }}">
    @php
        $authUser = auth()->user();
        $isCivilian = $authUser?->isCivilian() ?? false;
        $isAccountVerified = ! $isCivilian || ($authUser?->hasVerifiedAccount() ?? false);
        $dashboardUrl = $authUser && $authUser->is_admin ? route('admin.dashboard') : route('dashboard');
        $monitoringUrl = $isCivilian ? route('dashboard') : route('monitoring');
        $sendReportUrl = $isCivilian && ! $isAccountVerified ? route('account.verify') : route('reports.create');
        $roleLabel = $authUser?->is_admin ? 'Admin Receiver' : ($authUser?->role === 'civilian' ? 'Civilian Sender' : 'Admin Receiver');
        $stationLabel = $isCivilian
            ? ($authUser?->responderProfile?->assigned_station ?? 'Civilian Mobile')
            : 'Tomas Oppus Command Center';
        $pageLabel = trim($__env->yieldContent('page_label')) ?: ($isCivilian ? 'Civilian App' : 'Dashboard');
        $pageHeading = trim($__env->yieldContent('page_heading')) ?: (trim($__env->yieldContent('title')) ?: 'Operations Center');
        $pageSubheadingContent = trim($__env->yieldContent('page_subheading'));
        $pageSubheading = $__env->hasSection('page_subheading') ? $pageSubheadingContent : 'Live coordination, dispatch visibility, and incident intelligence.';
        $hideTopbar = trim($__env->yieldContent('hide_topbar')) === 'true';
        $isAdminDashboard = request()->routeIs('admin.dashboard');
        $isReportsPage = request()->routeIs('reports.*');
        $isAdminReportsPage = $isReportsPage && ! $isCivilian;
        $fatalAlertCount = isset($fatalAlerts) ? collect($fatalAlerts)->count() : 0;
        $compactPrimaryUrl = $isCivilian ? route('dashboard') : route('monitoring');
        $compactPrimaryLabel = $isCivilian ? 'Home' : 'Monitor';
        $compactPrimaryCode = $isCivilian ? 'HM' : 'DB';
        $compactSecondaryUrl = $isCivilian ? $sendReportUrl : route('reports.index');
        $compactSecondaryLabel = $isCivilian ? 'Report' : 'Feed';
        $compactSecondaryCode = $isCivilian ? 'RP' : 'FD';
        $nameParts = preg_split('/\s+/', trim((string) $authUser?->name)) ?: [];
        $initials = collect($nameParts)->filter()->map(static fn (string $part): string => strtoupper(substr($part, 0, 1)))->take(2)->implode('');
        $initials = $initials !== '' ? $initials : 'SR';
        $authProfilePhotoUrl = $authUser?->profile_photo_path ? route('profile.photo') : null;
        $runtimeReverbHost = env('VITE_REVERB_HOST', env('REVERB_HOST', request()->getHost()));
        $runtimeReverbPort = (int) env('VITE_REVERB_PORT', env('REVERB_PORT', 443));
        $runtimeReverbScheme = env('VITE_REVERB_SCHEME', env('REVERB_SCHEME', 'https'));
        $navIcons = [
            'home' => <<<'SVG'
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10.5 12 3l9 7.5"/><path d="M5.5 9.5V21h13V9.5"/><path d="M9.5 21v-6.5h5V21"/></svg>
SVG,
            'radar' => <<<'SVG'
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="8"/><path d="M12 4v8l5 5"/><path d="M4 12h2"/><path d="M18 12h2"/></svg>
SVG,
            'report' => <<<'SVG'
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M7 3.5h7l5 5V20a1 1 0 0 1-1 1H7a2 2 0 0 1-2-2V5.5a2 2 0 0 1 2-2Z"/><path d="M14 3.5V9h5"/><path d="M9 13h6"/><path d="M9 17h4"/></svg>
SVG,
            'feed' => <<<'SVG'
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6.5h16"/><path d="M4 12h16"/><path d="M4 17.5h10"/><circle cx="18" cy="17.5" r="2"/></svg>
SVG,
            'profile' => <<<'SVG'
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M5 20a7 7 0 0 1 14 0"/></svg>
SVG,
            'settings' => <<<'SVG'
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v3"/><path d="M12 18v3"/><path d="M3 12h3"/><path d="M18 12h3"/><path d="m5.64 5.64 2.12 2.12"/><path d="m16.24 16.24 2.12 2.12"/><path d="m18.36 5.64-2.12 2.12"/><path d="m7.76 16.24-2.12 2.12"/><circle cx="12" cy="12" r="3.5"/></svg>
SVG,
            'logout' => <<<'SVG'
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M10 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h4"/><path d="M14 16l4-4-4-4"/><path d="M18 12H9"/></svg>
SVG,
            'shield' => <<<'SVG'
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3 5 6v6c0 4.4 2.9 8.2 7 9 4.1-.8 7-4.6 7-9V6l-7-3Z"/><path d="m9.5 12 1.7 1.7 3.3-3.7"/></svg>
SVG,
        ];
    @endphp
    <script>
        window.__STITCH_RUNTIME__ = Object.assign({}, window.__STITCH_RUNTIME__ || {}, {
            reverb: {
                key: @json(env('VITE_REVERB_APP_KEY', env('REVERB_APP_KEY'))),
                host: @json($runtimeReverbHost),
                port: @json($runtimeReverbPort),
                scheme: @json($runtimeReverbScheme),
            },
        });
    </script>

    <button type="button" class="sidebar-backdrop" data-sidebar-backdrop hidden aria-label="Close navigation"></button>
    @if ($authUser)
        <button type="button" class="sidebar-reopen" data-sidebar-reopen aria-controls="primary-navigation" aria-expanded="false" aria-label="Open navigation">
            <span aria-hidden="true">&#9776;</span>
            <strong>Menu</strong>
        </button>
    @endif
    <a href="#main-content" class="ops-skip-link">Skip to content</a>
    <div class="ops-shell">
        <aside id="primary-navigation" class="ops-sidebar" data-sidebar>
            <div class="sidebar-mobile-head">
                @if ($isCivilian)
                    <div class="sidebar-account-heading">
                        <div class="avatar">
                            @if ($authProfilePhotoUrl)
                                <img src="{{ $authProfilePhotoUrl }}" alt="">
                            @else
                                {{ $initials }}
                            @endif
                        </div>
                        <div class="sidebar-account-copy">
                            <strong data-civilian-menu-name>{{ $authUser->name }}</strong>
                            <small>Civilian account</small>
                        </div>
                    </div>
                @else
                    <span class="sidebar-label">Operations Menu</span>
                @endif
                <button type="button" class="pill-btn sidebar-close" data-sidebar-close>Close</button>
            </div>
            <button type="button" class="sidebar-collapse-toggle" data-sidebar-collapse-toggle aria-expanded="true" aria-label="Collapse sidebar">
                <span data-sidebar-collapse-label>Collapse</span>
                <strong aria-hidden="true">&larr;</strong>
            </button>
            <a href="{{ $dashboardUrl }}" class="brand-lockup">
                <span style="color: #ff3b3f;">Tomas Oppus Rescue</span>
                <strong>{{ $isCivilian ? $authUser->name : 'LoRa Rescue Command' }}</strong>
            </a>

            <div class="sidebar-cluster">
                <div class="sidebar-label">{{ $isCivilian ? 'Your navigation' : 'Operations' }}<br><strong>{{ $stationLabel }}</strong></div>
                <nav class="sidebar-nav" aria-label="Main navigation">
                    <a href="{{ $monitoringUrl }}" class="{{ $isCivilian ? (request()->routeIs('dashboard') ? 'active' : '') : ((request()->routeIs('monitoring') || (request()->routeIs('dashboard') && ! $authUser?->is_admin)) ? 'active' : '') }}"><span>{{ $isCivilian ? 'Dashboard' : 'Live Monitoring' }}</span><small aria-hidden="true">{{ $isCivilian ? 'HM' : 'LM' }}</small></a>
                    @if ($authUser?->is_admin)
                        <a href="{{ route('monitoring.lora-mesh') }}" class="{{ request()->routeIs('monitoring.lora-mesh*') ? 'active' : '' }}"><span>LoRa Mesh</span><small aria-hidden="true">LR</small></a>
                        <a href="{{ route('lora-reports.index') }}" class="{{ request()->routeIs('lora-reports.*') ? 'active' : '' }}"><span>LoRa Reports</span><small aria-hidden="true">LP</small></a>
                    @endif
                    @if ($isCivilian)
                        <a href="{{ $sendReportUrl }}" class="{{ request()->routeIs('reports.create') || request()->routeIs('account.verify') ? 'active' : '' }}"><span>{{ $isAccountVerified ? 'Send Report' : 'Verify Account' }}</span><small>{{ $isAccountVerified ? 'SR' : 'VA' }}</small></a>
                        <a href="{{ route('reports.index') }}" class="{{ request()->routeIs('reports.index') || request()->routeIs('reports.show') || request()->routeIs('reports.success') ? 'active' : '' }}"><span>Report Status</span><small>{{ isset($stats['total']) ? $stats['total'] : 'RS' }}</small></a>
                    @else
                        <a href="{{ route('reports.index') }}" class="{{ request()->routeIs('reports.*') ? 'active' : '' }}"><span>Online Report</span><small>{{ isset($stats['active']) ? $stats['active'] : $fatalAlertCount }}</small></a>
                    @endif
                    @if ($authUser && ! $authUser->isCivilian())
                        <a href="{{ route('civilian-accounts.index') }}" class="{{ request()->routeIs('civilian-accounts.*') ? 'active' : '' }}"><span>Civilian Accounts</span><small>CA</small></a>
                    @endif
                    @if ($authUser?->is_admin)
                        <a href="{{ route('admin.dashboard') }}" class="{{ request()->routeIs('admin.dashboard', 'admin.node-locations.*') ? 'active' : '' }}"><span>Overview</span><small>AD</small></a>
                        <a href="{{ route('admin.text-ai-test') }}" class="{{ request()->routeIs('admin.text-ai-test*') ? 'active' : '' }}"><span>Text AI Lab</span><small>AI</small></a>
                    @endif
                    <a href="{{ route('profile.show') }}" class="{{ request()->routeIs('profile.*') ? 'active' : '' }}"><span>{{ $isCivilian ? 'Civilian Profile' : 'Profile' }}</span><small>{{ $isCivilian ? 'CP' : 'AP' }}</small></a>
                    <a href="{{ route('settings.index') }}" class="{{ request()->routeIs('settings.*') ? 'active' : '' }}"><span>{{ $isCivilian ? 'App Settings' : 'Settings' }}</span><small>ST</small></a>
                </nav>
            </div>

            <div class="sidebar-spacer" aria-hidden="true"></div>

            <div class="sidebar-foot">
                @if ($authUser)
                    <form method="POST" action="{{ route('logout') }}" class="sidebar-logout">
                        @csrf
                        <button type="submit" class="btn btn-danger">
                            <span>Log Out</span>
                            <strong>Exit</strong>
                        </button>
                    </form>
                @endif
            </div>
        </aside>

        <div class="ops-main {{ $isAdminReportsPage ? 'reports-ops-main' : '' }}">
            <div class="live-toast-stack" data-live-toast-stack></div>

            @unless ($hideTopbar)
                <header class="topbar {{ $isAdminDashboard ? 'admin-dashboard-topbar' : '' }} {{ $isReportsPage ? 'reports-topbar' : '' }}">
                    <div class="topbar-copy">
                        <p class="topbar-label">{{ $pageLabel }}</p>
                        <h1>{{ $pageHeading }}</h1>
                        @if ($pageSubheading !== '')
                            <span>{{ $pageSubheading }}</span>
                        @endif
                    </div>
                    <div class="topbar-actions">
                        <button type="button" class="pill-btn sidebar-toggle" data-sidebar-toggle aria-controls="primary-navigation" aria-expanded="false">{{ $isCivilian ? $authUser->name : 'Menu' }}</button>
                        <div class="pwa-shell-pill" data-pwa-status-pill><span data-pwa-status-label>Online Ready</span><strong data-pwa-display-mode>Browser</strong></div>
                        @unless ($isCivilian)
                            <button type="button" class="pill-btn" data-alert-center-toggle aria-expanded="false"><span>Alerts</span><strong data-alert-count>{{ $fatalAlertCount }}</strong></button>
                        @else
                            <a href="{{ $sendReportUrl }}" class="mini-chip"><span>Quick Action</span><strong>{{ $isAccountVerified ? 'Send Report' : 'Verify Account' }}</strong></a>
                        @endunless
                        <div class="topbar-profile-stack">
                            <div class="mini-chip"><span>System Time</span><strong data-system-time>{{ now()->format('Y-m-d H:i:s') }}</strong></div>
                            @if ($authUser)
                                <div class="identity-card">
                                    <div><strong>{{ $authUser->name }}</strong><span>{{ $roleLabel }}</span></div>
                                    <div class="avatar">
                                        @if ($authProfilePhotoUrl)
                                            <img src="{{ $authProfilePhotoUrl }}" alt="{{ $authUser->name }} profile photo">
                                        @else
                                            {{ $initials }}
                                        @endif
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>
                </header>
            @endunless

            @if (! $isCivilian && ! $isAdminDashboard && ! request()->routeIs('reports.*') && isset($connectivity) && !(in_array(($dashboardMode ?? null), ['admin', 'responder'], true) && (request()->routeIs('dashboard') || request()->routeIs('monitoring'))))
                <section class="system-strip">
                    <article class="system-card"><span>LoRa Status</span><strong class="system-dot">{{ $connectivity['lora_status'] ?? 'LoRa standby' }}</strong><p>Fallback mesh telemetry for compact incident transmission.</p></article>
                    <article class="system-card"><span>Internet Status</span><strong class="system-dot {{ str_contains(strtolower($connectivity['internet_status'] ?? ''), 'degraded') ? 'warning' : '' }}">{{ $connectivity['internet_status'] ?? 'Internet uplink healthy' }}</strong><p>Primary channel for full evidence payloads and admin sync.</p></article>
                    <article class="system-card"><span>Websocket / Reverb</span><strong class="system-dot">{{ $connectivity['websocket_status'] ?? 'Reverb live sync online' }}</strong><p>Real-time alert propagation to dashboards and admin views.</p></article>
                    <article class="system-card"><span>Queue Status</span><strong class="system-dot">{{ $connectivity['queue_status'] ?? 'Immediate broadcast channel' }}</strong><p>Incident updates and broadcast events are ready for dispatch.</p></article>
                    <article class="system-card"><span>Server / API</span><strong class="system-dot">{{ $connectivity['api_status'] ?? 'Laravel API healthy' }}</strong><p>Web dashboard and mobile API endpoint availability snapshot.</p></article>
                    <article class="system-card"><span>Gateway Sync</span><strong class="system-dot">{{ $connectivity['gateway_status'] ?? 'Gateway synchronized' }}</strong><p>Shared situational data across admin web and mobile interfaces.</p></article>
                </section>
            @endif

            @hasSection('hero')
                @yield('hero')
            @endif

            @if ($errors->any())
                <div class="flash error">{{ $errors->first() }}</div>
            @endif

            @if (session('status'))
                <div class="flash">{{ session('status') }}</div>
            @endif

            <main id="main-content" tabindex="-1" class="content-stack {{ $isAdminReportsPage ? 'reports-content-stack' : '' }}">
                @yield('content')
            </main>
        </div>
    </div>
    @if ($authUser)
        <nav class="mobile-quick-nav" aria-label="Quick navigation">
            <a href="{{ $compactPrimaryUrl }}" class="{{ ($isCivilian && request()->routeIs('dashboard')) || (! $isCivilian && request()->routeIs('monitoring')) ? 'active' : '' }}">
                <span aria-hidden="true">{!! $authUser?->is_admin ? $navIcons['shield'] : ($isCivilian ? $navIcons['home'] : $navIcons['radar']) !!}</span>
                <strong>{{ $compactPrimaryLabel }}</strong>
            </a>
            <a href="{{ $compactSecondaryUrl }}" class="{{ ($isCivilian && (request()->routeIs('reports.create') || request()->routeIs('account.verify'))) || (! $isCivilian && request()->routeIs('reports.index')) ? 'active' : '' }}">
                <span aria-hidden="true">{!! $isCivilian ? $navIcons['report'] : $navIcons['feed'] !!}</span>
                <strong>{{ $compactSecondaryLabel }}</strong>
            </a>
            <a href="{{ route('profile.show') }}" class="{{ request()->routeIs('profile.*') ? 'active' : '' }}">
                <span aria-hidden="true">{!! $navIcons['profile'] !!}</span>
                <strong>Profile</strong>
            </a>
            <a href="{{ route('settings.index') }}" class="{{ request()->routeIs('settings.*') ? 'active' : '' }}">
                <span aria-hidden="true">{!! $navIcons['settings'] !!}</span>
                <strong>Settings</strong>
            </a>
            <form method="POST" action="{{ route('logout') }}" class="mobile-quick-nav__logout">
                @csrf
                <button type="submit" class="is-logout" aria-label="Log out">
                    <span aria-hidden="true">{!! $navIcons['logout'] !!}</span>
                    <strong>Logout</strong>
                </button>
            </form>
        </nav>
    @endif
    @unless ($isCivilian)
        <aside class="alert-center" data-alert-center-panel hidden>
            <div class="alert-top">
                <div>
                    <p class="eyebrow">Alert Center</p>
                    <h3>Latest fatal incidents</h3>
                </div>
                <button type="button" class="pill-btn" data-alert-center-close>Close</button>
            </div>
            <div class="alert-list" data-alert-list>
                @forelse (($fatalAlerts ?? []) as $alert)
                    <a href="{{ route('reports.show', $alert) }}" class="alert-item">
                        <span class="tag red">Fatal</span>
                        <strong>{{ $alert->incident_type }}</strong>
                        <p>{{ $alert->location_text }}</p>
                        <p>{{ optional($alert->created_at)->diffForHumans() }}</p>
                    </a>
                @empty
                    <div class="data-empty">No fatal incidents are queued right now.</div>
                @endforelse
            </div>
        </aside>
    @endunless

    @if ($authUser && ! $isCivilian)
        <aside class="notification-gate" data-admin-notification-prompt hidden aria-live="polite">
            <div class="notification-gate-card">
                <p class="eyebrow">Emergency Notifications</p>
                <h2>Allow alerts before monitoring.</h2>
                <p data-admin-notification-copy>Admins need browser notifications so incoming emergency reports can be noticed immediately after login.</p>
                <button type="button" class="btn btn-primary" data-admin-notification-allow>Allow Notifications</button>
                <button type="button" class="btn btn-secondary" data-admin-notification-dismiss>Continue to dashboard</button>
            </div>
        </aside>
    @endif

    <div class="media-viewer" data-media-viewer hidden>
        <div class="media-viewer-shell" role="dialog" aria-modal="true" aria-labelledby="media-viewer-title">
            <div class="media-viewer-top">
                <div>
                    <p class="eyebrow">Evidence Viewer</p>
                    <strong id="media-viewer-title" data-media-viewer-title>Evidence Preview</strong>
                </div>
                <button type="button" class="media-viewer-close" data-media-viewer-close aria-label="Close media viewer">&times;</button>
            </div>
            <div class="media-viewer-frame">
                <img data-media-viewer-image alt="Expanded evidence preview" hidden>
                <video data-media-viewer-video controls playsinline hidden></video>
            </div>
        </div>
    </div>
    @if ($authUser && ! $isCivilian)
        <div class="report-detail-modal" data-report-detail-modal hidden>
            <div class="report-detail-modal-shell" role="dialog" aria-modal="true" aria-labelledby="report-detail-modal-title">
                <div class="report-detail-modal-head">
                    <div class="report-detail-modal-title">
                        <p class="eyebrow">Report Review</p>
                        <strong id="report-detail-modal-title" data-report-detail-modal-title>Incident details</strong>
                    </div>
                    <button type="button" class="report-detail-modal-close" data-report-detail-modal-close aria-label="Close report details">&times;</button>
                </div>
                <div class="report-detail-modal-body" data-report-detail-modal-body></div>
            </div>
        </div>
        <div class="report-map-modal" data-report-map-modal hidden>
            <div class="report-map-modal-shell" role="dialog" aria-modal="true" aria-labelledby="report-map-modal-title">
                <div class="report-map-modal-head">
                    <div class="report-map-modal-title">
                        <p class="eyebrow">Map Pin</p>
                        <strong id="report-map-modal-title" data-report-map-modal-title>Incident map location</strong>
                    </div>
                    <button type="button" class="report-map-modal-close" data-report-map-modal-close aria-label="Close map">&times;</button>
                </div>
                <div class="report-map-modal-body" data-report-map-modal-body></div>
            </div>
        </div>
    @endif
    @if ($isCivilian)
    <span hidden data-presence-url="{{ route('account.presence') }}" data-presence-interval="{{ \App\Support\CivilianPresence::HEARTBEAT_SECONDS }}"></span>
    <section class="pwa-install-card" data-pwa-install-card hidden>
        <div class="pwa-install-copy">
            <p class="eyebrow">Install Tomas Oppus Rescue</p>
            <strong data-pwa-install-title>Install the civilian sender app.</strong>
            <p data-pwa-install-copy>Add Tomas Oppus Rescue to your phone for faster emergency reporting and quick access to your report status.</p>
        </div>
        <div class="pwa-install-actions">
            <button type="button" class="btn btn-primary" data-pwa-install-action>Install App</button>
            <button type="button" class="btn btn-secondary" data-pwa-dismiss-action>Later</button>
        </div>
    </section>
    @endif
    <section class="pwa-update-card" data-pwa-update-card hidden role="status" aria-live="polite" aria-atomic="true">
        <div class="pwa-update-copy">
            <p class="pwa-update-badge" data-pwa-update-eyebrow>Updating App</p>
            <strong data-pwa-update-title>Applying latest system update.</strong>
            <p data-pwa-update-copy>The website and installed app will reload automatically with the newest emergency workflow and fixes.</p>
        </div>
    </section>

</body>
</html>
