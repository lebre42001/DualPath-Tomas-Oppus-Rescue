import { onDomReady } from './dom-ready';

onDomReady(() => {
    const modal = document.querySelector('[data-audit-history-modal]');
    const opener = document.querySelector('[data-audit-history-open]');
    if (!modal || !opener) return;
    const field = name => modal.querySelector(`[data-audit-history-${name}]`);
    const form = field('filters'), rows = field('rows'), message = field('message'), retry = field('retry');
    const previous = field('previous'), next = field('next');
    const selectPage = field('select-page'), printSelected = field('print-selected'), printAll = field('print-all');
    const selected = new Set();
    let applied = Object.fromEntries(new FormData(form));
    let page = 1, lastPage = 1, pendingPage = 1, total = 0, loading = false, loaded = false, controller;
    const updateSelection = () => {
        const boxes = [...rows.querySelectorAll('[data-audit-select]')];
        boxes.forEach(box => { box.checked = selected.has(box.dataset.auditSelect); box.disabled = loading || !loaded; });
        selectPage.disabled = loading || !loaded || !boxes.length;
        selectPage.checked = boxes.length > 0 && boxes.every(box => box.checked);
        selectPage.indeterminate = boxes.some(box => box.checked) && !selectPage.checked;
        field('selected').textContent = `${selected.size} selected across pages`;
        for (const [link, enabled, ids] of [[printSelected, selected.size > 0, [...selected]], [printAll, total > 0, []]]) {
            const available = enabled && loaded && !loading;
            link.setAttribute('aria-disabled', String(!available));
            link.tabIndex = available ? 0 : -1;
            if (!available) { link.removeAttribute('href'); continue; }
            const url = new URL(modal.dataset.printUrl, location.href);
            Object.entries(applied).forEach(([key, value]) => { if (key !== 'audit_page' && value.trim()) url.searchParams.set(key, value.trim()); });
            if (ids.length) url.searchParams.set('audit_ids', ids.join(','));
            link.href = url.href;
        }
    };
    const controls = () => {
        previous.disabled = loading || page <= 1;
        next.disabled = loading || page >= lastPage;
        rows.setAttribute('aria-busy', String(loading));
        updateSelection();
    };
    const load = async requestedPage => {
        controller?.abort();
        const request = new AbortController(); controller = request;
        pendingPage = requestedPage; loading = true; loaded = false; controls();
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
            total = payload.pagination.total; loaded = true;
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
        selected.clear(); loaded = false; controls();
    };
    const open = initialPage => {
        selected.clear(); loaded = false;
        page = lastPage = 1;
        field('count').textContent = 'Loading actions...'; field('page').textContent = 'Page 1';
        document.dispatchEvent(new CustomEvent('stitch:dialog-open', { detail: { modal, close, opener } }));
        void load(initialPage);
    };
    opener.addEventListener('click', () => open(1));
    field('close').addEventListener('click', close);
    modal.addEventListener('click', event => { if (event.target === modal) close(); });
    form.addEventListener('submit', event => { event.preventDefault(); selected.clear(); applied = Object.fromEntries(new FormData(form)); void load(1); });
    field('reset').addEventListener('click', () => {
        form.querySelectorAll('input, select').forEach(input => { input.value = ''; });
        selected.clear(); applied = Object.fromEntries(new FormData(form)); void load(1);
    });
    rows.addEventListener('change', event => {
        const box = event.target.closest('[data-audit-select]');
        if (!box || loading || !loaded) return;
        if (box.checked && selected.size >= 500 && !selected.has(box.dataset.auditSelect)) {
            message.hidden = false; message.textContent = 'Select up to 500 actions, or use Print all matching filters.';
        } else if (box.checked) selected.add(box.dataset.auditSelect);
        else selected.delete(box.dataset.auditSelect);
        updateSelection();
    });
    selectPage.addEventListener('change', () => {
        for (const box of rows.querySelectorAll('[data-audit-select]')) {
            if (selectPage.checked && selected.size < 500) selected.add(box.dataset.auditSelect);
            else if (!selectPage.checked) selected.delete(box.dataset.auditSelect);
        }
        updateSelection();
    });
    [printSelected, printAll].forEach(link => link.addEventListener('click', event => { if (link.getAttribute('aria-disabled') === 'true') event.preventDefault(); }));
    previous.addEventListener('click', () => { if (!loading && page > 1) void load(page - 1); });
    next.addEventListener('click', () => { if (!loading && page < lastPage) void load(page + 1); });
    retry.addEventListener('click', () => void load(pendingPage));
    if (modal.hasAttribute('data-audit-history-auto-open')) window.setTimeout(() => open(Number(modal.dataset.initialPage) || 1), 120);
});
