import test from 'node:test';
import assert from 'node:assert/strict';
import { createCivilianUpdateState } from '../../resources/js/civilian-update-state.js';
import { watchCivilianDraftRecovery } from '../../resources/js/civilian-draft-recovery.js';

globalThis.document = { readyState: 'loading', addEventListener() {} };
const { initializeCivilianSystem, copySystemLog } = await import('../../resources/js/civilian-system.js');
const old = '1111111111111111', running = '2222222222222222', latest = '3333333333333333';
const storage = () => { const values = new Map(); return { getItem: key => values.get(key) ?? null, setItem: (key, value) => values.set(key, value) }; };
const settle = () => new Promise(resolve => setImmediate(resolve));

test('version history and offline app events survive reopen, deduplicate detection, and stay account-scoped', async () => {
    const local = storage();
    const first = createCivilianUpdateState(1, old, { storage: local });
    assert.equal(first.observeLatest(running), true); assert.equal(first.observeLatest(running), false);
    const other = createCivilianUpdateState(2, latest, { storage: local });
    assert.equal(other.previous(), null); assert.equal(other.pending().length, 1);
    const reopened = createCivilianUpdateState(1, running, { storage: local });
    assert.equal(reopened.previous(), old);
    assert.equal(reopened.pending().filter(event => event.kind === 'app_update_applied').length, 1);
    const pendingIds = reopened.pending().map(event => event.event_id);
    await assert.rejects(reopened.flush(async () => { throw new Error('Offline'); }));
    assert.deepEqual(reopened.pending().map(event => event.event_id), pendingIds);
    const delivered = [];
    await reopened.flush(async event => { delivered.push(event.event_id); });
    assert.deepEqual(delivered, pendingIds); assert.equal(reopened.pending().length, 0);
    assert.equal(createCivilianUpdateState(1, running, { storage: local }).pending().length, 0);
    assert.equal(other.pending().length, 1);
});

test('stale targets and expired telemetry do not block current updates; unconfirmed writes remain retryable', async () => {
    const state = createCivilianUpdateState(1, running, { storage: storage() });
    state.enqueue('app_connection_offline', running); state.observeLatest(latest);
    let calls = 0;
    await state.flush(async () => { calls++; if (calls === 1) throw Object.assign(new Error('Expired'), { status: 422 }); if (calls === 2) throw Object.assign(new Error('Newer release'), { status: 409 }); });
    assert.equal(state.pending().length, 0);
    const blocked = createCivilianUpdateState(3, running, { storage: { getItem: () => null, setItem: () => { throw new Error('Quota'); } } });
    assert.equal(blocked.persistent(), false); assert.equal(blocked.pending().length, 1);
});

test('corrupted local activity state is recoverable and events queued during an active flush are included before reload', async () => {
    for (const value of ['"bad state"', '[]', '{"pending":[null]}']) {
        const state = createCivilianUpdateState(1, running, { storage: { getItem: () => value, setItem() {} } });
        assert.equal(state.pending().length, 1);
    }
    const state = createCivilianUpdateState(1, running, { storage: storage() });
    const delivered = [];
    await state.flush(async event => {
        delivered.push(event.kind);
        if (event.kind === 'app_version_seen') state.enqueue('app_update_requested', latest, running);
    });
    assert.deepEqual(delivered, ['app_version_seen', 'app_update_requested']);
    assert.equal(state.pending().length, 0);
});

class Element extends EventTarget {
    dataset = {}; children = []; attributes = {}; hidden = false; textContent = ''; disabled = false;
    setAttribute(name, value) { this.attributes[name] = value; }
    append(...nodes) { this.children.push(...nodes); }
    replaceChildren() { this.children = []; }
}
const fixture = ({ failEvents = () => false } = {}) => {
    const doc = new EventTarget(), win = new EventTarget(), nav = { onLine: true }, root = new Element();
    const selectors = ['civilian-updates-toggle', 'civilian-updates-modal', 'civilian-updates-close', 'civilian-update-count', 'civilian-update-list', 'civilian-updates-read', 'civilian-apply-update', 'app-update-status', 'report-connection', 'report-connection-retry'];
    const elements = Object.fromEntries(selectors.map(name => [name, new Element()]));
    elements['report-connection'].querySelector = () => elements['report-connection-retry'];
    root.dataset = { owner: '1', runningVersion: running, feedUrl: '/feed', readUrl: '/read', eventUrl: '/events' };
    root.querySelector = selector => elements[selector.slice(6, -1)];
    doc.querySelectorAll = () => []; doc.querySelector = () => ({ content: 'test-csrf' });
    doc.createElement = () => new Element();
    win.setTimeout = setTimeout; win.clearTimeout = clearTimeout; win.setInterval = () => 1; win.clearInterval = () => {};
    const sent = [], notifications = [{ id: 1, kind: 'report_status', title: 'Report acknowledged', message: 'Your report was acknowledged.', occurred_at: new Date().toISOString() }];
    const fetchImpl = async (url, options) => {
        assert.equal(options.credentials, 'same-origin'); assert.equal(options.cache, 'no-store');
        if (url === '/events') {
            if (failEvents()) return Response.json({ message: 'Activity service unavailable' }, { status: 503 });
            const event = JSON.parse(options.body); sent.push(event);
            if (event.kind === 'app_update_available') notifications.push({ id: 2, kind: event.kind, title: 'App update available', message: 'New build', occurred_at: new Date().toISOString() });
            return Response.json({ saved: true });
        }
        if (url === '/read') {
            const { ids } = JSON.parse(options.body); assert.deepEqual(ids, [1, 2]); notifications.splice(0);
            return Response.json({ saved: true });
        }
        return Response.json({ version: latest, unread_count: notifications.length, notifications });
    };
    initializeCivilianSystem(root, { document: doc, window: win, navigator: nav, fetchImpl, storage: storage() });
    return { doc, win, nav, elements, sent };
};

