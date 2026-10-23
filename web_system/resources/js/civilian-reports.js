import { onDomReady } from './dom-ready';

onDomReady(() => {
    const modal = document.querySelector('[data-civilian-reports-modal]');
    if (!modal) return;
    const dialog = modal.querySelector('[role="dialog"]');
    const field = name => modal.querySelector(`[data-civilian-reports-${name}]`);
    const list = field('list'), message = field('message'), retry = field('retry');
    const previous = field('previous'), next = field('next'), printAll = field('print-all');
    let opener, parentDialog, endpoint, controller, savedOverflow;
    let page = 1, lastPage = 1, pendingPage = 1, loading = false;

    const element = (tag, className, text = '') => {
        const node = document.createElement(tag);
        node.className = className;
        node.textContent = text;
        return node;
    };
    const record = report => {
        const row = element('li', 'civilian-reports-item');
        const details = element('details', 'civilian-report-details');
        const summary = element('summary', '');
        const identity = element('span', 'civilian-report-identity');
        identity.append(element('strong', '', report.code), element('span', '', report.type), element('small', '', report.submittedAt));
        summary.append(identity, element('span', `tag ${report.statusTone}`, report.status));
        const body = element('dl', 'civilian-report-fields');
        const add = (label, value) => {
            if (!value) return;
            const pair = element('div', '');
            pair.append(element('dt', '', label), element('dd', '', value));
            body.append(pair);
        };
        add('Severity', `${report.severity}${report.reviewRequired ? ' (responder review required)' : ''}`);
        add('Location', report.location);
        add('GPS coordinates', report.coordinates);
        add('Description', report.description);
        if (report.reportedFatality) add('Reported fatality', 'Reported by sender; requires verification.');
        add('Assigned responder', report.responder);
        add('Response notes', report.responseNotes);
        add('Resolved', report.resolvedAt);
        details.append(summary, body);
        const print = element('a', 'btn btn-secondary', 'Print');
        print.href = report.printUrl;
        print.target = '_blank';
        print.rel = 'noopener';
        print.setAttribute('aria-label', `Print ${report.code}`);
        row.append(details, print);
        return row;
    };
    const controls = () => {
        previous.disabled = loading || page <= 1;
        next.disabled = loading || page >= lastPage;
        list.setAttribute('aria-busy', String(loading));
    };
    const load = async requestedPage => {
        controller?.abort();
        const request = new AbortController();
        controller = request;
        pendingPage = requestedPage;
        loading = true;
        controls();
        retry.hidden = true;
        message.hidden = false;
        message.textContent = 'Loading reports...';
        const timeout = window.setTimeout(() => request.abort(), 15000);
        try {
            const url = new URL(endpoint, location.href);
            url.searchParams.set('page', requestedPage);
            const response = await fetch(url, { signal: request.signal, cache: 'no-store', headers: { Accept: 'application/json' } });
            if (!response.ok || response.redirected) throw new Error('Reports unavailable');
            const payload = await response.json();
            if (!Array.isArray(payload.reports) || !payload.pagination || !payload.account) throw new Error('Invalid reports');
            if (controller !== request || modal.hidden) return;
            if (payload.pagination.page > payload.pagination.lastPage) { void load(payload.pagination.lastPage); return; }
            page = payload.pagination.page;
            lastPage = payload.pagination.lastPage;
            field('account').textContent = `${payload.account.name} · ${payload.account.email}`;
            list.replaceChildren(...payload.reports.map(record));
            message.hidden = payload.reports.length > 0;
            message.textContent = 'This civilian has not submitted any reports.';
            printAll.href = payload.printAllUrl;
            printAll.hidden = payload.pagination.total === 0;
            field('count').textContent = payload.pagination.total ? `${payload.pagination.from}-${payload.pagination.to} of ${payload.pagination.total} reports` : '0 reports';
            field('page').textContent = `Page ${page} of ${lastPage}`;
            field('scroll').scrollTop = 0;
        } catch {
            if (controller !== request || modal.hidden) return;
            message.textContent = 'Unable to load reports. Please try again.';
            field('count').textContent = list.children.length ? 'Showing the last loaded page.' : 'Reports unavailable';
            retry.hidden = false;
        } finally {
            window.clearTimeout(timeout);
            if (controller === request) {
                loading = false;
                controls();
                if (!modal.hidden && !dialog.contains(document.activeElement)) field('close').focus({ preventScroll: true });
            }
        }
    };
    const close = () => {
        if (modal.hidden) return;
        controller?.abort();
        controller = null;
        modal.hidden = true;
        list.replaceChildren();
        if (parentDialog) parentDialog.inert = false;
        document.body.style.overflow = savedOverflow;
        if (opener?.isConnected) opener.focus({ preventScroll: true });
    };
    document.addEventListener('click', event => {
        const trigger = event.target.closest?.('[data-civilian-reports-url]');
        if (!trigger) return;
        event.preventDefault();
        opener = trigger;
        parentDialog = trigger.closest('[role="dialog"]');
        if (parentDialog) parentDialog.inert = true;
        savedOverflow = document.body.style.overflow;
        endpoint = trigger.dataset.civilianReportsUrl;
        page = lastPage = 1;
        list.replaceChildren();
        field('account').textContent = 'Loading account...';
        field('count').textContent = 'Loading reports...';
        field('page').textContent = 'Page 1';
        printAll.hidden = true;
        modal.hidden = false;
        document.body.style.overflow = 'hidden';
        field('close').focus({ preventScroll: true });
        void load(1);
    });
    field('close').addEventListener('click', close);
    modal.addEventListener('click', event => { if (event.target === modal) close(); });
    previous.addEventListener('click', () => { if (!loading && page > 1) void load(page - 1); });
    next.addEventListener('click', () => { if (!loading && page < lastPage) void load(page + 1); });
    retry.addEventListener('click', () => void load(pendingPage));
    // Handle only the top dialog so Escape/Tab preserve an underlying account/list modal.
    document.addEventListener('keydown', event => {
        if (modal.hidden || !['Escape', 'Tab'].includes(event.key)) return;
        event.stopImmediatePropagation();
        if (event.key === 'Escape') { event.preventDefault(); close(); return; }
        const targets = [...dialog.querySelectorAll('button:not(:disabled), a[href], summary, [tabindex="0"]')].filter(node => node.getClientRects().length);
        const first = targets[0], last = targets.at(-1);
        if (event.shiftKey && (document.activeElement === first || !dialog.contains(document.activeElement))) {
            event.preventDefault(); last?.focus();
        } else if (!event.shiftKey && (document.activeElement === last || !dialog.contains(document.activeElement))) {
            event.preventDefault(); first?.focus();
        }
    }, true);
});
