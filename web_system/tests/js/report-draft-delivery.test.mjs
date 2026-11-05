import test from 'node:test';
import assert from 'node:assert/strict';
import { createCivilianDraftStore } from '../../resources/js/civilian-draft-store.js';
import { checkReportReceipt, uploadReport, receivedReportUrl } from '../../resources/js/report-delivery.js';

const id = 'f06f156c-d08c-4d3b-939a-670b1cef6ad9';
const draft = { submissionId: id, fields: { description: 'Vehicle collision', location_text: 'Bridge', latitude: 10, liveness_proof: 'do not persist' },
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
    assert.deepEqual(Object.keys(restored.fields).sort(), ['description', 'location_text']);
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
