import { onDomReady } from './dom-ready.js';

export function initializeAdminSystemLog(root, { document: doc = document, window: win = window, fetchImpl = fetch } = {}) {
    const form = root.querySelector('[data-admin-log-filters]');
    const status = root.querySelector('[data-admin-log-status]');
    let dirty = false, stopped = false, busy = false, timer;
    form?.addEventListener('input', () => { dirty = true; status.textContent = 'Live refresh paused while you edit. Choose Search logs to apply these filters.'; });
    form?.addEventListener('change', () => { dirty = true; });
    const refresh = async () => {
        if (dirty || stopped || busy || doc.hidden) return;
        busy = true;
        try {
            const response = await fetchImpl(win.location.href, { credentials: 'same-origin', cache: 'no-store', signal: AbortSignal.timeout(10000), headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' } });
            if (response.redirected || [401, 403, 419].includes(response.status)) { stopped = true; throw new Error('Sign in again to refresh private activity records.'); }
            if (!response.ok) throw new Error('Updates delayed. Your saved logs remain available; choose Refresh logs to retry.');
            const parsed = new win.DOMParser().parseFromString(await response.text(), 'text/html');
            const updated = parsed.querySelector('[data-admin-system-log]');
            if (!updated) throw new Error('Log refresh was not confirmed. Choose Refresh logs to retry.');
            if (dirty || stopped || doc.hidden) return;
            if (root.dataset.dailyView === 'true' && updated.dataset.logDay !== root.dataset.logDay) {
                const url = new URL(win.location.href); url.searchParams.delete('page'); win.location.assign(url.href); return;
            }
            for (const selector of ['.admin-log-table tbody', '[data-admin-log-pagination]', '.admin-duty-list', '[data-admin-log-status]']) {
                const before = root.querySelector(selector), after = updated.querySelector(selector);
                if (before && after && !before.contains(doc.activeElement)) before.replaceChildren(...[...after.childNodes].map(node => doc.importNode(node, true)));
            }
        } catch (error) { status.textContent = error.message; }
        finally { busy = false; }
    };
    doc.addEventListener('visibilitychange', () => { if (!doc.hidden) void refresh(); });
    win.addEventListener('pagehide', () => { stopped = true; win.clearInterval(timer); });
    win.addEventListener('pageshow', event => { if (event.persisted) { stopped = false; win.clearInterval(timer); timer = win.setInterval(refresh, 30000); void refresh(); } });
    timer = win.setInterval(refresh, 30000);
    return { refresh };
}

onDomReady(() => {
    const activity = document.querySelector('[data-admin-activity-url]');
    if (!activity) return;
    const send = async detail => {
        if (!crypto.randomUUID) return;
        try {
            await fetch(activity.dataset.adminActivityUrl, { method: 'POST', credentials: 'same-origin', cache: 'no-store', keepalive: true,
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '' },
                body: JSON.stringify({ ...detail, event_id: crypto.randomUUID() }), signal: AbortSignal.timeout(8000) });
        } catch { /* Optional interface telemetry never blocks a real report decision. */ }
    };
    document.addEventListener('stitch:admin-activity', event => { void send(event.detail); });
    document.addEventListener('click', event => {
        const button = event.target.closest?.('[data-incoming-sound], [data-incoming-sound-mute], [data-incoming-sound-stop]');
        if (!button || !event.isTrusted) return;
        const kind = button.hasAttribute('data-incoming-sound-mute') ? 'alarm_muted' : button.hasAttribute('data-incoming-sound-stop') ? 'alarm_stopped'
            : button.textContent.includes('Play') ? 'alarm_play_requested' : button.textContent.includes('Test') ? 'alarm_tested' : 'alarm_enabled';
        void send({ event: kind });
    }, true);
    const log = document.querySelector('[data-admin-system-log]');
    if (log) initializeAdminSystemLog(log);
});
