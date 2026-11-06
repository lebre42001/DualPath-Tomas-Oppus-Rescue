import { onDomReady } from './dom-ready.js';
import { createCivilianUpdateState, isBuildVersion } from './civilian-update-state.js';
import { initializeUpdatesPosition } from './civilian-updates-position.js';

export function initializeCivilianSystem(root, { document: doc = document, window: win = window, navigator: nav = navigator, fetchImpl = fetch, storage } = {}) {
    const running = root.dataset.runningVersion;
    const state = createCivilianUpdateState(root.dataset.owner, running, { storage });
    const toggle = root.querySelector('[data-civilian-updates-toggle]');
    initializeUpdatesPosition(toggle, root.dataset.owner, { document: doc, window: win, storage });
    const modal = root.querySelector('[data-civilian-updates-modal]');
    const closeButton = root.querySelector('[data-civilian-updates-close]');
    const badge = root.querySelector('[data-civilian-update-count]');
    const list = root.querySelector('[data-civilian-update-list]');
    const markRead = root.querySelector('[data-civilian-updates-read]');
    const update = root.querySelector('[data-civilian-apply-update]');
    const status = root.querySelector('[data-app-update-status]');
    const connection = root.querySelector('[data-report-connection]');
    toggle.dataset.connectionState = connection.dataset.connectionState ?? 'checking';
    let latest = null, ids = [], syncing = false, applying = false, stopped = false, timer;
    const setText = (selector, value) => { for (const node of doc.querySelectorAll(selector)) node.textContent = value; };
    setText('[data-app-running]', running); setText('[data-app-previous]', state.previous() ?? 'Not recorded');
    const request = async (url, body) => {
        const controller = new AbortController();
        const timeout = win.setTimeout(() => controller.abort(), 8000);
        try {
            const response = await fetchImpl(url, {
                method: body ? 'POST' : 'GET', credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...(body ? { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': doc.querySelector('meta[name="csrf-token"]')?.content ?? '' } : {}) },
                ...(body ? { body: JSON.stringify(body) } : {}),
            });
            if (!response.ok || response.redirected) {
                if ([401, 403, 419].includes(response.status) || response.redirected) { stopped = true; toggle.dataset.connectionState = 'session'; }
                throw Object.assign(new Error('The server could not confirm this request.'), { status: response.status });
            }
            return await response.json();
        } finally { win.clearTimeout(timeout); }
    };
    const send = async event => {
        const data = await request(root.dataset.eventUrl, event);
        if (data.saved !== true) throw new Error('Device activity receipt was not confirmed.');
    };
    const node = (tag, text) => { const element = doc.createElement(tag); element.textContent = text; return element; };
    const render = data => {
        if (isBuildVersion(data.version)) latest = data.version;
        setText('[data-app-latest]', latest ?? 'Not confirmed');
        update.hidden = !latest || latest === running;
        if (!applying) status.textContent = latest !== running ? 'A newer server build is available. Choose Update app when you are ready.' : 'This page is using the latest server build.';
        const count = Math.max(0, Number(data.unread_count) || 0);
        badge.textContent = String(count); badge.hidden = count === 0;
        toggle.setAttribute('aria-label', count ? `System updates, ${count} unread` : 'System updates');
        list.replaceChildren(); ids = [];
        for (const event of data.notifications ?? []) {
            const card = node('article', ''); card.className = 'civilian-update-item';
            card.append(node('strong', event.title), node('p', event.message), node('time', new Date(event.occurred_at).toLocaleString()));
            list.append(card); ids.push(event.id);
        }
        if (!ids.length) list.append(node('p', 'No unread app or report updates.'));
        markRead.hidden = ids.length === 0;
    };
    const sync = async () => {
        if (syncing || stopped || applying || doc.hidden || nav.onLine === false) return;
        syncing = true;
        try {
            let activityPending = false;
            try { await state.flush(send); } catch (error) { if (stopped) throw error; activityPending = true; }
            let data = await request(root.dataset.feedUrl);
            if (!isBuildVersion(data.version) || !Array.isArray(data.notifications)) throw new Error('Update response was not confirmed.');
            if (state.observeLatest(data.version)) {
                try { await state.flush(send); } catch (error) { if (stopped) throw error; activityPending = true; }
                data = await request(root.dataset.feedUrl);
            }
            render(data);
            if (activityPending) status.textContent += state.persistent() ? ' Device activity sync is pending; report updates are still shown.' : ' Device activity could not be saved locally; reconnect before closing.';
        } catch {
            status.textContent = stopped ? 'Sign in again to check your private updates.'
                : state.persistent() ? 'Updates could not be confirmed. Reconnect and choose Check again. Pending device events are kept for retry.'
                    : 'Updates could not be confirmed and device activity could not be saved locally. Reconnect before closing this page.';
        } finally { syncing = false; }
    };
    const close = () => {
        doc.dispatchEvent(new CustomEvent('stitch:dialog-close', { detail: { modal } }));
        modal.hidden = true; toggle.setAttribute('aria-expanded', 'false');
    };
    toggle.addEventListener('click', () => {
        doc.dispatchEvent(new CustomEvent('stitch:dialog-open', { detail: { modal, close, opener: toggle } }));
        modal.hidden = false; toggle.setAttribute('aria-expanded', 'true'); void sync();
    });
    closeButton.addEventListener('click', close);
    modal.addEventListener('click', event => { if (event.target === modal) close(); });
    connection.addEventListener('stitch:connection-state', event => { toggle.dataset.connectionState = event.detail.state; });
    connection.querySelector('[data-report-connection-retry]').addEventListener('click', () => { void sync(); });
    markRead.addEventListener('click', async () => {
        if (!ids.length) return;
        markRead.disabled = true;
        try {
            const data = await request(root.dataset.readUrl, { ids: [...ids] });
            if (data.saved !== true) throw new Error('Read receipt was not confirmed.');
            await sync();
        }
        catch { status.textContent = 'Could not mark updates as read. Check your connection and try again.'; }
        finally { markRead.disabled = false; }
    });
    update.addEventListener('click', async () => {
        if (!latest || latest === running || applying || stopped) return;
        applying = true; update.disabled = true;
        status.textContent = 'Saving unfinished report details before updating…';
        try {
            const pending = [];
            doc.dispatchEvent(new CustomEvent('stitch:prepare-app-update', { detail: { pending } }));
            await Promise.all(pending);
            state.enqueue('app_update_requested', latest, running);
            try { await state.flush(send); }
            catch (error) { if (stopped || !state.persistent()) throw error; } // A durable retry queue must not block applying the app update.
            status.textContent = 'Applying the update. The app will reload with fresh assets.';
            win.dispatchEvent(new CustomEvent('stitch:apply-application-update'));
        } catch (error) {
            state.enqueue('app_update_failed', latest, running);
            status.textContent = error.message || 'Update could not be prepared. Keep this page open and try again.';
            applying = false; update.disabled = false;
        }
    });
    const refresh = () => { void sync(); };
    win.addEventListener('online', () => { state.enqueue('app_connection_online', running); refresh(); });
    win.addEventListener('stitch:application-update', refresh);
    win.addEventListener('pageshow', event => { if (event.persisted) { timer = win.setInterval(refresh, 20000); refresh(); } });
    doc.addEventListener('visibilitychange', () => { if (!doc.hidden) refresh(); });
    win.addEventListener('offline', () => { state.enqueue('app_connection_offline', running); status.textContent = 'Offline. Reconnect to check app and report updates.'; });
    win.addEventListener('pagehide', () => win.clearInterval(timer));
    timer = win.setInterval(refresh, 20000); void sync();
}

export async function copySystemLog(textarea, feedback, { navigator: nav = navigator } = {}) {
    try { await nav.clipboard.writeText(textarea.value); feedback.textContent = 'Log text copied.'; }
    catch { textarea.hidden = false; textarea.focus(); textarea.select(); feedback.textContent = 'Select Copy on your device to copy the selected log text.'; }
}

onDomReady(() => {
    const root = document.querySelector('[data-civilian-updates]');
    if (root) initializeCivilianSystem(root);
    const log = document.querySelector('[data-civilian-system-log]');
    log?.querySelector('[data-system-log-copy-button]')?.addEventListener('click', () => {
        void copySystemLog(log.querySelector('[data-system-log-copy]'), log.querySelector('[data-system-log-copy-status]'));
    });
});
