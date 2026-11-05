const DB_NAME = 'aksyres-civilian-report-drafts';
const STORE = 'drafts';
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
export const isSubmissionId = (value) => UUID.test(String(value ?? ''));

// Separate records and fallback keys by the authenticated account. Never load
// the legacy, unowned shared drafts as another user's emergency report.
export function createCivilianDraftStore(owner, options = {}) {
    const available = (name) => { try { return globalThis[name]; } catch { return undefined; } };
    const indexedDB = 'indexedDB' in options ? options.indexedDB : available('indexedDB');
    const storage = 'storage' in options ? options.storage : available('localStorage');
    owner = String(owner ?? '');
    if (!/^\d+$/.test(owner)) throw new Error('A signed-in account is required for drafts.');
    const key = `aksyres-report-drafts-v2:${owner}`;
    const own = (record) => record?.owner === owner && isSubmissionId(record.submissionId);
    const fallback = () => {
        const items = JSON.parse(storage?.getItem(key) ?? '[]');
        return Array.isArray(items) ? items.filter(own) : [];
    };
    const writeFallback = (items) => {
        if (!storage) throw new Error('Local draft storage is unavailable.');
        storage.setItem(key, JSON.stringify(items));
    };
    const database = () => new Promise((resolve, reject) => {
        if (!indexedDB) return reject(new Error('Photo storage is unavailable.'));
        let settled = false;
        const timer = setTimeout(() => { settled = true; reject(new Error('Photo storage did not open.')); }, 2500);
        const request = indexedDB.open(DB_NAME, 1);
        const fail = () => { clearTimeout(timer); settled = true; reject(request.error ?? new Error('Photo storage is unavailable.')); };
        request.onerror = fail;
        request.onblocked = fail;
        request.onupgradeneeded = () => {
            if (!request.result.objectStoreNames.contains(STORE)) request.result.createObjectStore(STORE, { keyPath: 'id' });
        };
        request.onsuccess = () => {
            clearTimeout(timer);
            if (settled) request.result.close(); else { settled = true; resolve(request.result); }
        };
    });
    const transact = async (mode, operation) => {
        const db = await database();
        return new Promise((resolve, reject) => {
            const tx = db.transaction(STORE, mode);
            const request = operation(tx.objectStore(STORE));
            tx.oncomplete = () => { db.close(); resolve(request.result); };
            tx.onerror = tx.onabort = () => { db.close(); reject(tx.error ?? new Error('Could not save the draft.')); };
        });
    };
    return {
        async list() {
            let records;
            try { records = (await transact('readonly', (store) => store.getAll())).filter(own); }
            catch { return fallback().sort((a, b) => b.updatedAt.localeCompare(a.updatedAt)); }
            let extra = [];
            try { extra = fallback(); } catch { /* IndexedDB remains usable without localStorage. */ }
            const latest = new Map();
            for (const record of [...extra, ...records]) {
                const previous = latest.get(record.submissionId);
                if (!previous || previous.updatedAt <= record.updatedAt) latest.set(record.submissionId, record);
            }
            return [...latest.values()].sort((a, b) => b.updatedAt.localeCompare(a.updatedAt));
        },
        async get(submissionId) { return (await this.list()).find((record) => record.submissionId === submissionId) ?? null; },
        async put(value) {
            if (!isSubmissionId(value.submissionId)) throw new Error('The draft report ID is invalid. Reopen the report form.');
            // Only these report fields are persisted. No CSRF token, GPS fix,
            // liveness proof or verification selfie is stored or replayed.
            const record = {
                id: `${owner}:${value.submissionId}`, owner, submissionId: value.submissionId,
                fields: { description: String(value.fields?.description ?? ''), location_text: String(value.fields?.location_text ?? '') },
                createdAt: value.createdAt, updatedAt: value.updatedAt, state: value.state ?? 'editing',
                attempted: Boolean(value.attempted), evidenceFile: value.evidenceFile ?? null,
                evidenceName: value.evidenceFile?.name ?? value.evidenceName ?? null, storageMode: 'full',
            };
            try {
                await transact('readwrite', (store) => store.put(record));
                try { writeFallback(fallback().filter((item) => item.submissionId !== record.submissionId)); } catch { /* Optional mirror cleanup. */ }
                return record;
            } catch {
                const textOnly = { ...record, evidenceFile: null, storageMode: 'text-only' };
                writeFallback([...fallback().filter((item) => item.submissionId !== record.submissionId), textOnly]);
                return textOnly;
            }
        },
        async remove(submissionId) {
            if (!isSubmissionId(submissionId)) return;
            if (indexedDB) await transact('readwrite', (store) => store.delete(`${owner}:${submissionId}`));
            try { writeFallback(fallback().filter((record) => record.submissionId !== submissionId)); }
            catch (error) { if (!indexedDB) throw error; }
        },
    };
}
