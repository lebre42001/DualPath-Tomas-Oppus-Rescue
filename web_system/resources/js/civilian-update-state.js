export const isBuildVersion = value => typeof value === 'string' && /^[a-f0-9]{16}$/.test(value);
const uuid = () => globalThis.crypto?.randomUUID?.() ?? '10000000-1000-4000-8000-100000000000'.replace(/[018]/g, digit => (Number(digit) ^ Math.random() * 16 >> Number(digit) / 4).toString(16));

// Only device app-version events live here. Server-recorded report/auth events
// never come from the browser. Keys and retry queues are scoped to the account.
export function createCivilianUpdateState(owner, running, { storage, newId = uuid, now = () => new Date().toISOString(), keyPrefix = 'aksyres-system-state:v1:' } = {}) {
    if (!/^\d+$/.test(String(owner)) || !isBuildVersion(running)) throw new Error('Invalid account or build.');
    if (storage === undefined) { try { storage = globalThis.localStorage; } catch { storage = null; } }
    const key = `${keyPrefix}${owner}`;
    let data = {}, flushing = null, persistent = true;
    try { data = JSON.parse(storage?.getItem(key) ?? '{}') ?? {}; } catch { data = {}; }
    if (typeof data !== 'object' || Array.isArray(data)) data = {};
    data.pending = Array.isArray(data.pending) ? data.pending.filter(event =>
        event && ['app_version_seen', 'app_update_available', 'app_update_requested', 'app_update_applied', 'app_update_failed', 'app_connection_offline', 'app_connection_online'].includes(event.kind) && isBuildVersion(event.version)) : [];
    data.notified = Array.isArray(data.notified) ? data.notified.filter(isBuildVersion) : [];
    const persist = () => { try { if (!storage) throw new Error(); storage.setItem(key, JSON.stringify(data)); persistent = true; } catch { persistent = false; } };
    const enqueue = (kind, version, previous = null) => {
        if (!isBuildVersion(version)) return;
        if (['app_version_seen', 'app_update_available'].includes(kind) && data.pending.some(event => event.kind === kind && event.version === version)) return;
        data.pending.push({ event_id: newId(), kind, version, previous_version: isBuildVersion(previous) ? previous : null, occurred_at: now() });
        data.pending = data.pending.slice(-100); persist();
    };
    if (data.running !== running) {
        if (isBuildVersion(data.running)) { data.previous = data.running; enqueue('app_update_applied', running, data.running); }
        data.running = running; enqueue('app_version_seen', running);
    }
    persist();
    return {
        previous: () => isBuildVersion(data.previous) ? data.previous : null,
        persistent: () => persistent,
        enqueue,
        observeLatest(version) {
            if (!isBuildVersion(version) || version === running || data.notified.includes(version)) return false;
            data.notified = [...data.notified, version].slice(-30);
            enqueue('app_update_available', version); return true;
        },
        pending: () => [...data.pending],
        flush(send) {
            if (flushing) return flushing;
            flushing = (async () => {
                while (data.pending.length) {
                    const event = data.pending[0];
                    try { await send(event); }
                    catch (error) { if (![409, 422].includes(error.status)) throw error; } // Expired/malformed telemetry must not block current updates.
                    data.pending = data.pending.filter(item => item.event_id !== event.event_id); persist();
                }
            })().finally(() => { flushing = null; });
            return flushing;
        },
    };
}
