@extends('layouts.app')

@php
    $isCivilian = $user?->isCivilian() ?? false;
@endphp

@section('title', $isCivilian ? 'App Settings' : 'System Settings')
@section('page_label', $isCivilian ? 'App Settings' : 'System Settings')
@section('page_heading', $isCivilian ? 'Civilian App Settings' : 'System Settings')
@section('page_subheading', $isCivilian ? 'Set report-status notifications and device display from one simple page.' : 'Manage alerts, connection preferences, and display settings.')

@section('hero')
    @if ($isCivilian)
    <section class="hero-card civilian-settings-hero" style="background:rgba(255,255,255,.96);border-color:rgba(15,31,47,.10);">
        <div class="hero-grid">
            <div class="hero-copy">
                <p class="eyebrow">Settings Center</p>
                <h2>Settings that matter.</h2>
                <p>
                    Choose how this account receives report-status updates and displays on this device.
                    Changes save automatically.
                </p>
                <div class="hero-actions">
                    <a href="{{ route('dashboard') }}" class="btn btn-primary">Home</a>
                    <a href="{{ route('profile.show') }}" class="btn btn-secondary">Profile</a>
                </div>
            </div>
            <div class="hero-metrics civilian-settings-metrics">
                <article class="metric-card">
                    <span>Status alerts</span>
                    <strong>{{ $settings['critical_alerts'] ? 'On' : 'Off' }}</strong>
                    <p>Updates about your reports.</p>
                </article>
                <article class="metric-card">
                    <span>Push notifications</span>
                    <strong>{{ $settings['push_notifications'] ? 'On' : 'Off' }}</strong>
                    <p>Browser or device alerts.</p>
                </article>
                <article class="metric-card">
                    <span>SMS backup</span>
                    <strong>{{ $settings['sms_backup'] ? 'On' : 'Off' }}</strong>
                    <p>Backup alert path.</p>
                </article>
            </div>
        </div>
    </section>
    @endif
@endsection

