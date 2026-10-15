@extends('layouts.app')

@section('title', 'Permission Readiness')
@section('page_label', 'Permission Readiness')
@section('page_heading', 'Permission Readiness Check')
@section('page_subheading', $isCivilian ? 'Check device access before sending an emergency report so photo, GPS, notifications, and connectivity are ready.' : 'Check browser access before field operations so photo capture, GPS, notifications, and connectivity are ready for admin use.')

@section('hero')
    <section class="hero-card" style="background:linear-gradient(135deg,rgba(255,255,255,.97),rgba(232,244,255,.96));border-color:rgba(32,104,174,.16);">
        <div class="hero-grid">
            <div class="hero-copy">
                <p class="eyebrow">Device Readiness</p>
                <h2>Verify camera, GPS, notifications, and network state before you rely on the PWA in the field.</h2>
                <p>
                    This check runs directly in the browser on the current device. It does not change server data. Use it before reporting,
                    before a deployment, or after installing the app on a new phone.
                </p>
                <div class="hero-actions">
                    <a href="{{ route('reports.create') }}" class="btn btn-primary">Open Report Form</a>
                    <a href="{{ route('settings.index') }}" class="btn btn-secondary">Back To Settings</a>
                </div>
            </div>
            <div class="hero-metrics">
                <article class="metric-card">
                    <span>Camera</span>
                    <strong>Photo</strong>
                    <p>Confirms whether the browser can request live camera access for evidence and selfie capture.</p>
                </article>
                <article class="metric-card">
                    <span>Location</span>
                    <strong>GPS</strong>
                    <p>Checks if geolocation can return current coordinates from this device and page context.</p>
                </article>
                <article class="metric-card">
                    <span>Notifications</span>
                    <strong>Alerts</strong>
                    <p>Shows whether push-style browser notifications can be allowed on this browser.</p>
                </article>
                <article class="metric-card">
                    <span>Connection</span>
                    <strong>PWA</strong>
                    <p>Summarizes online state, secure context, and whether the app is installed or still in browser mode.</p>
                </article>
            </div>
        </div>
    </section>
@endsection

