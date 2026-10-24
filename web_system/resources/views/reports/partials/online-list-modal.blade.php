<div class="civilian-directory-modal online-reports-list-modal" data-online-reports-modal data-reports-url="{{ route('reports.all', array_filter($filters, fn ($value) => $value !== '')) }}" hidden>
    <section class="civilian-directory-dialog" role="dialog" aria-modal="true" aria-labelledby="online-reports-title" tabindex="-1">
        <header class="civilian-directory-head"><div><h2 id="online-reports-title">All online reports</h2><p>Filter reports, then select one for details. Dates use Philippines time.</p></div><button type="button" class="btn btn-secondary" data-online-reports-close aria-label="Close online reports">&times;</button></header>
        <form class="online-reports-search" data-online-reports-search>
            <div class="online-reports-filter-field online-reports-search-field"><label for="online-reports-search">Find a report</label><input id="online-reports-search" class="input" type="search" maxlength="180" placeholder="Name, report ID, or location" value="{{ $filters['search'] }}" name="search"></div>
            <div class="online-reports-filter-field"><label for="online-reports-severity">Severity</label><select id="online-reports-severity" name="severity" class="input"><option value="">All severities</option>@foreach (['Minor', 'Serious', 'Critical', 'Needs review', 'Unassessed'] as $severity)<option value="{{ $severity }}" @selected($filters['severity'] === $severity)>{{ $severity }}</option>@endforeach</select></div>
            <div class="online-reports-filter-field"><label for="online-reports-from">From date</label><input id="online-reports-from" class="input" type="date" name="date_from"></div>
            <div class="online-reports-filter-field"><label for="online-reports-to">To date</label><input id="online-reports-to" class="input" type="date" name="date_to"></div>
            <div class="online-reports-filter-actions"><button class="btn btn-secondary" type="submit">Apply</button><button class="btn btn-secondary" type="button" data-online-reports-reset>Reset</button></div>
        </form>
        <div class="civilian-directory-scroll" data-online-reports-scroll>
            <p class="civilian-directory-message" data-online-reports-message role="status" aria-live="polite"></p><button type="button" class="btn btn-secondary" data-online-reports-retry hidden>Try again</button>
            <div class="online-reports-columns" aria-hidden="true"><span>Reporter</span><span>Report ID</span><span>Severity / AI analysis</span><span>Submitted</span></div>
            <ul class="online-reports-list" data-online-reports-list aria-label="Online reports"></ul>
        </div>
        <footer class="civilian-directory-footer"><p data-online-reports-count role="status" aria-live="polite">Loading reports...</p><nav aria-label="All online reports pagination"><button type="button" class="btn btn-secondary" data-online-reports-previous disabled>&larr; Previous</button><span data-online-reports-page>Page 1</span><button type="button" class="btn btn-secondary" data-online-reports-next disabled>Next &rarr;</button></nav></footer>
    </section>
</div>
