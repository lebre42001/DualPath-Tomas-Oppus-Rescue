import { onDomReady } from './dom-ready.js';
import { initializeUpdatesPosition } from './civilian-updates-position.js';
import { createCivilianUpdateState, isBuildVersion } from './civilian-update-state.js';

export function initializeAdminNotifications(root, { document: doc = document, window: win = window, fetchImpl = fetch, storage } = {}) {
    if (storage === undefined) { try { storage = win.localStorage; } catch { storage = null; } }
    const toggle = root.querySelector('[data-admin-notifications-toggle]');
    const modal = root.querySelector('[data-alert-center-panel]');
    const tabs = [...root.querySelectorAll('[data-notification-tab]')];
    const panels = [...root.querySelectorAll('[data-notification-panel]')];
    const badge = root.querySelector('[data-admin-notification-count]');
    initializeUpdatesPosition(toggle, `admin-${root.dataset.owner}`, { document: doc, window: win, storage });
    const activity = event => doc.dispatchEvent(new CustomEvent('stitch:admin-activity', { detail: { event } }));
    let reports = Number(modal.dataset.unreadCount) || 0, updateUnread = 0, selected = 'reports';
    const renderCount = () => {
        const count = reports + updateUnread;
        badge.textContent = count > 99 ? '99+' : String(count); badge.hidden = count === 0;
        toggle.setAttribute('aria-label', `Notifications, ${reports} unseen reports, ${updateUnread} unread app updates`);
        const counter = root.querySelector('[data-admin-update-count]');
        if (counter) { counter.textContent = String(updateUnread); counter.hidden = updateUnread === 0; }
    };
    const select = (name, focus = false, record = true) => {
        selected = name;
        for (const tab of tabs) {
            const active = tab.dataset.notificationTab === name;
            tab.setAttribute('aria-selected', String(active)); tab.tabIndex = active ? 0 : -1;
            if (active && focus) tab.focus();
        }
        for (const panel of panels) panel.hidden = panel.dataset.notificationPanel !== name;
        if (record) activity(name === 'updates' ? 'notification_tab_updates' : 'notification_tab_reports');
    };
    const close = () => {
        doc.dispatchEvent(new CustomEvent('stitch:dialog-close', { detail: { modal } }));
        modal.hidden = true; toggle.setAttribute('aria-expanded', 'false');
    };
    const open = () => {
        select(reports ? 'reports' : updateUnread ? 'updates' : selected, false, false);
        doc.dispatchEvent(new CustomEvent('stitch:dialog-open', { detail: { modal, close, opener: toggle } }));
        modal.hidden = false; toggle.setAttribute('aria-expanded', 'true'); activity('notifications_opened');
        void refresh();
    };
    toggle.addEventListener('click', open);
    root.querySelector('[data-alert-center-close]').addEventListener('click', close);
    modal.addEventListener('click', event => { if (event.target === modal) close(); });
    tabs.forEach((tab, index) => {
        tab.addEventListener('click', () => select(tab.dataset.notificationTab));
        tab.addEventListener('keydown', event => {
            if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
            event.preventDefault();
            const next = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : (index + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
            select(tabs[next].dataset.notificationTab, true);
        });
    });
    doc.addEventListener('stitch:incoming-count', event => { reports = Number(event.detail.count) || 0; renderCount(); });
    renderCount();

    let refresh = async () => {};
    if (!root.hasAttribute('data-admin-app-updates')) return { open, close };
    const running = root.dataset.runningVersion;
    root.querySelector('[data-admin-running-build]').textContent = running;
    const state = createCivilianUpdateState(root.dataset.owner, running, { storage, keyPrefix: 'aksyres-admin-system-state:v1:' });
    const status = root.querySelector('[data-admin-update-status]');
    const apply = root.querySelector('[data-admin-apply-update]');
    const check = root.querySelector('[data-admin-check-updates]');
    const read = root.querySelector('[data-admin-read-update]');
    const notes = root.querySelector('[data-admin-release-notes]');
    const readKey = `aksyres-admin-update-read:${root.dataset.owner}`;
    let latest = null, seen = null, busy = false, applying = false, stopped = false, timer, lastNotes = null;
    try { seen = storage?.getItem(readKey); } catch { /* Reading notices remains optional. */ }
    root.querySelector('[data-admin-previous-build]').textContent = state.previous() ?? 'Not recorded';
    const request = async (url, body) => {
        const abort = new AbortController(), timeout = win.setTimeout(() => abort.abort(), 10000);
        try {
            const response = await fetchImpl(url, { method: body ? 'POST' : 'GET', credentials: 'same-origin', cache: 'no-store', signal: abort.signal,
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...(body ? { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': doc.querySelector('meta[name="csrf-token"]')?.content ?? '' } : {}) },
                ...(body ? { body: JSON.stringify(body) } : {}) });
            if (response.redirected || [401, 403, 419].includes(response.status)) { stopped = true; throw new Error('Sign in again to check private admin updates.'); }
            if (!response.ok) throw Object.assign(new Error('Could not confirm the update. Check your connection and try again.'), { status: response.status });
            return response.json();
        } finally { win.clearTimeout(timeout); }
    };
    let confirmedRequest = null;
    const send = async event => {
        const receipt = await request(root.dataset.eventUrl, event);
        if (receipt.saved !== true) throw new Error('Activity was not confirmed.');
        if (event.kind === 'app_update_requested') confirmedRequest = event.version;
    };
    refresh = async () => {
        if (busy || stopped || applying) return;
        busy = true; check.disabled = true; status.textContent = 'Checking the server…';
        try {
            const data = await request(root.dataset.updatesUrl);
            if (!isBuildVersion(data.version)) throw new Error('The server build was not confirmed.');
            latest = data.version; state.observeLatest(latest);
            root.querySelector('[data-admin-latest-build]').textContent = latest;
            apply.hidden = latest === running; read.hidden = latest === running || seen === latest;
            updateUnread = latest !== running && seen !== latest ? 1 : 0; renderCount();
            if (typeof data.release_notes_html === 'string' && data.release_notes_html !== lastNotes) {
                notes.innerHTML = data.release_notes_html; lastNotes = data.release_notes_html;
            }
            status.textContent = latest === running ? 'Checked: this page is using the latest server build.' : 'A new server build is available. Read what changed, then choose Update app.';
            await state.flush(send);
        } catch (error) { status.textContent = error.message; }
        finally { busy = false; check.disabled = false; }
    };
    check.addEventListener('click', () => { activity('update_check_requested'); void refresh(); });
    read.addEventListener('click', () => {
        if (!latest) return;
        seen = latest; try { storage?.setItem(readKey, seen); } catch { /* Per-page read state still works. */ }
        updateUnread = 0; read.hidden = true; renderCount(); activity('update_notice_seen');
    });
    apply.addEventListener('click', async () => {
        if (!latest || latest === running || applying || stopped) return;
        if (!win.confirm('Update the app now? This reloads the page. Save or finish any unsaved report review first.')) return;
        applying = true; apply.disabled = true;
        try {
            confirmedRequest = null;
            state.enqueue('app_update_requested', latest, running);
            await state.flush(send);
            if (confirmedRequest !== latest) throw new Error('The server build changed. Check again before updating.');
            status.textContent = 'Applying the update. The saved request is in System Log.';
            win.dispatchEvent(new CustomEvent('stitch:apply-application-update'));
        } catch (error) {
            state.enqueue('app_update_failed', latest, running);
            status.textContent = error.message + ' The page has been kept open.';
            applying = false; apply.disabled = false;
        }
    });
    win.addEventListener('stitch:application-update', () => { void refresh(); });
    win.addEventListener('online', () => { state.enqueue('app_connection_online', running); void refresh(); });
    win.addEventListener('offline', () => { state.enqueue('app_connection_offline', running); status.textContent = 'Offline. Reconnect to check updates; report monitoring will retry.'; });
    doc.addEventListener('visibilitychange', () => { if (!doc.hidden) void refresh(); });
    win.addEventListener('pagehide', () => { stopped = true; win.clearInterval(timer); });
    win.addEventListener('pageshow', event => { if (event.persisted) { stopped = false; win.clearInterval(timer); timer = win.setInterval(() => { if (!doc.hidden) void refresh(); }, 20000); void refresh(); } });
    timer = win.setInterval(() => { if (!doc.hidden) void refresh(); }, 20000); void refresh();
    return { open, close, refresh };
}

onDomReady(() => { const root = document.querySelector('[data-admin-notifications]'); if (root) initializeAdminNotifications(root); });
