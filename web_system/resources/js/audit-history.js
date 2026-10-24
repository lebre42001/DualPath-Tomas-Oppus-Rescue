import { onDomReady } from './dom-ready';

onDomReady(() => {
    const modal = document.querySelector('[data-audit-history-modal]');
    const opener = document.querySelector('[data-audit-history-open]');
    if (!modal || !opener) return;
    const field = name => modal.querySelector(`[data-audit-history-${name}]`);
    const form = field('filters'), rows = field('rows'), message = field('message'), retry = field('retry');
    const previous = field('previous'), next = field('next');
    let applied = Object.fromEntries(new FormData(form));
    let page = 1, lastPage = 1, pendingPage = 1, loading = false, controller;
    const controls = () => {
        previous.disabled = loading || page <= 1;
        next.disabled = loading || page >= lastPage;
        rows.setAttribute('aria-busy', String(loading));
    };
    const load = async requestedPage => {
        controller?.abort();
        const request = new AbortController(); controller = request;
        pendingPage = requestedPage; loading = true; controls();
        message.hidden = false; message.textContent = 'Loading actions...'; retry.hidden = true;
        const timeout = window.setTimeout(() => request.abort(), 15000);
        try {
            const url = new URL(modal.dataset.historyUrl, location.href);
            Object.entries(applied).forEach(([key, value]) => url.searchParams.set(key, value.trim()));
            url.searchParams.set('audit_page', requestedPage);
            const response = await fetch(url, { signal: request.signal, cache: 'no-store', headers: { Accept: 'application/json' } });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok || response.redirected) throw new Error(Object.values(payload.errors || {}).flat()[0] || 'Unable to load actions. Please sign in again or retry.');
            if (typeof payload.html !== 'string' || !payload.pagination) throw new Error('Unable to load actions. Please try again.');
            if (controller !== request || modal.hidden) return;
            if (payload.pagination.page > payload.pagination.lastPage) { void load(payload.pagination.lastPage); return; }
            page = payload.pagination.page; lastPage = payload.pagination.lastPage;
            rows.innerHTML = payload.html;
            message.hidden = true;
            field('count').textContent = payload.pagination.total ? `${payload.pagination.from}-${payload.pagination.to} of ${payload.pagination.total} actions` : '0 actions';
            field('page').textContent = `Page ${page} of ${lastPage}`;
            field('scroll').scrollTop = 0;
        } catch (error) {
            if (controller !== request || modal.hidden) return;
            message.textContent = error.name === 'AbortError' ? 'Loading timed out. Please try again.' : error.message;
            field('count').textContent = rows.children.length ? 'Showing the last loaded page.' : 'Actions unavailable';
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
        document.dispatchEvent(new CustomEvent('stitch:dialog-close', { detail: { modal } }));
        rows.replaceChildren();
    };
    const open = initialPage => {
        page = lastPage = 1;
        field('count').textContent = 'Loading actions...'; field('page').textContent = 'Page 1';
        document.dispatchEvent(new CustomEvent('stitch:dialog-open', { detail: { modal, close, opener } }));
        void load(initialPage);
    };
    opener.addEventListener('click', () => open(1));
    field('close').addEventListener('click', close);
    modal.addEventListener('click', event => { if (event.target === modal) close(); });
    form.addEventListener('submit', event => { event.preventDefault(); applied = Object.fromEntries(new FormData(form)); void load(1); });
    field('reset').addEventListener('click', () => {
        form.querySelectorAll('input, select').forEach(input => { input.value = ''; });
        applied = Object.fromEntries(new FormData(form)); void load(1);
    });
    previous.addEventListener('click', () => { if (!loading && page > 1) void load(page - 1); });
    next.addEventListener('click', () => { if (!loading && page < lastPage) void load(page + 1); });
    retry.addEventListener('click', () => void load(pendingPage));
    if (modal.hasAttribute('data-audit-history-auto-open')) window.setTimeout(() => open(Number(modal.dataset.initialPage) || 1), 120);
});
