import { onDomReady } from './dom-ready';
import { createCivilianDraftStore, isSubmissionId } from './civilian-draft-store';
import { checkReportReceipt, uploadReport, receivedReportUrl } from './report-delivery';

const messages = {
    editing: 'Draft — not sent', sending: 'Delivery unconfirmed — check before sending again',
    offline: 'Draft — connection lost', connection: 'Draft — connection interrupted',
    interrupted: 'Draft — sending interrupted', slow: 'Draft — sending took too long',
    server: 'Draft — server receipt unconfirmed', session: 'Draft — sign in to continue',
    validation: 'Draft — review details and verification',
};
const element = (tag, text, className = '') => {
    const node = document.createElement(tag); node.textContent = text; node.className = className; return node;
};

onDomReady(() => {
    const panel = document.querySelector('[data-civilian-drafts]');
    if (!panel) return;
    const store = createCivilianDraftStore(panel.dataset.owner);
    const form = document.querySelector('[data-report-draft-form][data-report-role="civilian"]');
    const list = panel.querySelector('[data-civilian-draft-list]');
    const counter = panel.querySelector('[data-civilian-draft-count]');
    const saveButton = form?.querySelector('[data-civilian-draft-save]');
    const feedback = form?.querySelector('[data-civilian-draft-feedback]');
    const submitButton = form?.querySelector('[data-report-submit]');
    let records = [], saving = Promise.resolve(), timer, controller = null, busy = false, restoring = false;
    const notice = (text) => {
        if (feedback) feedback.textContent = text;
        panel.querySelector('[data-civilian-draft-notice]').textContent = text;
    };
    const storageError = () => notice('Could not save a local draft. Keep this page open and check your device storage. Your report has not been confirmed as received.');
    const receiptUrl = (id) => panel.dataset.receiptUrl.replace('__submission__', encodeURIComponent(id));
    const currentId = () => form?.elements.namedItem('submission_id')?.value;
    const fileInput = (name) => form?.querySelector(`input[name="${name}"]`);
    const selectedPhoto = () => fileInput('evidence_photo_capture')?.files?.[0] ?? fileInput('evidence')?.files?.[0] ?? null;

    const render = async () => {
        records = await store.list();
        panel.hidden = records.length === 0;
        counter.textContent = String(records.length);
        list.replaceChildren();
        for (const record of records) {
            const card = element('article', '', 'civilian-draft-card');
            card.dataset.draftId = record.submissionId;
            card.append(element('span', messages[record.state] ?? messages.editing, 'tag amber'));
            card.append(element('p', record.fields.description || 'Road Accident report'));
            card.append(element('p', record.fields.location_text || 'Location details not entered yet.', 'section-copy'));
            card.append(element('p', `Saved ${new Date(record.updatedAt).toLocaleString()}`, 'section-copy'));
            const hasPhoto = record.evidenceFile instanceof Blob;
            card.append(element('p', hasPhoto ? 'Scene photo saved with this draft.' : record.evidenceName ? 'Text saved. Reattach the scene photo when continuing.' : 'Scene photo still required.', 'section-copy'));
            const actions = element('div', '', 'button-row');
            const resume = element('button', 'Continue', 'btn btn-primary'); resume.type = 'button'; resume.disabled = busy;
            resume.addEventListener('click', () => { void continueDraft(record).catch(storageError); });
            const remove = element('button', 'Delete draft', 'btn btn-secondary'); remove.type = 'button'; remove.disabled = busy;
            remove.addEventListener('click', () => {
                if (busy || !window.confirm('Delete this local draft? This does not change any report already received by the server.')) return;
                void store.remove(record.submissionId).then(render).catch(storageError);
            });
            actions.append(resume, remove); card.append(actions); list.append(card);
        }
    };

    const snapshot = (state, attempted) => {
        if (!form || !isSubmissionId(currentId())) return null;
        const fields = {
            description: form.elements.namedItem('description')?.value ?? '',
            location_text: form.elements.namedItem('location_text')?.value ?? '',
        };
        const evidenceFile = selectedPhoto();
        if (!fields.description.trim() && !fields.location_text.trim() && !evidenceFile) return null;
        const previous = records.find((record) => record.submissionId === currentId());
        return {
            submissionId: currentId(), fields, evidenceFile, evidenceName: evidenceFile?.name ?? previous?.evidenceName,
            createdAt: previous?.createdAt ?? new Date().toISOString(), updatedAt: new Date().toISOString(),
            state: state ?? previous?.state ?? 'editing', attempted: attempted ?? previous?.attempted ?? false,
        };
    };
    const save = (state, attempted) => {
        const record = snapshot(state, attempted);
        if (!record) return Promise.resolve(null);
        const job = saving.catch(() => {}).then(async () => {
            const saved = await store.put(record); await render(); return saved;
        });
        saving = job;
        return job;
    };
    const savedNotice = (record, lead = 'Draft saved on this device.') => {
        if (!record) { notice('Enter a description, location, or scene photo before saving a draft.'); return; }
        notice(lead + (record.storageMode === 'text-only' && record.evidenceName ? ' Photo storage is unavailable; reattach the scene photo when continuing.' : '') + ' A fresh GPS lock and live selfie check are required before sending.');
    };
    const confirmReceipt = async (data, id) => {
        const url = receivedReportUrl(data);
        if (!url) return false;
        // A received report is authoritative even if local cleanup fails. The
        // same submission ID lets Continue reconcile a leftover draft later.
        try { await store.remove(id); } catch { /* Do not hide a confirmed server receipt. */ }
        notice('The server received this report. Opening its confirmation.');
        window.location.assign(url);
        return true;
    };

    async function continueDraft(record) {
        if (busy) return;
        if (record.attempted && navigator.onLine) {
            notice('Checking whether the server already received this report…');
            try { if (await confirmReceipt(await checkReportReceipt(receiptUrl(record.submissionId)), record.submissionId)) return; }
            catch { /* Preserve the draft; Send checks again before any retry. */ }
        }
        if (!form) {
            if (!navigator.onLine) { notice('Your draft is saved. Reconnect to open the report form and continue.'); return; }
            window.location.assign(`${panel.dataset.createUrl}?draft=${encodeURIComponent(record.submissionId)}`);
            return;
        }
        clearTimeout(timer);
        if (currentId() !== record.submissionId) await save();
        await saving.catch(() => {});
        restoring = true;
        try {
            form.elements.namedItem('submission_id').value = record.submissionId;
            for (const [name, value] of Object.entries(record.fields)) {
                const input = form.elements.namedItem(name); if (!input) continue;
                input.value = value; input.dispatchEvent(new Event('input', { bubbles: true }));
            }
            for (const name of ['evidence', 'evidence_photo_capture', 'selfie', 'selfie_capture']) {
                const input = fileInput(name); if (input) input.value = '';
            }
            let restoredPhoto = false;
            if (record.evidenceFile instanceof Blob && typeof DataTransfer !== 'undefined') {
                try {
                    const transfer = new DataTransfer();
                    transfer.items.add(new File([record.evidenceFile], record.evidenceName || 'scene.jpg', { type: record.evidenceFile.type }));
                    fileInput('evidence').files = transfer.files; restoredPhoto = true;
                } catch { /* Explain browsers that require manual reattachment. */ }
            }
            for (const name of ['evidence', 'selfie', 'selfie_capture']) fileInput(name)?.dispatchEvent(new Event('change', { bubbles: true }));
            form.dispatchEvent(new Event('stitch:report-draft-restored'));
            notice('Draft restored. Review your details, lock GPS, and complete a new live selfie check.' + (!restoredPhoto && record.evidenceName ? ' Reattach the scene photo before sending.' : ''));
            form.scrollIntoView({ behavior: 'smooth', block: 'start' });
        } finally { restoring = false; }
    }

    if (form) {
        const disabledBefore = new Map();
        const toggleSending = (sending) => {
            busy = sending; form.dataset.reportSending = String(sending);
            form.setAttribute('aria-busy', String(sending));
            if (sending) {
                for (const input of form.querySelectorAll('input, textarea, select, button')) {
                    if (input === saveButton || input.type === 'hidden') continue;
                    disabledBefore.set(input, input.disabled); input.disabled = true;
                }
            } else {
                for (const [input, disabled] of disabledBefore) input.disabled = disabled;
                disabledBefore.clear();
            }
            if (submitButton) submitButton.textContent = sending ? 'Sending report…' : 'Send Report';
            if (saveButton) saveButton.textContent = sending ? 'Stop sending and keep draft' : 'Save draft';
            for (const button of list.querySelectorAll('button')) button.disabled = sending;
        };
        const send = async () => {
            if (busy) return;
            clearTimeout(timer);
            const body = new FormData(form), id = currentId();
            toggleSending(true); controller = new AbortController();
            let slowTimer, saved = null, attempted = false;
            try {
                await saving.catch(() => {});
                const previous = await store.get(id).catch(() => null);
                if (previous?.attempted) {
                    notice('Checking the previous attempt before sending again…');
                    if (await confirmReceipt(await checkReportReceipt(receiptUrl(id), { signal: controller.signal }), id)) return;
                }
                try { saved = await save('sending', true); } catch { storageError(); }
                notice(saved ? (saved.storageMode === 'text-only'
                    ? 'Sending report. Only a text backup could be saved; photo storage is unavailable. Keep this page open until receipt is confirmed.'
                    : 'Sending report. A recovery draft is saved on this device; wait for the server confirmation.')
                    : 'Sending report. Local backup is unavailable; keep this page open until receipt is confirmed.');
                slowTimer = setTimeout(() => notice(saved ? 'Sending is taking longer than usual. Your draft is saved. You can stop sending and continue later; receipt is not confirmed yet.' : 'Sending is taking longer than usual. Local draft storage is unavailable; keep this page open.'), 15000);
                attempted = true;
                const data = await uploadReport(form.action, body, { signal: controller.signal });
                if (!await confirmReceipt(data, id)) throw new Error('The server did not return a valid receipt.');
            } catch (error) {
                clearTimeout(slowTimer);
                try { saved = await save(error.reason ?? 'server', attempted || records.find((item) => item.submissionId === id)?.attempted); }
                catch { saved = null; }
                const lead = saved ? `${error.message} Draft saved on this device.` : 'Receipt is unconfirmed and local saving failed. Keep this page open and check your connection and device storage.';
                savedNotice(saved, lead);
                if (!saved) storageError();
            } finally {
                clearTimeout(slowTimer); controller = null; toggleSending(false);
                // Neither an old location nor a consumed/expired liveness
                // proof is valid authorization for a resumed transmission.
                form.dispatchEvent(new Event('stitch:report-draft-restored'));
            }
        };
        form.addEventListener('input', () => {
            if (restoring || busy) return;
            clearTimeout(timer); timer = setTimeout(() => { void save().then((record) => { if (record) savedNotice(record); }).catch(storageError); }, 500);
        });
        form.addEventListener('change', () => {
            if (restoring || busy) return;
            clearTimeout(timer); void save().then((record) => { if (record) savedNotice(record); }).catch(storageError);
        });
        saveButton?.addEventListener('click', () => {
            if (busy) { controller?.abort('interrupted'); return; }
            clearTimeout(timer); void save(navigator.onLine ? 'editing' : 'offline').then((record) => savedNotice(record)).catch(storageError);
        });
        // Capture offline attempts before GPS/selfie validation can open a
        // network-dependent modal. Partial drafts never need those checks.
        form.addEventListener('submit', (event) => {
            if (busy || !navigator.onLine) {
                event.preventDefault(); event.stopImmediatePropagation();
                if (!busy) void save('offline').then((record) => savedNotice(record, 'You are offline. Draft saved on this device.')).catch(storageError);
            }
        }, true);
        form.addEventListener('submit', (event) => {
            if (event.defaultPrevented) return; // Existing photo, GPS and live-check gate still owns validation.
            event.preventDefault(); void send();
        });
        window.addEventListener('offline', () => {
            if (controller) { controller.abort('offline'); return; }
            clearTimeout(timer); void save('offline').then((record) => { if (record) savedNotice(record, 'Connection lost. Draft saved on this device.'); }).catch(storageError);
        });
    }
    window.addEventListener('online', () => notice('Connection is available again. Continue your saved draft and review it before sending. Reports are not sent automatically.'));
    void render().then(async () => {
        const id = new URL(window.location.href).searchParams.get('draft');
        if (form && isSubmissionId(id)) {
            const record = await store.get(id);
            if (record) await continueDraft(record); else notice('This draft is not available for this account on this device.');
        }
    }).catch(storageError);
});
