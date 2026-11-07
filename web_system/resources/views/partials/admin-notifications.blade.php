<section data-admin-notifications data-owner="{{ $authUser->id }}" data-running-version="{{ $pwaAssetVersion }}"
    @if($authUser->is_admin) data-admin-app-updates data-updates-url="{{ route('admin.notification-updates') }}" data-event-url="{{ route('admin.app-events') }}" @endif>
    <button type="button" class="admin-notifications-toggle" data-alert-center-toggle data-admin-notifications-toggle aria-controls="admin-notifications-modal" aria-expanded="false" aria-label="Notifications" aria-describedby="admin-notifications-help" title="Open notifications. Drag to move.">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M9 21h6"/></svg>
        <span>Notifications</span><b data-admin-notification-count hidden>0</b>
    </button>
    <span id="admin-notifications-help" class="sr-only">Tap to open notifications. Drag to move. Arrow keys move the focused badge; Home restores its position.</span>
    <div id="admin-notifications-modal" class="admin-notifications-modal" data-alert-center-panel data-incoming-reports data-feed-url="{{ route('monitoring.reports.feed') }}" data-user-id="{{ $authUser->id }}" data-alarm-url="{{ asset('sounds/admin-report-alarm-993.wav') }}" hidden>
        <div class="admin-notifications-sheet" role="dialog" aria-modal="true" aria-labelledby="admin-notifications-title">
            <header class="admin-notifications-heading"><h2 id="admin-notifications-title">Notifications</h2><button type="button" class="btn btn-secondary" data-alert-center-close>Close</button></header>
            <div class="admin-notification-tabs" role="tablist" aria-label="Notification type">
                <button type="button" role="tab" id="admin-reports-tab" data-notification-tab="reports" aria-controls="admin-notification-reports" aria-selected="true">Reports <b data-alert-count>0</b></button>
                @if($authUser->is_admin)<button type="button" role="tab" id="admin-updates-tab" data-notification-tab="updates" aria-controls="admin-notification-updates" aria-selected="false" tabindex="-1">Updates <b data-admin-update-count hidden>0</b></button>@endif
            </div>
            <section id="admin-notification-reports" role="tabpanel" aria-labelledby="admin-reports-tab" data-notification-panel="reports">
                <p class="section-copy">New online and LoRa reports appear here. Opening a notification marks it seen on this browser; it does not acknowledge or resolve the report.</p>
                <section class="incoming-alarm-toolbar" aria-label="Report alarm">
                    <div><strong>Report alarm</strong><small data-incoming-sound-status role="status">Your Sound on or Mute choice is remembered across navigation.</small></div>
                    <div class="incoming-alarm-actions">
                        <button type="button" class="pill-btn" data-incoming-sound>Test sound</button>
                        <button type="button" class="pill-btn" data-incoming-sound-mute>Mute sound</button>
                        <button type="button" class="pill-btn" data-incoming-sound-stop hidden>Stop alarm</button>
                    </div>
                </section>
                <div class="incoming-report-controls"><button type="button" class="btn btn-secondary" data-incoming-clear>Mark all seen</button><small data-incoming-status role="status">Checking new reports…</small></div>
                <p class="sr-only" role="status" aria-live="polite" data-incoming-unread-summary></p>
                <div class="alert-list" data-alert-list></div>
                <div class="button-row"><a class="btn btn-secondary" href="{{ route('reports.index') }}">All online reports</a><a class="btn btn-secondary" href="{{ route('lora-reports.index') }}">All LoRa reports</a></div>
            </section>
            @if($authUser->is_admin)
                <section id="admin-notification-updates" role="tabpanel" aria-labelledby="admin-updates-tab" data-notification-panel="updates" hidden>
                    <h3>App updates</h3>
                    <p class="section-copy">Check what's changed before updating. Save or finish any open review first. An app update reloads this page; it does not upload firmware to ESP32 nodes.</p>
                    <dl class="admin-update-versions"><dt>Running build</dt><dd data-admin-running-build>{{ $pwaAssetVersion }}</dd><dt>Latest server build</dt><dd data-admin-latest-build>Not checked yet</dd><dt>Previous build</dt><dd data-admin-previous-build>Not recorded</dd></dl>
                    <p data-admin-update-status role="status" aria-live="polite">Checking for updates…</p>
                    <div class="button-row"><button type="button" class="btn btn-secondary" data-admin-check-updates>Check again</button><button type="button" class="btn btn-primary" data-admin-apply-update hidden>Update app</button><button type="button" class="btn btn-secondary" data-admin-read-update hidden>Mark update seen</button></div>
                    <h3>What's new</h3><div class="civilian-release-list" data-admin-release-notes>@include('partials.admin-release-notes')</div>
                    <a class="btn btn-secondary" href="{{ route('admin.system-log') }}">Open System Log</a>
                </section>
            @endif
        </div>
    </div>
</section>
