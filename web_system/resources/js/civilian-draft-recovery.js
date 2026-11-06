// Save text synchronously on edits; do not depend on an unload handler running.
// Photo transactions are asynchronous and cannot survive a power loss before commit.
export function watchCivilianDraftRecovery({ form, checkpoint, persist, paused, saved, failed, window: win = window, document: doc = document }) {
    let timer;
    const cancel = () => win.clearTimeout(timer);
    const backup = () => { try { checkpoint(); } catch { /* The full store may still be available. */ } };
    const flush = () => {
        cancel();
        if (paused()) return;
        backup();
        void persist().then(record => { if (record) saved(record); }).catch(failed);
    };
    form.addEventListener('input', () => {
        if (paused()) return;
        backup(); cancel(); timer = win.setTimeout(flush, 250);
    });
    form.addEventListener('change', flush);
    doc.addEventListener('visibilitychange', () => { if (doc.hidden) flush(); });
    win.addEventListener('pagehide', flush);
    doc.addEventListener('stitch:prepare-app-update', event => {
        cancel();
        const prepare = async () => {
            if (paused()) throw new Error('Finish or stop sending the report before updating the app.');
            checkpoint();
            const record = await persist();
            if (record?.evidenceName && record.storageMode === 'text-only') throw new Error('The scene photo could not be saved. Keep this page open and finish or save the photo before updating.');
            if (record) saved(record);
        };
        event.detail.pending.push(prepare());
    });
    return { cancel, flush };
}