test('floating modal counts report/build updates, marks only shown entries, and applies an update only after draft preparation', async () => {
    const { doc, win, elements, sent } = fixture(); let applied = 0, finish;
    win.addEventListener('stitch:apply-application-update', () => applied++);
    await settle();
    assert.equal(elements['civilian-update-count'].textContent, '2'); assert.equal(applied, 0);
    assert.equal(elements['civilian-apply-update'].hidden, false);
    elements['civilian-updates-toggle'].dispatchEvent(new Event('click')); await settle();
    assert.equal(elements['civilian-updates-modal'].hidden, false); assert.equal(elements['civilian-updates-toggle'].attributes['aria-expanded'], 'true');
    elements['civilian-updates-read'].dispatchEvent(new Event('click')); await settle();
    assert.equal(elements['civilian-update-count'].hidden, true);
    doc.addEventListener('stitch:prepare-app-update', event => event.detail.pending.push(new Promise(resolve => { finish = resolve; })));
    elements['civilian-apply-update'].dispatchEvent(new Event('click')); await settle();
    assert.equal(applied, 0); assert.equal(elements['civilian-apply-update'].disabled, true);
    finish(); await settle();
    assert.equal(applied, 1); assert(sent.some(event => event.kind === 'app_update_requested'));
});

test('a failed draft save prevents reload and leaves an actionable update status', async () => {
    const { doc, win, elements } = fixture(); await settle(); let applied = 0;
    win.addEventListener('stitch:apply-application-update', () => applied++);
    doc.addEventListener('stitch:prepare-app-update', event => event.detail.pending.push(Promise.reject(new Error('Scene photo could not be saved.'))));
    elements['civilian-apply-update'].dispatchEvent(new Event('click')); await settle();
    assert.equal(applied, 0); assert.match(elements['app-update-status'].textContent, /photo could not be saved/);
    assert.equal(elements['civilian-apply-update'].disabled, false);
});

test('device telemetry failures do not hide server report notifications and durable retries do not block a requested update', async () => {
    const { win, elements } = fixture({ failEvents: () => true }); await settle();
    assert.equal(elements['civilian-update-count'].textContent, '1');
    assert.match(elements['app-update-status'].textContent, /report updates are still shown/);
    let applied = 0; win.addEventListener('stitch:apply-application-update', () => applied++);
    elements['civilian-apply-update'].dispatchEvent(new Event('click')); await settle();
    assert.equal(applied, 1);
});

test('update preparation waits for photo persistence and refuses a text-only photo fallback or active transmission', async () => {
    const form = new EventTarget(), doc = new EventTarget(), win = new EventTarget();
    win.setTimeout = setTimeout; win.clearTimeout = clearTimeout;
    let paused = false, mode = 'full';
    watchCivilianDraftRecovery({ form, document: doc, window: win, paused: () => paused, checkpoint() {}, persist: async () => ({ evidenceName: 'scene.jpg', storageMode: mode }), saved() {}, failed() {} });
    const pending = [];
    doc.dispatchEvent(new CustomEvent('stitch:prepare-app-update', { detail: { pending } })); await Promise.all(pending);
    mode = 'text-only'; const fallback = [];
    doc.dispatchEvent(new CustomEvent('stitch:prepare-app-update', { detail: { pending: fallback } })); await assert.rejects(Promise.all(fallback), /photo could not be saved/);
    paused = true; const sending = [];
    doc.dispatchEvent(new CustomEvent('stitch:prepare-app-update', { detail: { pending: sending } })); await assert.rejects(Promise.all(sending), /stop sending/);
});

test('copy text uses the clipboard and provides selected text when clipboard access is denied', async () => {
    const text = { value: '2026-11-07 — Report acknowledged', hidden: true, focus() { this.focused = true; }, select() { this.selected = true; } }, feedback = {};
    let copied;
    await copySystemLog(text, feedback, { navigator: { clipboard: { writeText: async value => { copied = value; } } } });
    assert.equal(copied, text.value); assert.equal(feedback.textContent, 'Log text copied.');
    await copySystemLog(text, feedback, { navigator: {} });
    assert.equal(text.hidden, false); assert.equal(text.selected, true); assert.match(feedback.textContent, /Select Copy/);
});
