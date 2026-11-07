<div class="civilian-tutorial" data-civilian-tutorial data-owner="{{ auth()->id() }}" data-auto="{{ $isAccountVerified ? 'false' : 'true' }}" data-verified="{{ $isAccountVerified ? 'true' : 'false' }}" data-nav-links="{{ json_encode(['drafts' => route('reports.drafts'), 'profile' => route('profile.show'), 'settings' => route('settings.index'), 'log' => route('civilian.system-log')]) }}" role="dialog" aria-modal="true" aria-labelledby="civilian-tutorial-title" aria-describedby="civilian-tutorial-copy" hidden>
    <div class="civilian-tutorial-blocker" aria-hidden="true"></div>
    <div class="civilian-tutorial-spotlight" data-tutorial-spotlight hidden aria-hidden="true"></div>
    <section class="civilian-tutorial-demo" data-tutorial-demo hidden aria-label="Report tutorial preview">
        <strong>Report tutorial preview</strong><p>No report is sent. Camera and location stay off.</p>
        <div class="civilian-tutorial-example" data-tutorial-example="incident" hidden>
            <div class="civilian-tutorial-incident-selector" data-tutorial-preview-target="incident" role="img" aria-label="Type of incident preview: Road Accident selected">
                <div><span>Type of incident</span><strong>Road Accident</strong><small>Tap to view incident types</small></div>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="m6 9 6 6 6-6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </div>
        </div>
        <div class="civilian-tutorial-example" data-tutorial-example="photo" hidden><strong>Capture Photo / Upload Photo</strong><span>Attach a clear scene photo.</span></div>
        <div class="civilian-tutorial-example" data-tutorial-example="gps" hidden><strong>Get GPS</strong><span>Allow location once when offered; keep phone Location on. Add a barangay, road or landmark.</span></div>
        <div class="civilian-tutorial-example" data-tutorial-example="description" hidden><strong>Short description</strong><span>Example: Motorcycle crash near the market; one injured person.</span></div>
        <div class="civilian-tutorial-example" data-tutorial-example="selfie" hidden><strong>Live selfie &amp; blink check</strong><span>A fresh check opens when you send a real report.</span></div>
        <div class="civilian-tutorial-example" data-tutorial-example="receipt" hidden><strong>Send Report → Server receipt</strong><span>Wait for confirmation that the server received your report.</span></div>
    </section>
    <section class="civilian-tutorial-callout" data-tutorial-callout>
        <p class="civilian-tutorial-progress" data-tutorial-progress role="status" aria-live="polite"></p>
        <h2 id="civilian-tutorial-title" data-tutorial-title>Quick app tour</h2>
        <p id="civilian-tutorial-copy" data-tutorial-copy></p>
        <div class="civilian-tutorial-controls"><button type="button" class="btn btn-secondary" data-tutorial-skip>Skip</button><button type="button" class="btn btn-secondary" data-tutorial-back>Back</button><button type="button" class="btn btn-primary" data-tutorial-next>Next</button></div>
        <a class="btn btn-primary" data-tutorial-verify href="{{ route('account.verify') }}" hidden>Verify Account</a>
    </section>
</div>
