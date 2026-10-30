<div class="civilian-directory-modal audit-history-modal" data-audit-history-modal data-history-url="{{ route('admin.report-audit') }}" data-print-url="{{ route('admin.report-audit.print') }}" data-initial-page="{{ $auditFilters['audit_page'] ?? 1 }}" @if (collect($auditFilters)->filter(fn ($value) => filled($value))->isNotEmpty()) data-audit-history-auto-open @endif hidden>
    <section class="civilian-directory-dialog audit-history-dialog" id="audit-history-dialog" role="dialog" aria-modal="true" aria-labelledby="audit-history-title" tabindex="-1">
        <header class="civilian-directory-head"><div><h2 id="audit-history-title">All admin report actions</h2><p>10 actions per page. Open a row for details or use checkboxes to print several. Dates use Philippines time.</p></div><button type="button" class="btn btn-secondary" data-audit-history-close aria-label="Close all admin actions">&times;</button></header>
        <form class="audit-history-filters" data-audit-history-filters>
            <div class="audit-history-search"><label for="audit-history-search">Find an action</label><input id="audit-history-search" type="search" class="input" name="audit_search" maxlength="100" placeholder="Report ID, admin, node, or note" value="{{ $auditFilters['audit_search'] ?? '' }}"></div>
            <div><label for="audit-history-source">Source</label><select id="audit-history-source" class="input" name="audit_source"><option value="">Online and LoRa</option><option value="online" @selected(($auditFilters['audit_source'] ?? '') === 'online')>Online</option><option value="lora" @selected(($auditFilters['audit_source'] ?? '') === 'lora')>LoRa / Nodes</option></select></div>
            <div><label for="audit-history-action">Action</label><select id="audit-history-action" class="input" name="audit_action"><option value="">All admin actions</option>@foreach (['coordination' => 'Response / status', 'severity_review' => 'Severity review', 'ai_analysis' => 'AI analysis requested', 'deletion' => 'Deleted report'] as $value => $label)<option value="{{ $value }}" @selected(($auditFilters['audit_action'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></div>
            <div><label for="audit-history-from">From date</label><input id="audit-history-from" class="input" type="date" name="audit_from" value="{{ $auditFilters['audit_from'] ?? '' }}"></div>
            <div><label for="audit-history-to">To date</label><input id="audit-history-to" class="input" type="date" name="audit_to" value="{{ $auditFilters['audit_to'] ?? '' }}"></div>
            <div><label for="audit-history-admin">Admin name</label><input id="audit-history-admin" class="input" type="search" name="audit_admin" maxlength="100" placeholder="Any admin" value="{{ $auditFilters['audit_admin'] ?? '' }}"></div>
            <div class="audit-history-filter-actions"><button class="btn btn-primary" type="submit">Apply</button><button class="btn btn-secondary" type="button" data-audit-history-reset>Reset</button></div>
        </form>
        <div class="audit-history-printbar">
            <label><input type="checkbox" data-audit-history-select-page disabled> Select this page</label>
            <span data-audit-history-selected role="status" aria-live="polite">0 selected</span>
            <div><a class="btn btn-secondary" data-audit-history-print-selected target="_blank" rel="noopener" aria-disabled="true">Print selected</a><a class="btn btn-secondary" data-audit-history-print-all target="_blank" rel="noopener" aria-disabled="true">Print all matching filters</a></div>
        </div>
        <div class="civilian-directory-scroll audit-history-scroll" data-audit-history-scroll>
            <p class="civilian-directory-message" data-audit-history-message role="status" aria-live="polite"></p><button type="button" class="btn btn-secondary" data-audit-history-retry hidden>Try again</button>
            <div class="command-table-wrap"><table class="command-audit-table"><thead><tr><th>Action</th><th>Incident</th><th>Source</th><th>Status</th><th>Actor</th><th>Time</th></tr></thead><tbody data-audit-history-rows></tbody></table></div>
        </div>
        <footer class="civilian-directory-footer"><p data-audit-history-count role="status" aria-live="polite">Loading actions...</p><nav aria-label="All admin actions pagination"><button type="button" class="btn btn-secondary" data-audit-history-previous disabled>&larr; Previous</button><span data-audit-history-page>Page 1</span><button type="button" class="btn btn-secondary" data-audit-history-next disabled>Next &rarr;</button></nav></footer>
    </section>
</div>
