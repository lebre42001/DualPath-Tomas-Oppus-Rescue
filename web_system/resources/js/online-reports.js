import { onDomReady } from './dom-ready';

onDomReady(() => {
    const modal = document.querySelector('[data-online-reports-modal]');
    const opener = document.querySelector('[data-online-reports-open]');
    if (!modal || !opener) return;
    const field = name => modal.querySelector(`[data-online-reports-${name}]`);
    const list = field('list'), message = field('message'), retry = field('retry');
    const previous = field('previous'), next = field('next');
    const form = field('search');
    let baseUrl = new URL(modal.dataset.reportsUrl, location.href);
    let appliedFilters = Object.fromEntries(new FormData(form));
    let page = 1, lastPage = 1, pendingPage = 1, loading = false;
    let controller, detailController;
    const element = (tag, className, text) => {
        const node = document.createElement(tag);
        node.className = className;
        node.textContent = text;
        return node;
    };
    const openDetail = async (url, trigger) => {
        detailController?.abort();
        const request = new AbortController();
        detailController = request;
        trigger.disabled = true;
        message.hidden = false;
        message.textContent = 'Opening report...';
        const timeout = window.setTimeout(() => request.abort(), 15000);
        try {
            const response = await fetch(url, { signal: request.signal, cache: 'no-store', headers: { Accept: 'application/json' } });
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
    const row = report => {
        const li = document.createElement('li');
        const button = element('button', 'online-reports-row', '');
        button.type = 'button';
        button.setAttribute('aria-label', `View ${report.code} from ${report.name}`);
        const category = element('span', 'online-reports-category', '');
        const tone = { Critical: 'red', Serious: 'amber', Minor: 'green' }[report.severity] || 'blue';
        category.append(element('span', `tag ${tone}`, report.severity), element('small', '', report.analysis));
        button.append(element('strong', '', report.name), element('span', 'online-reports-code', report.code), category, element('time', '', report.date));
        button.addEventListener('click', () => void openDetail(report.detailsUrl, button));
        li.append(button);
        return li;
    };
    const controls = () => {
        previous.disabled = loading || page <= 1;
        next.disabled = loading || page >= lastPage;
        list.setAttribute('aria-busy', String(loading));
    };
    const load = async requestedPage => {
        controller?.abort(); detailController?.abort(); detailController = null;
        const request = new AbortController(); controller = request;
        pendingPage = requestedPage; loading = true; controls();
        message.hidden = false; message.textContent = 'Loading reports...'; retry.hidden = true;
        const timeout = window.setTimeout(() => request.abort(), 15000);
        try {
            const url = new URL(baseUrl);
            url.searchParams.set('page', requestedPage);
            Object.entries(appliedFilters).forEach(([name, value]) => url.searchParams.set(name, value.trim()));
            const response = await fetch(url, { signal: request.signal, cache: 'no-store', headers: { Accept: 'application/json' } });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok || response.redirected) throw new Error(Object.values(payload.errors || {}).flat()[0] || 'Unable to load reports. Please sign in again or retry.');
            if (!Array.isArray(payload.reports) || !payload.pagination) throw new Error('Unable to load reports. Please try again.');
            if (controller !== request || modal.hidden) return;
            if (payload.pagination.page > payload.pagination.lastPage) { void load(payload.pagination.lastPage); return; }
            page = payload.pagination.page; lastPage = payload.pagination.lastPage;
            list.replaceChildren(...payload.reports.map(row));
            message.hidden = payload.reports.length > 0;
            message.textContent = 'No online reports matched your search and filters.';
            field('count').textContent = payload.pagination.total ? `${payload.pagination.from}-${payload.pagination.to} of ${payload.pagination.total} reports` : '0 reports';
            field('page').textContent = `Page ${page} of ${lastPage}`;
            field('scroll').scrollTop = 0;
        } catch (error) {
            if (controller !== request || modal.hidden) return;
            message.textContent = error.name === 'AbortError' ? 'Loading timed out. Please try again.' : (error.message || 'Unable to load reports. Please try again.');
            field('count').textContent = list.children.length ? 'Showing the last loaded page.' : 'Reports unavailable';
            retry.hidden = false;
        } finally {
            window.clearTimeout(timeout);
            if (controller === request) {
                loading = false; controls();
                if (!modal.hidden && !modal.contains(document.activeElement)) field('close').focus({ preventScroll: true });
            }
        }
    };
    const close = () => {
        controller?.abort(); controller = null;
        detailController?.abort(); detailController = null;
        document.dispatchEvent(new CustomEvent('stitch:dialog-close', { detail: { modal } }));
        list.replaceChildren();
    };
    opener.addEventListener('click', () => {
        page = lastPage = 1;
        field('count').textContent = 'Loading reports...'; field('page').textContent = 'Page 1';
        document.dispatchEvent(new CustomEvent('stitch:dialog-open', { detail: { modal, close, opener } }));
        void load(1);
    });
    field('close').addEventListener('click', close);
    modal.addEventListener('click', event => { if (event.target === modal) close(); });
    form.addEventListener('submit', event => { event.preventDefault(); appliedFilters = Object.fromEntries(new FormData(form)); void load(1); });
    field('reset').addEventListener('click', () => {
        form.querySelectorAll('input, select').forEach(input => { input.value = ''; });
        baseUrl.search = '';
        appliedFilters = Object.fromEntries(new FormData(form));
        void load(1);
    });
    previous.addEventListener('click', () => { if (!loading && page > 1) void load(page - 1); });
    next.addEventListener('click', () => { if (!loading && page < lastPage) void load(page + 1); });
    retry.addEventListener('click', () => void load(pendingPage));
    const review = document.querySelector('[data-online-review-on-load]');
    if (review) window.setTimeout(() => document.dispatchEvent(new CustomEvent('stitch:report-open', {
        detail: { html: review.innerHTML, title: review.dataset.reportTitle, opener },
    })), 120);
});
