import test from 'node:test';
import assert from 'node:assert/strict';
import { createCivilianDraftStore } from '../../resources/js/civilian-draft-store.js';
import { checkReportReceipt, uploadReport, receivedReportUrl } from '../../resources/js/report-delivery.js';
import { watchCivilianDraftRecovery } from '../../resources/js/civilian-draft-recovery.js';

const id = 'f06f156c-d08c-4d3b-939a-670b1cef6ad9';
const draft = { submissionId: id, fields: { incident_type: 'Flood / Landslide', description: 'Vehicle collision', location_text: 'Bridge', latitude: 10, liveness_proof: 'do not persist' },
    createdAt: '2026-11-06T01:00:00Z', updatedAt: '2026-11-06T01:00:00Z', state: 'connection', attempted: true,
    evidenceFile: new File(['scene'], 'scene.jpg', { type: 'image/jpeg' }), selfieFile: new File(['private'], 'selfie.jpg'), csrf: 'private' };
const storage = () => { const data = new Map(); return { getItem: (key) => data.get(key) ?? null, setItem: (key, value) => data.set(key, value) }; };
const json = (body, status = 200) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });

test('fallback keeps the submission ID, text and interruption state after reload, and tells the truth about missing photo storage', async () => {
    const local = storage(), first = createCivilianDraftStore(1, { indexedDB: null, storage: local });
    const saved = await first.put(draft);
    assert.equal(saved.storageMode, 'text-only'); assert.equal(saved.evidenceFile, null);
    const restored = await createCivilianDraftStore(1, { indexedDB: null, storage: local }).get(id);
    assert.equal(restored.submissionId, id); assert.equal(restored.attempted, true);
    assert.equal(restored.fields.description, draft.fields.description); assert.equal(restored.evidenceName, 'scene.jpg');
    assert.equal(restored.fields.incident_type, 'Flood / Landslide');
    assert.deepEqual(Object.keys(restored.fields).sort(), ['description', 'incident_type', 'location_text']);
    assert.equal(restored.selfieFile, undefined); assert.equal(restored.csrf, undefined);
});
test('one account cannot list or delete another account’s drafts on a shared device', async () => {
    const local = storage(), a = createCivilianDraftStore(1, { indexedDB: null, storage: local }), b = createCivilianDraftStore(2, { indexedDB: null, storage: local });
    await a.put(draft); assert.deepEqual(await b.list(), []); await b.remove(id);
    assert.equal((await a.list()).length, 1); await a.remove(id); assert.deepEqual(await a.list(), []);
});
test('storage quota/privacy failures never claim that a draft was saved', async () => {
    const store = createCivilianDraftStore(1, { indexedDB: null, storage: { getItem: () => null, setItem: () => { throw new Error('Quota'); } } });
    await assert.rejects(store.put(draft), /Quota/);
    assert.throws(() => createCivilianDraftStore(''), /signed-in account/);
});
test('network failure is detected even when the browser thinks it is online', async () => {
    await assert.rejects(uploadReport('/reports', new FormData(), { fetchImpl: async () => { throw new TypeError('Failed to fetch'); } }), (error) => error.reason === 'connection');
});
test('slow uploads and explicit offline interruptions abort without claiming receipt', async () => {
    const stalled = async (_url, { signal }) => new Promise((_resolve, reject) => signal.addEventListener('abort', () => reject(new Error('Aborted')), { once: true }));
    await assert.rejects(uploadReport('/reports', new FormData(), { fetchImpl: stalled, timeoutMs: 5 }), (error) => error.reason === 'slow');
    const controller = new AbortController();
    const sending = uploadReport('/reports', new FormData(), { fetchImpl: stalled, signal: controller.signal }); controller.abort('offline');
    await assert.rejects(sending, (error) => error.reason === 'offline');
});
test('validation and expired sessions remain resumable rather than being mistaken for successful delivery', async () => {
    await assert.rejects(uploadReport('/reports', new FormData(), { fetchImpl: async () => json({ errors: { liveness_proof: ['Complete a new live check.'] } }, 422) }), (error) => error.reason === 'validation');
    await assert.rejects(uploadReport('/reports', new FormData(), { fetchImpl: async () => json({}, 419) }), (error) => error.reason === 'session');
});
test('receipt reconciliation is read-only, fresh and same-account authenticated; only a valid local success URL clears a draft', async () => {
    const response = await checkReportReceipt('/reports/submissions/'+id, { fetchImpl: async (_url, options) => {
        assert.equal(options.method, 'GET'); assert.equal(options.cache, 'no-store'); assert.equal(options.credentials, 'same-origin');
        return json({ received: true, redirect_url: 'https://app.test/reports/12/success' });
    } });
    assert.equal(receivedReportUrl(response, 'https://app.test'), 'https://app.test/reports/12/success');
    assert.equal(receivedReportUrl({ received: false, redirect_url: response.redirect_url }, 'https://app.test'), null);
    assert.equal(receivedReportUrl({ received: true, redirect_url: 'https://other.test/reports/12/success' }, 'https://app.test'), null);
    assert.equal(receivedReportUrl({ received: true, redirect_url: '/login' }, 'https://app.test'), null);
});

