import { onDomReady } from './dom-ready';

onDomReady(() => {
    const modal = document.querySelector('[data-civilian-reports-modal]');
    if (!modal) return;
    const field = name => modal.querySelector(`[data-civilian-reports-${name}]`);
    const list = field('list'), message = field('message'), retry = field('retry');
    const previous = field('previous'), next = field('next'), printAll = field('print-all');
    let endpoint, controller, detailController;
    let page = 1, lastPage = 1, pendingPage = 1, loading = false;

    const element = (tag, className, text = '') => {
        const node = document.createElement(tag);
        node.className = className;
        node.textContent = text;
        return node;
    };
    const openDetail = async (report, trigger) => {
        detailController?.abort();
        const request = new AbortController();
        detailController = request;
        trigger.disabled = true;
        message.hidden = false;
        message.textContent = 'Opening report...';
        const timeout = window.setTimeout(() => request.abort(), 15000);
        try {
            const response = await fetch(report.detailsUrl, { signal: request.signal, cache: 'no-store', headers: { Accept: 'application/json' } });
            if (!response.ok || response.redirected) throw new Error('Report unavailable');
            const payload = await response.json();
            if (!payload.html || !payload.title) throw new Error('Invalid report');
            if (detailController !== request || modal.hidden) return;
            message.hidden = true;
            document.dispatchEvent(new CustomEvent('stitch:report-open', { detail: { ...payload, opener: trigger } }));
        } catch {
            if (detailController === request && !modal.hidden) message.textContent = 'Unable to open this report. Select it to try again, or refresh the list if it was removed.';
        } finally {
            window.clearTimeout(timeout);
            trigger.disabled = false;
        }
    };
    const record = report => {
        const row = element('li', 'civilian-reports-item');
        const button = element('button', 'civilian-report-open');
        button.type = 'button';
        button.dataset.civilianReportId = report.id;
        button.setAttribute('aria-label', `View ${report.code}`);
        const identity = element('span', 'civilian-report-identity');
        identity.append(element('strong', '', report.code), element('span', '', report.type), element('small', '', report.submittedAt));
        const badges = element('span', 'civilian-report-badges');
        const tone = { Critical: 'red', Serious: 'amber', Minor: 'green' }[report.severity] || 'blue';
        badges.append(element('span', `tag ${tone}`, report.severity), element('span', `tag ${report.statusTone}`, report.status));
        button.append(identity, badges);
        button.addEventListener('click', () => void openDetail(report, button));
        const print = element('a', 'btn btn-secondary', 'Print');
        print.href = report.printUrl;
        print.target = '_blank';
        print.rel = 'noopener';
        print.setAttribute('aria-label', `Print ${report.code}`);
        row.append(button, print);
        return row;
    };
    const controls = () => {
        previous.disabled = loading || page <= 1;
        next.disabled = loading || page >= lastPage;
        list.setAttribute('aria-busy', String(loading));
    };
    const load = async requestedPage => {
        controller?.abort(); detailController?.abort(); detailController = null;
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
            if (controller !== request || modal.hidden) return false;
            if (payload.pagination.page > payload.pagination.lastPage) return await load(payload.pagination.lastPage);
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
            return true;
        } catch {
            if (controller !== request || modal.hidden) return false;
            message.textContent = 'Unable to load reports. Please try again.';
            field('count').textContent = list.children.length ? 'Showing the last loaded page.' : 'Reports unavailable';
            retry.hidden = false;
            return false;
        } finally {
            window.clearTimeout(timeout);
            if (controller === request) {
                loading = false;
                controls();
                if (!modal.hidden && !modal.contains(document.activeElement)) field('close').focus({ preventScroll: true });
            }
        }
    };
    const close = () => {
        if (modal.hidden) return;
        controller?.abort(); controller = null;
        detailController?.abort(); detailController = null;
        document.dispatchEvent(new CustomEvent('stitch:dialog-close', { detail: { modal } }));
        list.replaceChildren();
    };
    const open = (url, opener) => {
        endpoint = url;
        page = lastPage = 1;
        list.replaceChildren();
        field('account').textContent = 'Loading account...';
        field('count').textContent = 'Loading reports...';
        field('page').textContent = 'Page 1';
        printAll.hidden = true;
        document.dispatchEvent(new CustomEvent('stitch:dialog-open', { detail: { modal, close, opener } }));
        return load(1);
    };
    document.addEventListener('click', event => {
        const trigger = event.target.closest?.('[data-civilian-reports-url]');
        if (!trigger) return;
        event.preventDefault();
        void open(trigger.dataset.civilianReportsUrl, trigger);
    });
    field('close').addEventListener('click', close);
    modal.addEventListener('click', event => { if (event.target === modal) close(); });
    previous.addEventListener('click', () => { if (!loading && page > 1) void load(page - 1); });
    next.addEventListener('click', () => { if (!loading && page < lastPage) void load(page + 1); });
    retry.addEventListener('click', () => void load(pendingPage));
    const review = document.querySelector('[data-civilian-report-review-on-load]');
    if (review) window.setTimeout(async () => {
        const url = review.dataset.reportsUrl;
        const opener = [...document.querySelectorAll('[data-civilian-reports-url]')].find(node => node.dataset.civilianReportsUrl === url)
            || document.querySelector('[data-civilian-directory-open]');
        if (!await open(url, opener) || modal.hidden) return;
        document.dispatchEvent(new CustomEvent('stitch:report-open', { detail: {
            html: review.innerHTML, title: review.dataset.reportTitle,
            opener: list.querySelector(`[data-civilian-report-id="${review.dataset.reportId}"]`) || field('close'),
        } }));
    }, 120);
});
