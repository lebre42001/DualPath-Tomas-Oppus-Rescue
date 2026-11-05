<section id="saved-report-drafts" class="panel civilian-drafts-panel" data-civilian-drafts
    data-owner="{{ auth()->id() }}" data-create-url="{{ route('reports.create') }}"
    data-receipt-url="{{ route('reports.submission', '__submission__') }}" hidden>
    <div class="panel-head">
        <div class="panel-heading">
            <p class="panel-kicker">Saved on this device</p>
            <h2 class="panel-title">Report drafts <span class="tag amber" data-civilian-draft-count>0</span></h2>
            <p class="section-copy">These reports are not confirmed as received. Continue when your connection is ready.</p>
        </div>
    </div>
    <div class="civilian-draft-list" data-civilian-draft-list></div>
    <p class="section-copy" data-civilian-draft-notice role="status" aria-live="polite"></p>
</section>
