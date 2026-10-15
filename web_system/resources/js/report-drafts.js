import { onDomReady } from './dom-ready';
const DB_NAME = 'bontoc-rescue-pwa';
const DB_VERSION = 1;
const STORE_NAME = 'reportDrafts';
const WORKING_DRAFT_ID = 'report-working-draft';
const ACTIVE_QUEUE_KEY = 'bontoc-rescue-active-queued-draft';
const FALLBACK_WORKING_KEY = 'bontoc-rescue-working-draft-fallback';
const FALLBACK_QUEUE_KEY = 'bontoc-rescue-queued-drafts-fallback';

const supportsIndexedDb = () => 'indexedDB' in window;

const formatTimestamp = (value) => {
    if (!value) {
        return null;
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return null;
    }

    return date.toLocaleString([], {
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });
};

const openDraftDatabase = () =>
    new Promise((resolve, reject) => {
        if (!supportsIndexedDb()) {
            resolve(null);
            return;
        }

        const request = window.indexedDB.open(DB_NAME, DB_VERSION);

        request.onerror = () => reject(request.error);
        request.onupgradeneeded = () => {
            const database = request.result;

            if (!database.objectStoreNames.contains(STORE_NAME)) {
                const store = database.createObjectStore(STORE_NAME, { keyPath: 'id' });
                store.createIndex('queue', 'queue', { unique: false });
                store.createIndex('updatedAt', 'updatedAt', { unique: false });
            }
        };
        request.onsuccess = () => resolve(request.result);
    });

const withDraftStore = async (mode, handler) => {
    const database = await openDraftDatabase();

    if (!database) {
        return null;
    }

    return new Promise((resolve, reject) => {
        const transaction = database.transaction(STORE_NAME, mode);
        const store = transaction.objectStore(STORE_NAME);
        const result = handler(store);

        transaction.oncomplete = () => {
            database.close();
            resolve(result);
        };

        transaction.onerror = () => {
            database.close();
            reject(transaction.error);
        };
    });
};

const getFallbackQueue = () => {
    try {
        return JSON.parse(window.localStorage.getItem(FALLBACK_QUEUE_KEY) ?? '[]');
    } catch {
        return [];
    }
};

const setFallbackQueue = (value) => {
    window.localStorage.setItem(FALLBACK_QUEUE_KEY, JSON.stringify(value));
};

const getFallbackWorking = () => {
    try {
        return JSON.parse(window.localStorage.getItem(FALLBACK_WORKING_KEY) ?? 'null');
    } catch {
        return null;
    }
};

const cloneFile = (file) => {
    if (!(file instanceof File)) {
        return null;
    }

    return new File([file], file.name, {
        type: file.type,
        lastModified: file.lastModified,
    });
};

const pickActiveFile = (...inputs) => {
    for (const input of inputs) {
        if (input instanceof HTMLInputElement && input.files?.[0] instanceof File) {
            return cloneFile(input.files[0]);
        }
    }

    return null;
};

const deleteDraftRecord = async (id) => {
    if (!supportsIndexedDb()) {
        if (id === WORKING_DRAFT_ID) {
            window.localStorage.removeItem(FALLBACK_WORKING_KEY);
        } else {
            const queue = getFallbackQueue().filter((item) => item.id !== id);
            setFallbackQueue(queue);
        }

        return;
    }

    await withDraftStore('readwrite', (store) => {
        store.delete(id);
    });
};

const getDraftRecord = async (id) => {
    if (!supportsIndexedDb()) {
        if (id === WORKING_DRAFT_ID) {
            return getFallbackWorking();
        }

        return getFallbackQueue().find((item) => item.id === id) ?? null;
    }

    return new Promise(async (resolve, reject) => {
        try {
            const database = await openDraftDatabase();

            if (!database) {
                resolve(null);
                return;
            }

            const transaction = database.transaction(STORE_NAME, 'readonly');
            const store = transaction.objectStore(STORE_NAME);
            const request = store.get(id);

            request.onsuccess = () => resolve(request.result ?? null);
            request.onerror = () => reject(request.error);
            transaction.oncomplete = () => database.close();
        } catch (error) {
            reject(error);
        }
    });
};