@section('content')
    @unless ($isCivilian)
        <div class="admin-settings-save-status" role="status" aria-live="polite">
            <strong data-settings-status>Ready</strong>
            <span data-settings-message>Notification and connectivity changes are saved automatically.</span>
        </div>
    @endunless
    <section class="workspace-grid {{ $isCivilian ? 'civilian-settings-workspace' : 'admin-settings-workspace' }}">
        <article class="panel {{ $isCivilian ? 'civilian-settings-card' : '' }}">
            <div class="panel-head">
                <div class="panel-heading">
                    <p class="panel-kicker">Notifications</p>
                    <h2 class="panel-title">Alert preferences</h2>
                    <p class="section-copy">Turn alert channels on or off.</p>
                </div>
            </div>

            <div class="toggle-stack" data-settings-root data-settings-endpoint="{{ route('settings.update') }}" data-connectivity-initial="{{ $settings['connectivity_mode'] }}">
                <div class="toggle-item">
                    <div class="toggle-copy">
                        <strong>{{ $isCivilian ? 'Report status alerts' : 'Critical alerts' }}</strong>
                        <p>{{ $isCivilian ? 'Notify this device when one of your reports changes status.' : 'For fatal incidents and urgent system notices.' }}</p>
                    </div>
                    <label class="switch">
                        <input type="checkbox" aria-label="{{ $isCivilian ? 'Report status alerts' : 'Critical alerts' }}" data-setting-input="critical_alerts" @checked($settings['critical_alerts'])>
                        <span class="switch-track"></span>
                    </label>
                </div>

                <div class="toggle-item">
                    <div class="toggle-copy">
                        <strong>Push notifications</strong>
                        <p>Show updates on this browser or device.</p>
                    </div>
                    <label class="switch">
                        <input type="checkbox" aria-label="Push notifications" data-setting-input="push_notifications" @checked($settings['push_notifications'])>
                        <span class="switch-track"></span>
                    </label>
                </div>

                <div class="toggle-item">
                    <div class="toggle-copy">
                        <strong>SMS backup</strong>
                        <p>Use SMS when internet alerts are unreliable.</p>
                    </div>
                    <label class="switch">
                        <input type="checkbox" aria-label="SMS backup" data-setting-input="sms_backup" @checked($settings['sms_backup'])>
                        <span class="switch-track"></span>
                    </label>
                </div>
            </div>
        </article>

        <div class="workspace-side {{ $isCivilian ? 'civilian-settings-side' : 'admin-settings-side' }}">
            @unless ($isCivilian)
            <article class="panel {{ $isCivilian ? 'civilian-settings-card' : 'admin-settings-connectivity' }}">
                <div class="panel-head">
                    <div class="panel-heading">
                        <p class="panel-kicker">Connectivity</p>
                        <h2 class="panel-title">Fallback behavior</h2>
                        <p class="section-copy">Choose automatic routing or LoRa fallback.</p>
                    </div>
                </div>

                <div class="settings-grid">
                    <button type="button" class="setting-card" data-connectivity-mode="auto_select" aria-pressed="{{ $settings['connectivity_mode'] === 'auto_select' ? 'true' : 'false' }}">
                        <strong>Auto Select</strong>
                        <p>Use the best available path.</p>
                        <span class="tag {{ $settings['connectivity_mode'] === 'auto_select' ? 'blue' : 'neutral' }}">Recommended</span>
                    </button>

                    <button type="button" class="setting-card" data-connectivity-mode="lora_fallback" aria-pressed="{{ $settings['connectivity_mode'] === 'lora_fallback' ? 'true' : 'false' }}">
                        <strong>LoRa Fallback</strong>
                        <p>Prefer fallback behavior for weak internet.</p>
                        <span class="tag {{ $settings['connectivity_mode'] === 'lora_fallback' ? 'green' : 'neutral' }}">Fallback Ready</span>
                    </button>
                </div>

                <div class="panel-note">
                    <strong>Current mode</strong>
                    <p class="subtle" data-connectivity-label>{{ $settings['connectivity_mode'] === 'lora_fallback' ? 'LoRa fallback is enabled for this account.' : 'Automatic connectivity selection is enabled for this account.' }}</p>
                </div>
            </article>
            @endunless

            <article class="panel {{ $isCivilian ? 'civilian-settings-card' : 'admin-settings-appearance' }}">
                <div class="panel-head">
                    <div class="panel-heading">
                        <p class="panel-kicker">Appearance</p>
                        <h2 class="panel-title">Display mode</h2>
                        <p class="section-copy">Follow your device appearance or choose a mode for this browser.</p>
                    </div>
                </div>

                <div class="settings-grid" role="group" aria-label="Display mode">
                    <button type="button" class="setting-card" data-theme-choice="system" aria-pressed="false">
                        <strong>System</strong>
                        <p>Follow device light or dark mode automatically.</p>
                    </button>
                    <button type="button" class="setting-card" data-theme-choice="light" aria-pressed="false">
                        <strong>Light Mode</strong>
                        <p>Clear cards and bright display.</p>
                    </button>
                    <button type="button" class="setting-card" data-theme-choice="dark" aria-pressed="false">
                        <strong>Dark Mode</strong>
                        <p>Lower glare for long use.</p>
                    </button>
                </div>
                <p class="subtle" data-theme-status role="status" aria-live="polite">The display follows your device unless you choose Light or Dark.</p>
            </article>

            @if ($isCivilian)
            <article class="panel civilian-settings-card">
                <div class="panel-head">
                    <div class="panel-heading">
                        <p class="panel-kicker">Save Status</p>
                        <h2 class="panel-title">Auto-save summary</h2>
                    </div>
                </div>
                <div class="panel-note">
                    <strong data-settings-status>Ready</strong>
                    <p class="subtle" data-settings-message>Notification and connectivity changes are saved automatically.</p>
                </div>
            </article>
            @endif
        </div>
    </section>

    <section class="dual-grid {{ $isCivilian ? 'civilian-settings-lower-grid' : 'admin-settings-lower-grid' }}">
        @if ($isCivilian)
        <article class="panel civilian-settings-card">
            <div class="panel-head">
                <div class="panel-heading">
                    <p class="panel-kicker">Current Setup</p>
                    <h2 class="panel-title">Preference summary</h2>
                </div>
            </div>
            <div class="meta-list">
                <div class="meta-row"><strong>{{ $isCivilian ? 'Report status alerts' : 'Critical alerts' }}</strong><span>{{ $settings['critical_alerts'] ? 'Enabled' : 'Disabled' }}</span></div>
                <div class="meta-row"><strong>Push notifications</strong><span>{{ $settings['push_notifications'] ? 'Enabled' : 'Disabled' }}</span></div>
                <div class="meta-row"><strong>SMS backup</strong><span>{{ $settings['sms_backup'] ? 'Enabled' : 'Disabled' }}</span></div>
            </div>
        </article>
        @endif

        <article class="panel {{ $isCivilian ? 'civilian-settings-card civilian-settings-links-card' : 'admin-settings-links' }}">
            <div class="panel-head">
                <div class="panel-heading">
                    <p class="panel-kicker">Quick Links</p>
                    <h2 class="panel-title">Useful links</h2>
                </div>
            </div>
            <div class="stack">
                <a href="{{ $isCivilian ? route('dashboard') : route('monitoring') }}" class="detail-card">
                    <strong>{{ $isCivilian ? 'Dashboard' : 'Live Monitoring' }}</strong>
                    <p>{{ $isCivilian ? 'Return to report and status.' : 'Return to live incident monitoring.' }}</p>
                </a>
                <a href="{{ route('reports.index') }}" class="detail-card">
                    <strong>{{ $isCivilian ? 'My Reports' : 'Incident Reports' }}</strong>
                    <p>{{ $isCivilian ? 'Open your report history.' : 'Open the full report queue.' }}</p>
                </a>
                <a href="{{ route('settings.readiness') }}" class="detail-card">
                    <strong>Permission Readiness Check</strong>
                    <p>Check camera, GPS, notifications, and connection.</p>
                </a>
                <a href="{{ route('profile.show') }}" class="detail-card">
                    <strong>{{ $isCivilian ? 'Profile' : 'Admin Profile' }}</strong>
                    <p>Update account details and profile photo.</p>
                </a>
            </div>
        </article>
    </section>


@endsection
