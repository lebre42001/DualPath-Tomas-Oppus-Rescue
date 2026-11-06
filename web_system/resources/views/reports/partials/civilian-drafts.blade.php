<section id="saved-report-drafts" class="panel civilian-drafts-panel" data-civilian-drafts
    data-owner="{{ auth()->id() }}" data-create-url="{{ route('reports.create') }}"
    data-receipt-url="{{ route('reports.submission', '__submission__') }}">
    <div class="panel-head">
        <div class="panel-heading">
            <p class="panel-kicker">Saved on this device</p>
            <h2 class="panel-title">Draft Reports <span class="tag amber" data-civilian-draft-count>0</span></h2>
            <p class="section-copy">Only drafts for your signed-in account on this device appear here. These reports are not confirmed as received. Reconnect to continue, lock GPS, and complete a fresh live selfie check before sending.</p>
        </div>
        <button type="button" class="btn btn-secondary" data-civilian-library-open="drafts" aria-haspopup="dialog">View All</button>
    </div>
    <p class="section-copy" data-civilian-draft-preview>Latest saved drafts. View All to search and browse the complete list.</p>
    <div class="civilian-draft-list" data-civilian-draft-list></div>
    <div class="civilian-draft-empty" data-civilian-draft-empty hidden>
        <strong>No draft reports</strong>
        <p>Unfinished or interrupted reports saved on this device will appear here. A confirmed report moves to Report Status.</p>
        <a class="btn btn-primary" href="{{ route('reports.create') }}">Start a report</a>
    </div>
    <noscript>Enable JavaScript to view the drafts saved on this device.</noscript>
    <p class="section-copy" data-civilian-draft-notice role="status" aria-live="polite"></p>
</section>