const getQueuedDrafts = async () => {
    if (!supportsIndexedDb()) {
        return getFallbackQueue().sort((a, b) => new Date(b.updatedAt) - new Date(a.updatedAt));
    }

    return new Promise(async (resolve, reject) => {
        try {
            const database = await openDraftDatabase();

            if (!database) {
                resolve([]);
                return;
            }

            const transaction = database.transaction(STORE_NAME, 'readonly');
            const store = transaction.objectStore(STORE_NAME);
            const request = store.getAll();

            request.onsuccess = () => {
                const drafts = (request.result ?? [])
                    .filter((item) => item.queue)
                    .sort((a, b) => new Date(b.updatedAt) - new Date(a.updatedAt));

                resolve(drafts);
            };
            request.onerror = () => reject(request.error);
            transaction.oncomplete = () => database.close();
        } catch (error) {
            reject(error);
        }
    });
};

const clearAllDrafts = async () => {
    if (!supportsIndexedDb()) {
        window.localStorage.removeItem(FALLBACK_WORKING_KEY);
        window.localStorage.removeItem(FALLBACK_QUEUE_KEY);
        window.localStorage.removeItem(ACTIVE_QUEUE_KEY);
        return;
    }

    await withDraftStore('readwrite', (store) => {
        store.clear();
    });

    window.localStorage.removeItem(ACTIVE_QUEUE_KEY);
};

const saveDraftRecord = async (record) => {
    if (!supportsIndexedDb()) {
        const textOnlyRecord = {
            ...record,
            evidenceFile: null,
            selfieFile: null,
            fileName: record.evidenceFile?.name ?? null,
            selfieFileName: record.selfieFile?.name ?? null,
        };

        if (record.id === WORKING_DRAFT_ID) {
            window.localStorage.setItem(FALLBACK_WORKING_KEY, JSON.stringify(textOnlyRecord));
        } else {
            const queue = getFallbackQueue().filter((item) => item.id !== record.id);
            queue.push(textOnlyRecord);
            setFallbackQueue(queue);
        }

        return;
    }

    await withDraftStore('readwrite', (store) => {
        store.put(record);
    });
};

const restoreDraftFile = (input, file, emptyMessage, manualMessage) => {
    if (!input) {
        return emptyMessage;
    }

    if (!file) {
        input.value = '';
        return null;
    }

    if (typeof DataTransfer === 'undefined') {
        return manualMessage;
    }

    try {
        const transfer = new DataTransfer();
        transfer.items.add(file);
        input.files = transfer.files;
        return null;
    } catch {
        return manualMessage;
    }
};

const getDraftFields = (form) => {
    const fieldNames = ['incident_type', 'transmission_type', 'severity', 'description', 'location_text', 'latitude', 'longitude', 'gps_fallback_used'];

    return fieldNames.reduce((fields, name) => {
        const element = form.elements.namedItem(name);
        fields[name] = element && 'value' in element ? element.value : '';
        return fields;
    }, {});
};

const hasMeaningfulDraftContent = (fields, evidenceFile, selfieFile) => {
    return [
        fields.description,
        fields.location_text,
        fields.latitude,
        fields.longitude,
        fields.incident_type !== 'General Emergency' ? fields.incident_type : '',
        evidenceFile?.name ?? '',
        selfieFile?.name ?? '',
    ].some((value) => String(value ?? '').trim() !== '');
};

const clearSuccessDrafts = async () => {
    await deleteDraftRecord(WORKING_DRAFT_ID);

    const activeQueuedDraftId = window.localStorage.getItem(ACTIVE_QUEUE_KEY);
    if (activeQueuedDraftId) {
        await deleteDraftRecord(activeQueuedDraftId);
        window.localStorage.removeItem(ACTIVE_QUEUE_KEY);
    }
};

