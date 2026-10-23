import { onDomReady } from './dom-ready';

onDomReady(() => {
    const settings = document.querySelector('[data-presence-url]');
    if (!settings) return;
    const endpoint = settings.dataset.presenceUrl;
    const clientId = window.crypto?.randomUUID?.()
        ?? `${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}`;
    const intervalMs = Number(settings.dataset.presenceInterval || 20) * 1000;
    let sequence = 0;
    let busy = false;
    let stopped = false;
    let offlineAnnounced = false;
    let pageHidden = false;
    let interval;
    const packet = state => {
        const body = new FormData();
        body.set('_token', document.querySelector('meta[name="csrf-token"]')?.content || '');
        body.set('client_id', clientId);
        body.set('sequence', String(++sequence));
        body.set('state', state);
        return body;
    };
    const heartbeat = async () => {
        if (stopped || busy || pageHidden || document.hidden || navigator.onLine === false) return;
        busy = true;
        offlineAnnounced = false;
        const abort = typeof AbortController === 'undefined' ? null : new AbortController();
        const timeout = abort ? window.setTimeout(() => abort.abort(), 10000) : null;
        try {
            const response = await fetch(endpoint, {
                method: 'POST', credentials: 'same-origin', cache: 'no-store',
                headers: { Accept: 'application/json' }, body: packet('online'),
                signal: abort?.signal,
            });
            if (response.redirected || [401, 403, 419, 423].includes(response.status)) {
                stopped = true;
                window.clearInterval(interval);
            }
        } catch {
            // Server-side expiry covers network loss and interrupted browser processes.
        } finally {
            window.clearTimeout(timeout);
            busy = false;
            if (offlineAnnounced && !pageHidden && !document.hidden && navigator.onLine !== false) void heartbeat();
        }
    };
    const offline = () => {
        if (stopped || offlineAnnounced) return;
        offlineAnnounced = true;
        const body = packet('offline');
        if (navigator.sendBeacon?.(endpoint, body)) return;
        void fetch(endpoint, { method: 'POST', body, keepalive: true, credentials: 'same-origin', headers: { Accept: 'application/json' } }).catch(() => {});
    };
    document.addEventListener('visibilitychange', () => { if (document.hidden) offline(); else void heartbeat(); });
    window.addEventListener('pagehide', () => { pageHidden = true; offline(); });
    window.addEventListener('pageshow', () => { pageHidden = false; void heartbeat(); });
    window.addEventListener('offline', offline);
    window.addEventListener('online', heartbeat);
    interval = window.setInterval(heartbeat, intervalMs);
    void heartbeat();
});

onDomReady(() => {
    const directory = document.querySelector('[data-civilian-presence-url]');
    if (!directory) return;
    const status = directory.querySelector('[data-presence-refresh-status]');
    const ids = [...new Set(Array.from(directory.querySelectorAll('[data-civilian-account-id]'), row => row.dataset.civilianAccountId))];
    if (!ids.length) {
        if (status) status.textContent = 'No accounts in this view.';
        return;
    }
    const endpoint = new URL(directory.dataset.civilianPresenceUrl, location.href);
    ids.forEach(id => endpoint.searchParams.append('ids[]', id));
    let busy = false;
    const refresh = async () => {
        if (busy || document.hidden) return;
        busy = true;
        const abort = typeof AbortController === 'undefined' ? null : new AbortController();
        const timeout = abort ? window.setTimeout(() => abort.abort(), 10000) : null;
        try {
            const response = await fetch(endpoint, { cache: 'no-store', headers: { Accept: 'application/json' }, signal: abort?.signal });
            if (!response.ok || response.redirected) throw new Error('Presence unavailable');
            const payload = await response.json();
            if (!Array.isArray(payload.accounts)) throw new Error('Invalid presence data');
            payload.accounts.forEach(account => {
                const id = Number(account.id);
                if (!Number.isInteger(id)) return;
                const online = account.online === true;
                const label = online ? 'Online' : 'Offline';
                document.querySelectorAll(`[data-account-presence="${id}"]`).forEach(element => {
                    if (element.textContent !== label) element.textContent = label;
                    if (element.hasAttribute('data-presence-badge')) {
                        element.classList.toggle('blue', online);
                        element.classList.toggle('neutral', !online);
                    }
                });
                document.querySelectorAll(`[data-presence-description="${id}"]`).forEach(element => {
                    element.textContent = online ? 'An active app connection was detected within the last 60 seconds.' : 'No current app connection detected.';
                });
                document.querySelectorAll(`[data-presence-last-seen="${id}"]`).forEach(element => { element.textContent = account.lastSeenHuman; });
                document.querySelectorAll(`[data-presence-last-seen-exact="${id}"]`).forEach(element => { element.textContent = account.lastSeenExact; });
            });
            if (status) {
                status.textContent = `Presence updated ${new Date().toLocaleTimeString()}`;
                status.dataset.state = 'live';
            }
        } catch {
            if (status) {
                status.textContent = 'Presence updates unavailable; showing last known status.';
                status.dataset.state = 'error';
            }
        } finally {
            window.clearTimeout(timeout);
            busy = false;
        }
    };
    window.setInterval(refresh, 5000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) void refresh(); });
    window.addEventListener('online', refresh);
    void refresh();
});