test('text is already on disk when a photo transaction has not opened yet, including immediately before app termination', async () => {
    const local = storage();
    let request;
    const store = createCivilianDraftStore(1, { storage: local, indexedDB: { open: () => request = {} } });
    const pending = store.put(draft);
    const afterRestart = createCivilianDraftStore(1, { storage: local, indexedDB: null });
    assert.equal((await afterRestart.get(id)).fields.description, draft.fields.description);
    assert.equal((await afterRestart.get(id)).storageMode, 'text-only');
    assert.equal((await createCivilianDraftStore(2, { storage: local, indexedDB: null }).list()).length, 0);
    request.onerror(); await pending;
});

const photoDatabase = () => {
    const rows = new Map();
    return { open() {
        const request = {};
        request.result = {
            close() {}, objectStoreNames: { contains: () => true },
            transaction() {
                const tx = {};
                const operation = action => {
                    const result = {};
                    setImmediate(() => { result.result = action(); tx.oncomplete(); });
                    return result;
                };
                tx.objectStore = () => ({
                    getAll: () => operation(() => [...rows.values()]),
                    put: row => operation(() => { rows.set(row.id, row); return row.id; }),
                    delete: key => operation(() => rows.delete(key)),
                });
                return tx;
            },
        };
        setImmediate(() => request.onsuccess());
        return request;
    } };
};

test('a newer shutdown checkpoint keeps its latest text and previously committed photo despite an older queued write', async () => {
    const local = storage(), indexedDB = photoDatabase();
    const store = createCivilianDraftStore(1, { storage: local, indexedDB });
    const original = await store.put(draft);
    store.checkpoint({ ...original, fields: { description: 'Latest unfinished text', location_text: 'New landmark' }, updatedAt: '2026-11-06T01:00:02Z' });
    await store.put({ ...draft, updatedAt: '2026-11-06T01:00:01Z' });
    const restored = await createCivilianDraftStore(1, { storage: local, indexedDB }).get(id);
    assert.equal(restored.fields.description, 'Latest unfinished text');
    assert.equal(restored.fields.location_text, 'New landmark');
    assert(restored.evidenceFile instanceof Blob);
    assert.equal(restored.storageMode, 'full');
    assert.equal((await store.list()).length, 1, 'One draft is counted once across both storage layers');
    await store.remove(id); assert.equal((await store.list()).length, 0);
});

test('a replacement photo that never committed cannot silently restore a different older photo', async () => {
    const local = storage(), indexedDB = photoDatabase();
    const store = createCivilianDraftStore(1, { storage: local, indexedDB });
    await store.put(draft);
    store.checkpoint({ ...draft, updatedAt: '2026-11-06T01:00:02Z', evidenceFile: new File(['new scene'], 'scene.jpg', { type: 'image/jpeg' }) });
    const restored = await store.get(id);
    assert.equal(restored.evidenceFile, null); assert.equal(restored.storageMode, 'text-only');
});

test('autosave checkpoints every edit, flushes on background/close, and cannot recreate a confirmed report', async () => {
    const form = new EventTarget(), win = new EventTarget(), doc = new EventTarget();
    const local = storage(), store = createCivilianDraftStore(1, { indexedDB: null, storage: local });
    let timer, paused = false, persists = 0, failures = 0, current = { ...draft, state: 'editing', attempted: false };
    win.clearTimeout = () => { timer = null; }; win.setTimeout = callback => { timer = callback; return 1; };
    watchCivilianDraftRecovery({ form, window: win, document: doc, paused: () => paused,
        checkpoint: () => store.checkpoint(current), persist: () => { persists++; return store.put(current); }, saved() {}, failed: () => failures++ });
    form.dispatchEvent(new Event('input'));
    assert.equal(persists, 0, 'The immediate checkpoint does not wait for the debounced full save');
    assert.equal((await store.get(id)).fields.description, draft.fields.description);
    current = { ...current, fields: { description: 'Last keystroke', location_text: '' }, updatedAt: '2026-11-06T01:00:03Z' };
    form.dispatchEvent(new Event('input'));
    assert.equal((await store.get(id)).fields.description, 'Last keystroke');
    doc.hidden = true; doc.dispatchEvent(new Event('visibilitychange'));
    await new Promise(resolve => setImmediate(resolve)); assert.equal(persists, 1);
    win.dispatchEvent(new Event('pagehide')); await new Promise(resolve => setImmediate(resolve)); assert.equal(persists, 2);
    paused = true; await store.remove(id);
    form.dispatchEvent(new Event('change')); win.dispatchEvent(new Event('pagehide'));
    assert.equal(persists, 2); assert.equal((await store.list()).length, 0); assert.equal(failures, 0); assert.equal(timer, null);
});