onDomReady( () => {
    const successMarker = document.querySelector('[data-report-draft-clear-on-success]');
    if (successMarker) {
        void clearSuccessDrafts();
    }

    const form = document.querySelector('[data-report-draft-form]');

    if (!(form instanceof HTMLFormElement)) {
        return;
    }

    const evidenceInput = form.querySelector('input[name="evidence"]');
    const evidencePhotoInput = form.querySelector('input[name="evidence_photo_capture"]');
    const evidenceVideoInput = form.querySelector('input[name="evidence_video_capture"]');
    const selfieInput = form.querySelector('input[name="selfie"]');
    const selfieCaptureInput = form.querySelector('input[name="selfie_capture"]');
    const queueCount = document.querySelector('[data-draft-queue-count]');
    const autosaveState = document.querySelector('[data-draft-autosave-state]');
    const status = document.querySelector('[data-draft-status]');
    const saveButton = document.querySelector('[data-draft-save]');
    const restoreButton = document.querySelector('[data-draft-restore]');
    const clearButton = document.querySelector('[data-draft-clear]');

    let autosaveTimer = null;

    const setStatus = (message) => {
        if (status) {
            status.textContent = message;
        }
    };

    const setAutosaveState = (message) => {
        if (autosaveState) {
            autosaveState.textContent = message;
        }
    };

    const refreshDraftSummary = async () => {
        const queuedDrafts = await getQueuedDrafts();
        const workingDraft = await getDraftRecord(WORKING_DRAFT_ID);
        const countLabel = queuedDrafts.length === 1 ? '1 queued' : `${queuedDrafts.length} queued`;

        if (queueCount) {
            queueCount.textContent = countLabel;
        }

        if (workingDraft?.updatedAt) {
            const formatted = formatTimestamp(workingDraft.updatedAt);
            setAutosaveState(formatted ? `Saved ${formatted}` : 'Autosave ready');
        } else {
            setAutosaveState(supportsIndexedDb() ? 'Autosave ready' : 'Text-only backup');
        }
    };

    const buildDraftRecord = async ({ queue }) => {
        const fields = getDraftFields(form);
        const evidenceFile = pickActiveFile(evidencePhotoInput, evidenceVideoInput, evidenceInput);
        const selfieFile = pickActiveFile(selfieCaptureInput, selfieInput);

        if (!hasMeaningfulDraftContent(fields, evidenceFile, selfieFile)) {
            return null;
        }

        const existingWorkingDraft = !queue ? await getDraftRecord(WORKING_DRAFT_ID) : null;
        const id = queue ? `queued-${Date.now()}` : WORKING_DRAFT_ID;
        const createdAt = queue ? new Date().toISOString() : existingWorkingDraft?.createdAt ?? new Date().toISOString();

        return {
            id,
            queue,
            role: form.dataset.reportRole ?? 'civilian',
            createdAt,
            updatedAt: new Date().toISOString(),
            fields,
            evidenceFile,
            selfieFile,
        };
    };

    const saveWorkingDraft = async () => {
        const record = await buildDraftRecord({ queue: false });

        if (!record) {
            await deleteDraftRecord(WORKING_DRAFT_ID);
            await refreshDraftSummary();
            return;
        }

        await saveDraftRecord(record);
        await refreshDraftSummary();
    };

    const queueCurrentDraft = async (sourceLabel) => {
        const record = await buildDraftRecord({ queue: true });

        if (!record) {
            setStatus('Add incident details first before saving or queueing a draft.');
            return null;
        }

        await saveDraftRecord(record);
        window.localStorage.setItem(ACTIVE_QUEUE_KEY, record.id);
        await refreshDraftSummary();
        setStatus(`${sourceLabel} saved to the offline draft queue at ${formatTimestamp(record.updatedAt) ?? 'just now'}.`);

        return record;
    };

    const applyDraftToForm = async (record) => {
        if (!record) {
            setStatus('No saved draft is available on this device yet.');
            return;
        }

        Object.entries(record.fields ?? {}).forEach(([name, value]) => {
            const element = form.elements.namedItem(name);

            if (element && 'value' in element) {
                element.value = value ?? '';
                element.dispatchEvent(new Event('input', { bubbles: true }));
                element.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });

        const restoreMessages = [
            restoreDraftFile(
                evidenceInput,
                record?.evidenceFile ?? null,
                'No evidence input is available on this page.',
                'Draft fields were restored, but the evidence file must be reattached manually on this browser.'
            ),
            restoreDraftFile(
                selfieInput,
                record?.selfieFile ?? null,
                'No selfie input is available on this page.',
                'Draft fields were restored, but the verification selfie must be reattached manually on this browser.'
            ),
        ].filter(Boolean);

        if (evidencePhotoInput instanceof HTMLInputElement) {
            evidencePhotoInput.value = '';
        }

        if (evidenceVideoInput instanceof HTMLInputElement) {
            evidenceVideoInput.value = '';
        }

        if (selfieCaptureInput instanceof HTMLInputElement) {
            selfieCaptureInput.value = '';
        }

        if (evidenceInput instanceof HTMLInputElement) {
            evidenceInput.dispatchEvent(new Event('change', { bubbles: true }));
        }

        if (selfieInput instanceof HTMLInputElement) {
            selfieInput.dispatchEvent(new Event('change', { bubbles: true }));
        }

        if (record.queue) {
            window.localStorage.setItem(ACTIVE_QUEUE_KEY, record.id);
        } else {
            window.localStorage.removeItem(ACTIVE_QUEUE_KEY);
        }

        await refreshDraftSummary();
        setStatus(
            restoreMessages[0] ?? `Draft restored from ${formatTimestamp(record.updatedAt) ?? 'saved state'}. Review it before sending.`
        );
    };

    const restoreLatestDraft = async () => {
        const queuedDrafts = await getQueuedDrafts();
        const latestQueuedDraft = queuedDrafts[0] ?? null;

        if (latestQueuedDraft) {
            await applyDraftToForm(latestQueuedDraft);
            return;
        }

        const workingDraft = await getDraftRecord(WORKING_DRAFT_ID);
        await applyDraftToForm(workingDraft);
    };

    const scheduleAutosave = () => {
        if (autosaveTimer) {
            window.clearTimeout(autosaveTimer);
        }

        autosaveTimer = window.setTimeout(() => {
            void saveWorkingDraft();
        }, 700);
    };

    const relevantFields = Array.from(form.querySelectorAll('input, textarea, select')).filter(
        (element) => element instanceof HTMLInputElement || element instanceof HTMLTextAreaElement || element instanceof HTMLSelectElement
    );

    relevantFields.forEach((element) => {
        const eventName = element instanceof HTMLTextAreaElement || (element instanceof HTMLInputElement && element.type !== 'file')
            ? 'input'
            : 'change';

        element.addEventListener(eventName, scheduleAutosave);
        element.addEventListener('change', scheduleAutosave);
    });

    saveButton?.addEventListener('click', () => {
        void queueCurrentDraft('Draft');
    });

    restoreButton?.addEventListener('click', () => {
        void restoreLatestDraft();
    });

    clearButton?.addEventListener('click', () => {
        const confirmed = window.confirm('Clear the local working draft and every queued draft saved on this device?');

        if (!confirmed) {
            return;
        }

        void clearAllDrafts().then(() => {
            setStatus('All local drafts were cleared from this device.');
            return refreshDraftSummary();
        });
    });

    window.addEventListener('online', () => {
        setStatus('Internet is back. You can restore a queued draft and send it now.');
    });

    window.addEventListener('offline', () => {
        setStatus('You are offline. Keep encoding and use the draft queue so your report stays on this device.');
    });

    form.addEventListener('submit', (event) => {
        if (window.navigator.onLine) {
            return;
        }

        event.preventDefault();
        void queueCurrentDraft('Offline submission');
    });

    void refreshDraftSummary();
});
