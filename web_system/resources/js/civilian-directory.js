import { onDomReady } from './dom-ready';

onDomReady(() => {
    const modal = document.querySelector('[data-civilian-directory-modal]');
    const opener = document.querySelector('[data-civilian-directory-open]');
    if (!modal || !opener) return;
    const dialog = modal.querySelector('[role="dialog"]');
    const list = modal.querySelector('[data-civilian-directory-list]');
    const message = modal.querySelector('[data-civilian-directory-message]');
    const count = modal.querySelector('[data-civilian-directory-count]');
    const pageLabel = modal.querySelector('[data-civilian-directory-page]');
    const previous = modal.querySelector('[data-civilian-directory-previous]');
    const next = modal.querySelector('[data-civilian-directory-next]');
    const retry = modal.querySelector('[data-civilian-directory-retry]');
    const closeButton = modal.querySelector('[data-civilian-directory-close]');
    let page = 1;
    let lastPage = 1;
    let pendingPage = 1;
    let controller;
    let loading = false;
    let savedOverflow = '';

    const element = (tag, className, text) => {
        const node = document.createElement(tag);
        node.className = className;
        node.textContent = text;
        return node;
    };
    const renderAccount = account => {
        const row = element('li', 'civilian-directory-item', '');
        row.dataset.civilianAccountId = account.id;
        const identity = element('div', 'civilian-directory-identity', '');
        identity.append(element('strong', '', account.name), element('small', '', account.email));
        const badges = element('div', 'civilian-directory-badges', '');
        badges.append(element('span', `tag ${account.verified ? 'green' : 'amber'}`, account.verified ? 'Verified' : 'Not verified'));
        if (account.blocked) badges.append(element('span', 'tag red', 'Blocked'));
        const presence = element('span', `tag ${account.online ? 'blue' : 'neutral'}`, account.online ? 'Online' : 'Offline');
        presence.dataset.accountPresence = account.id;
        presence.dataset.presenceBadge = '';
        badges.append(presence, element('span', 'tag neutral', `${account.reports} ${account.reports === 1 ? 'report' : 'reports'}`));
        const view = element('a', 'btn btn-secondary', 'View');
        view.href = account.url;
        view.setAttribute('aria-label', `View ${account.name}`);
        row.append(identity, badges, view);
        return row;
    };
    const updateControls = () => {
        previous.disabled = loading || page <= 1;
        next.disabled = loading || page >= lastPage;
        list.setAttribute('aria-busy', String(loading));
    };
    const loadPage = async requestedPage => {
        controller?.abort();
        const request = new AbortController();
        controller = request;
        pendingPage = requestedPage;
        loading = true;
        updateControls();
        retry.hidden = true;
        message.hidden = false;
        message.textContent = 'Loading accounts...';
        const timeout = window.setTimeout(() => request.abort(), 15000);
        try {
            const url = new URL(modal.dataset.accountsUrl, location.href);
            url.searchParams.set('page', requestedPage);
            const response = await fetch(url, { signal: request.signal, cache: 'no-store', headers: { Accept: 'application/json' } });
            if (!response.ok || response.redirected) throw new Error('Accounts unavailable');
            const payload = await response.json();
            if (!Array.isArray(payload.accounts) || !payload.pagination) throw new Error('Invalid directory data');
            if (controller !== request || modal.hidden) return;
            if (payload.pagination.page > payload.pagination.lastPage) {
                void loadPage(payload.pagination.lastPage);
                return;
            }
            page = payload.pagination.page;
            lastPage = payload.pagination.lastPage;
            list.replaceChildren(...payload.accounts.map(renderAccount));
            message.hidden = payload.accounts.length > 0;
            message.textContent = 'No civilian accounts yet.';
            count.textContent = payload.pagination.total ? `${payload.pagination.from}-${payload.pagination.to} of ${payload.pagination.total} accounts` : '0 accounts';
            pageLabel.textContent = `Page ${page} of ${lastPage}`;
            modal.querySelector('[data-civilian-directory-scroll]').scrollTop = 0;
            document.dispatchEvent(new Event('civilian-presence:refresh'));
        } catch {
            if (controller !== request || modal.hidden) return;
            message.textContent = 'Unable to load accounts. Please try again.';
            count.textContent = list.children.length ? 'Showing the last loaded page.' : 'Accounts unavailable';
            retry.hidden = false;
        } finally {
            window.clearTimeout(timeout);
            if (controller === request) {
                loading = false;
                updateControls();
                // A disabled pagination button may lose focus after reaching the first/last page.
                if (!modal.hidden && !dialog.contains(document.activeElement)) closeButton.focus({ preventScroll: true });
            }
        }
    };
    const close = () => {
        if (modal.hidden) return;
        controller?.abort();
        controller = null;
        modal.hidden = true;
        list.replaceChildren();
        document.body.style.overflow = savedOverflow;
        opener.focus({ preventScroll: true });
    };
    opener.addEventListener('click', () => {
        savedOverflow = document.body.style.overflow;
        modal.hidden = false;
        document.body.style.overflow = 'hidden';
        page = 1;
        lastPage = 1;
        count.textContent = 'Loading accounts...';
        pageLabel.textContent = 'Page 1';
        closeButton.focus({ preventScroll: true });
        void loadPage(1);
    });
    closeButton.addEventListener('click', close);
    modal.addEventListener('click', event => { if (event.target === modal) close(); });
    previous.addEventListener('click', () => { if (!loading && page > 1) void loadPage(page - 1); });
    next.addEventListener('click', () => { if (!loading && page < lastPage) void loadPage(page + 1); });
    retry.addEventListener('click', () => void loadPage(pendingPage));
    document.addEventListener('keydown', event => {
        if (modal.hidden) return;
        if (event.key === 'Escape') { event.preventDefault(); close(); }
        if (event.key !== 'Tab') return;
        const targets = [...dialog.querySelectorAll('button:not(:disabled), a[href], [tabindex="0"]')].filter(node => node.getClientRects().length);
        const first = targets[0];
        const last = targets.at(-1);
        if (event.shiftKey && (document.activeElement === first || !dialog.contains(document.activeElement))) {
            event.preventDefault(); last?.focus();
        } else if (!event.shiftKey && (document.activeElement === last || !dialog.contains(document.activeElement))) {
            event.preventDefault(); first?.focus();
        }
    });
});
