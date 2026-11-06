import { onDomReady } from './dom-ready.js';
import { checkReportConnection } from './report-delivery.js';

const states = {
    checking: ['Checking online reporting', 'Availability is not confirmed yet. You can prepare or save a draft while checking.'],
    available: ['Online reporting server reachable', 'Complete the required report details to send online. A server receipt confirms delivery.'],
    offline: ['Offline — online reporting unavailable', 'Your device reports no connection. Save a draft on this device and reconnect before sending. A saved draft has not reached responders.'],
    unavailable: ['Online reporting unavailable', 'The reporting server could not be reached or confirmed. Save a draft and check again. A network connection alone does not confirm delivery.'],
    session: ['Sign in to use online reporting', 'Your session could not be confirmed. Save your draft on this device, then sign in again.'],
};

// Exported so interruption and reconnection can be checked without sending reports.
export function initializeReportConnection(panel, { window: win = window, document: doc = document, navigator: nav = navigator, probe = checkReportConnection, now = Date.now } = {}) {
    const title = panel.querySelector('[data-report-connection-title]');
    const message = panel.querySelector('[data-report-connection-message]');
    const retry = panel.querySelector('[data-report-connection-retry]');
    const result = panel.querySelector('[data-report-connection-result]');
    let controller = null;
    let checks = 0;
    const time = () => new Date(now()).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit', second: '2-digit' });
    const feedback = (text) => { if (result) result.textContent = text; };
    const show = (state) => {
        panel.dataset.connectionState = state;
        title.textContent = states[state][0];
        message.textContent = states[state][1];
        retry.disabled = state === 'checking';
        retry.textContent = state === 'checking' ? 'Checking…' : 'Check again';
    };
    const check = async () => {
        // Automatic polling must not cancel a check the user is waiting for.
        if (controller && !controller.signal.aborted) return;
        const current = new AbortController();
        controller = current;
        const number = ++checks, started = now();
        if (nav.onLine === false) {
            controller = null; show('offline');
            feedback(`Check #${number} at ${time()}: device offline. Reconnect, then check again.`);
            return;
        }
        show('checking');
        feedback(`Check #${number}: contacting the reporting server…`);
        try {
            await probe(panel.dataset.connectionUrl, { signal: current.signal });
            if (!current.signal.aborted) {
                const state = nav.onLine === false ? 'offline' : 'available';
                show(state);
                feedback(state === 'available'
                    ? `Check #${number} completed at ${time()}: server responded in ${Math.max(0, now() - started)} ms.`
                    : `Check #${number} at ${time()}: device offline.`);
            }
        } catch (error) {
            if (!current.signal.aborted) {
                const state = nav.onLine === false ? 'offline' : error.reason === 'session' ? 'session' : 'unavailable';
                show(state);
                feedback(`Check #${number} completed at ${time()}: ${states[state][0].toLowerCase()}.`);
            }
        } finally {
            if (controller === current) controller = null;
        }
    };
    const visible = () => { if (!doc.hidden) void check(); };
    const offline = () => { controller?.abort(); controller = null; show('offline'); feedback(`Connection lost at ${time()}. Reconnect, then check again.`); };
    retry.addEventListener('click', check);
    win.addEventListener('online', visible);
    win.addEventListener('offline', offline);
    doc.addEventListener('visibilitychange', visible);
    let timer = win.setInterval(visible, 30000);
    void check();
    win.addEventListener('pagehide', () => {
        controller?.abort(); controller = null; win.clearInterval(timer);
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