@section('content')
    <section class="workspace-grid" data-permission-readiness-root>
        <article class="panel">
            <div class="panel-head">
                <div class="panel-heading">
                    <p class="panel-kicker">Readiness Board</p>
                    <h2 class="panel-title">Live permission status</h2>
                    <p class="section-copy">Run each check individually or refresh all results in one pass. Camera and location requests only happen when you trigger them.</p>
                </div>
                <div class="panel-actions">
                    <button type="button" class="btn btn-primary" data-readiness-refresh>Run Full Check</button>
                </div>
            </div>

            <div class="summary-strip">
                <article class="summary-card">
                    <span>Overall readiness</span>
                    <strong data-readiness-score>0 / 5</strong>
                    <p data-readiness-summary>Waiting for the first browser check.</p>
                </article>
                <article class="summary-card">
                    <span>Current mode</span>
                    <strong data-readiness-app-mode>Browser</strong>
                    <p data-readiness-app-copy>App mode will update after the page checks standalone state and secure context.</p>
                </article>
                <article class="summary-card">
                    <span>Connectivity</span>
                    <strong data-readiness-network-value>Unknown</strong>
                    <p data-readiness-network-copy>Network state is checked directly from this device.</p>
                </article>
            </div>

            <div class="preview-grid" style="margin-top:18px;">
                <article class="detail-card" data-readiness-card="camera">
                    <div class="tag neutral" data-readiness-badge="camera">Not checked</div>
                    <strong>Camera access</strong>
                    <p data-readiness-copy="camera">Check whether this browser can request photo, video, and selfie capture.</p>
                    <div class="button-row">
                        <button type="button" class="btn btn-secondary" data-readiness-action="camera">Test camera</button>
                        <button type="button" class="btn btn-secondary" data-readiness-stop-camera hidden>Stop camera</button>
                    </div>
                    <video class="detail-media" data-readiness-camera-preview autoplay muted playsinline hidden></video>
                </article>

                <article class="detail-card" data-readiness-card="location">
                    <div class="tag neutral" data-readiness-badge="location">Not checked</div>
                    <strong>Location access</strong>
                    <p data-readiness-copy="location">Check whether this page can request live GPS coordinates from the device.</p>
                    <div class="button-row">
                        <button type="button" class="btn btn-secondary" data-readiness-action="location">Test location</button>
                    </div>
                    <p class="section-copy" data-readiness-location-result>Coordinates will appear here after a successful location test.</p>
                </article>

                <article class="detail-card" data-readiness-card="notifications">
                    <div class="tag neutral" data-readiness-badge="notifications">Not checked</div>
                    <strong>Notifications</strong>
                    <p data-readiness-copy="notifications">Check whether this browser supports notifications and whether permission is already granted.</p>
                    <div class="button-row">
                        <button type="button" class="btn btn-secondary" data-readiness-action="notifications">Allow notifications</button>
                    </div>
                </article>

                <article class="detail-card" data-readiness-card="network">
                    <div class="tag neutral" data-readiness-badge="network">Not checked</div>
                    <strong>Online and offline state</strong>
                    <p data-readiness-copy="network">Tracks whether the browser is online now and whether the PWA can fall back when connectivity drops.</p>
                    <div class="button-row">
                        <button type="button" class="btn btn-secondary" data-readiness-action="network">Refresh network check</button>
                    </div>
                </article>

                <article class="detail-card" data-readiness-card="app">
                    <div class="tag neutral" data-readiness-badge="app">Not checked</div>
                    <strong>App install mode</strong>
                    <p data-readiness-copy="app">Shows whether the system is running as an installed app, whether service workers are available, and whether the page is secure.</p>
                    <div class="button-row">
                        <button type="button" class="btn btn-secondary" data-readiness-action="app">Refresh app check</button>
                    </div>
                </article>
            </div>
        </article>

        <div class="workspace-side">
            <article class="panel">
                <div class="panel-head">
                    <div class="panel-heading">
                        <p class="panel-kicker">Field Guidance</p>
                        <h2 class="panel-title">What to fix first</h2>
                    </div>
                </div>
                <div class="stack">
                    <div class="detail-card">
                        <strong>Camera blocked</strong>
                        <p>Evidence capture and selfie verification will fail until the browser is allowed to use the camera.</p>
                    </div>
                    <div class="detail-card">
                        <strong>Location blocked</strong>
                        <p>GPS autofill needs a secure page context and a browser that is allowed to access geolocation.</p>
                    </div>
                    <div class="detail-card">
                        <strong>Notifications denied</strong>
                        <p>Admin alerts and browser prompts will stay silent until notification permission is granted again.</p>
                    </div>
                    <div class="detail-card">
                        <strong>Offline detected</strong>
                        <p>Use the local draft queue and submit once the device reconnects, or switch to fallback procedures.</p>
                    </div>
                </div>
            </article>

            <article class="panel">
                <div class="panel-head">
                    <div class="panel-heading">
                        <p class="panel-kicker">Quick Links</p>
                        <h2 class="panel-title">Next actions</h2>
                    </div>
                </div>
                <div class="stack">
                    <a href="{{ route('reports.create') }}" class="detail-card">
                        <strong>{{ $isCivilian ? 'Send Emergency Report' : 'Open Incident Form' }}</strong>
                        <p>{{ $isCivilian ? 'Go back to the reporting form once camera, GPS, and notifications are ready.' : 'Return to the web incident form after checking the current browser.' }}</p>
                    </a>
                    <a href="{{ route('settings.index') }}" class="detail-card">
                        <strong>System Settings</strong>
                        <p>Adjust push notification preferences and fallback connectivity mode.</p>
                    </a>
                    <a href="{{ $isCivilian ? route('dashboard') : route('monitoring') }}" class="detail-card">
                        <strong>Dashboard</strong>
                        <p>{{ $isCivilian ? 'Return to your civilian dashboard and report history.' : 'Return to admin monitoring and live incident activity.' }}</p>
                    </a>
                </div>
            </article>
        </div>
    </section>
@endsection
