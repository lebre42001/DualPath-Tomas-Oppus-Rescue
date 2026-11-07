<section data-civilian-updates data-owner="{{ $authUser->id }}" data-running-version="{{ $pwaAssetVersion }}"
    data-feed-url="{{ route('civilian.system-updates') }}" data-read-url="{{ route('civilian.system-updates.read') }}"
    data-event-url="{{ route('civilian.app-events') }}">
    <button type="button" class="civilian-updates-toggle" data-civilian-updates-toggle data-connection-state="checking" aria-label="Report and app updates" aria-describedby="civilian-updates-position-help" aria-controls="civilian-updates-modal" aria-expanded="false" title="Tap to open updates. Drag to move.">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M9 21h6"/></svg>
        <span>Updates</span><b data-civilian-update-count hidden>0</b>
    </button>
    <span id="civilian-updates-position-help" class="sr-only">Tap or press Enter to open updates. Drag to move the badge. With the badge focused, arrow keys move it and Home restores its default position.</span>
    <div class="sr-only" role="status" aria-live="polite" aria-atomic="true" data-civilian-report-notice-announcement></div>
    <aside class="civilian-report-notice" data-civilian-report-notice aria-label="Report notification" hidden>
        <div>
            <p class="civilian-report-notice-count" data-civilian-report-notice-count></p>
            <strong data-civilian-report-notice-title></strong>
            <p data-civilian-report-notice-message></p>
            <p class="civilian-report-notice-date" data-civilian-report-notice-date></p>
        </div>
        <div class="civilian-report-notice-actions">
            <button type="button" class="btn btn-primary" data-civilian-report-notice-open>View updates</button>
            <button type="button" class="btn btn-secondary" data-civilian-report-notice-dismiss>Dismiss</button>
        </div>
    </aside>
    <div id="civilian-updates-modal" class="civilian-updates-modal" data-civilian-updates-modal hidden>
        <div class="civilian-updates-sheet" role="dialog" aria-modal="true" aria-labelledby="civilian-updates-heading">
            <header class="civilian-updates-heading"><h2 id="civilian-updates-heading">Report and app updates</h2><button type="button" class="btn btn-secondary" data-civilian-updates-close>Close</button></header>
            <section aria-labelledby="civilian-unread-updates-heading">
                <h3 id="civilian-unread-updates-heading">Unread report and app updates</h3>
                <p class="section-copy">Report notifications appear while the app is open and connected, or when you return. Dismissing a notice does not mark it as read.</p>
                <p class="civilian-alerts-off" data-civilian-report-alerts-off hidden>Report status alerts are off. <a href="{{ route('settings.index') }}">Enable them in Settings.</a> Your unread updates and System Log are still available.</p>
                <div class="civilian-update-list" data-civilian-update-list></div>
                <button type="button" class="btn btn-secondary" data-civilian-updates-read hidden>Mark shown updates as read</button>
                <a class="btn btn-secondary civilian-updates-log-link" href="{{ route('civilian.system-log') }}">Open System Log</a>
            </section>
            <section class="civilian-release-section" aria-labelledby="civilian-release-heading">
                <h3 id="civilian-release-heading">What's new · Mga giusab</h3>
                <p class="section-copy">Read what changed in each release. Check again refreshes these notes from the server; marking notices as read keeps these notes available.</p>
                <div class="civilian-release-list" data-civilian-release-notes>@include('partials.civilian-release-notes')</div>
            </section>
            <aside class="civilian-report-connection" data-report-connection data-connection-state="checking" data-connection-url="{{ route('reports.connection') }}">
                <div role="status" aria-live="polite" aria-atomic="true">
                    <strong data-report-connection-title>Checking online reporting</strong>
                    <p data-report-connection-message>Availability is not confirmed yet. You can prepare or save a draft while checking.</p>
                    <p class="civilian-connection-result" data-report-connection-result>No completed check yet.</p>
                </div>
                <button class="btn btn-secondary" type="button" data-report-connection-retry aria-describedby="report-connection-help">Check again</button>
                <p id="report-connection-help" class="civilian-connection-help">Check again tests access to the reporting server. Use Send Report to send your report.</p>
            </aside>
            <section class="civilian-version-card">
                <h3>App version</h3>
                <dl><dt>Running build</dt><dd data-app-running>{{ $pwaAssetVersion }}</dd><dt>Latest server build</dt><dd data-app-latest>Not checked yet</dd><dt>Previous build on this device</dt><dd data-app-previous>Not recorded</dd></dl>
                <p data-app-update-status role="status" aria-live="polite">Checking for app and report updates…</p>
                <button type="button" class="btn btn-primary" data-civilian-apply-update hidden>Update app</button>
                <p class="section-copy">Update app saves an unfinished report before reloading. It does not send the report.</p>
            </section>
        </div>
    </div>
</section>
