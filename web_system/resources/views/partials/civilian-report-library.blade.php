<section data-civilian-report-library data-owner="{{ $authUser->id }}" data-reports-url="{{ route('reports.civilian-collection') }}" data-create-url="{{ route('reports.create') }}">
    <div class="civilian-library-modal" data-library-modal hidden>
        <section class="civilian-library-sheet" role="dialog" aria-modal="true" aria-labelledby="civilian-library-title">
            <header class="civilian-library-heading"><div><h2 id="civilian-library-title" data-library-title>Report History</h2><p data-library-help></p></div><button type="button" class="btn btn-secondary" data-library-close>Close</button></header>
            <form class="civilian-library-filters" data-library-filters>
                <div class="field"><label for="library-search">Search</label><input class="input" id="library-search" name="search" maxlength="180" placeholder="Reference, description or location"></div>
                <div class="field"><label for="library-status">Status</label><select id="library-status" name="status" data-library-status></select></div>
                <div class="field"><label for="library-from">Submitted / saved from</label><input class="input" type="date" id="library-from" name="from"></div>
                <div class="field"><label for="library-to">Submitted / saved to</label><input class="input" type="date" id="library-to" name="to"></div>
                <div class="field"><label for="library-sort">Sort</label><select id="library-sort" name="sort"><option value="newest">Newest first</option><option value="oldest">Oldest first</option></select></div>
                <div class="civilian-library-actions"><button type="submit" class="btn btn-primary">Search</button><button type="button" class="btn btn-secondary" data-library-reset>Reset</button></div>
            </form>
            <p class="section-copy" data-library-message role="status" aria-live="polite"></p><button type="button" class="btn btn-secondary" data-library-retry hidden>Try again</button>
            <div class="civilian-library-table-wrap" tabindex="0" role="region" aria-label="Reports table; scroll horizontally for all columns" data-library-scroll>
                <table class="civilian-library-table"><caption class="sr-only" data-library-caption>Reports for your account</caption><thead><tr><th scope="col">Reference</th><th scope="col">Location</th><th scope="col">Status</th><th scope="col" data-library-date-heading>Submitted</th><th scope="col">Actions</th></tr></thead><tbody data-library-rows></tbody></table>
            </div>
            <nav class="civilian-library-pagination" aria-label="Reports pages"><p data-library-count role="status" aria-live="polite"></p><div class="civilian-library-page-buttons"><button type="button" class="btn btn-secondary" data-library-previous>Previous</button><span data-library-pages></span><button type="button" class="btn btn-secondary" data-library-next>Next</button></div><span data-library-page-label></span></nav>
        </section>
    </div>
    <div class="civilian-library-modal" data-library-detail hidden>
        <section class="civilian-library-sheet civilian-library-detail-sheet" role="dialog" aria-modal="true" aria-labelledby="civilian-library-detail-title">
            <header class="civilian-library-heading"><h2 id="civilian-library-detail-title" data-library-detail-title>Report details</h2><button type="button" class="btn btn-secondary" data-library-detail-close>Back</button></header>
            <div data-library-detail-body></div><p data-library-detail-message role="status" aria-live="polite"></p>
        </section>
    </div>
</section>
