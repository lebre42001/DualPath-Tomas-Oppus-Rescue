import { onDomReady } from './dom-ready.js';
import { checkReportConnection } from './report-delivery.js';

const states = {
    checking: ['Checking online reporting', 'Availability is not confirmed yet. You can prepare or save a draft while checking.'],
    available: ['Online reporting server reachable', 'The server responded to this check. Complete the photo, location, description, and live selfie check to send. Wait for a receipt to confirm delivery.'],
    offline: ['Offline — online reporting unavailable', 'Your device reports no connection. Save a draft on this device and reconnect before sending. A saved draft has not reached responders.'],
    unavailable: ['Online reporting unavailable', 'The reporting server could not be reached or confirmed. Save a draft and check again. A network connection alone does not confirm delivery.'],
    session: ['Sign in to use online reporting', 'Your session could not be confirmed. Save your draft on this device, then sign in again.'],
};

// Exported so interruption and reconnection can be checked without sending reports.
export function initializeReportConnection(panel, { window: win = window, document: doc = document, navigator: nav = navigator, probe = checkReportConnection } = {}) {
    const title = panel.querySelector('[data-report-connection-title]');
    const message = panel.querySelector('[data-report-connection-message]');
    const retry = panel.querySelector('[data-report-connection-retry]');
    let controller = null;
    const show = (state) => {
        panel.dataset.connectionState = state;
        title.textContent = states[state][0];
        message.textContent = states[state][1];
        retry.disabled = state === 'checking';
    };
    const check = async () => {
        controller?.abort();
        const current = new AbortController();
        controller = current;
        if (nav.onLine === false) { show('offline'); return; }
        show('checking');
        try {
            await probe(panel.dataset.connectionUrl, { signal: current.signal });
            if (!current.signal.aborted) show(nav.onLine === false ? 'offline' : 'available');
        } catch (error) {
            if (!current.signal.aborted) show(nav.onLine === false ? 'offline' : error.reason === 'session' ? 'session' : 'unavailable');
        }
    };
    const visible = () => { if (!doc.hidden) void check(); };
    const offline = () => { controller?.abort(); show('offline'); };
    retry.addEventListener('click', check);
    win.addEventListener('online', visible);
    win.addEventListener('offline', offline);
    doc.addEventListener('visibilitychange', visible);
    let timer = win.setInterval(visible, 30000);
    void check();
    win.addEventListener('pagehide', () => {
        controller?.abort(); win.clearInterval(timer);
        // Re-entering a page from the browser's back/forward cache needs a fresh check.
    });
    win.addEventListener('pageshow', (event) => {
        if (event.persisted) { timer = win.setInterval(visible, 30000); visible(); }
    });
}

onDomReady(() => {
    const panel = document.querySelector('[data-report-connection]');
    if (panel) initializeReportConnection(panel);
});
