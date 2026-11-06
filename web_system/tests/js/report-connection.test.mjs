import test from 'node:test';
import assert from 'node:assert/strict';
import { checkReportConnection } from '../../resources/js/report-delivery.js';

// Prevent automatic DOM initialization in this isolated, browser-free test.
globalThis.document = { readyState: 'loading', addEventListener() {} };
const { initializeReportConnection } = await import('../../resources/js/report-connection.js');
const json = (data, status = 200) => new Response(JSON.stringify(data), { status });

test('connection probe requires an explicit fresh authenticated response, including when Wi-Fi is connected', async () => {
    assert.equal(await checkReportConnection('/reports/connection', { fetchImpl: async (_url, options) => {
        assert.equal(options.method, 'GET'); assert.equal(options.cache, 'no-store');
        assert.equal(options.credentials, 'same-origin'); return json({ available: true });
    } }), true);
    for (const response of [json({}), json({ available: false }), json({}, 503)]) {
        await assert.rejects(checkReportConnection('/reports/connection', { fetchImpl: async () => response }), error => error.reason === 'server');
    }
    await assert.rejects(checkReportConnection('/reports/connection', { fetchImpl: async () => { throw new TypeError('Network'); } }), error => error.reason === 'connection');
    await assert.rejects(checkReportConnection('/reports/connection', { fetchImpl: async () => json({}, 401) }), error => error.reason === 'session');
});

test('offline, captive/unreachable server, session expiry and reconnection display their actual state without submitting a report', async () => {
    const win = new EventTarget(), doc = new EventTarget(), nav = { onLine: false };
    let interval, calls = 0, behavior = async () => true;
    win.setInterval = callback => { interval = callback; return 1; }; win.clearInterval = () => {};
    const title = {}, message = {}, retry = new EventTarget();
    const panel = { dataset: { connectionUrl: '/reports/connection' }, querySelector: selector => ({
        '[data-report-connection-title]': title, '[data-report-connection-message]': message,
        '[data-report-connection-retry]': retry,
    })[selector] };
    initializeReportConnection(panel, { window: win, document: doc, navigator: nav, probe: async (url, options) => {
        calls++; assert.equal(url, '/reports/connection'); return behavior(options.signal);
    } });
    const settle = () => new Promise(resolve => setImmediate(resolve));
    assert.equal(calls, 0); assert.equal(panel.dataset.connectionState, 'offline');
    nav.onLine = true; behavior = async () => { throw new Error('Server unavailable'); };
    win.dispatchEvent(new Event('online')); await settle();
    assert.equal(panel.dataset.connectionState, 'unavailable');
    behavior = async () => { throw Object.assign(new Error('Session expired'), { reason: 'session' }); };
    retry.dispatchEvent(new Event('click')); await settle(); assert.equal(panel.dataset.connectionState, 'session');
    behavior = async () => true; retry.dispatchEvent(new Event('click')); await settle();
    assert.equal(panel.dataset.connectionState, 'available'); assert.match(message.textContent, /receipt/);
    let finish;
    behavior = () => new Promise(resolve => { finish = resolve; });
    interval(); assert.equal(panel.dataset.connectionState, 'checking');
    nav.onLine = false; win.dispatchEvent(new Event('offline')); finish(true); await settle();
    assert.equal(panel.dataset.connectionState, 'offline', 'A late response cannot overwrite the offline state');
    const previousCalls = calls; doc.hidden = true; interval(); assert.equal(calls, previousCalls);
});
