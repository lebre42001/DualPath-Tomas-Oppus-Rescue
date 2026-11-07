const noticeWatermarks = new Map();
const reportEvent = event => event && typeof event.kind === 'string' && event.kind.startsWith('report_')
    && !['report_submitted', 'report_submission'].includes(event.kind);
const eventId = value => {
    if (!['number', 'string'].includes(typeof value) || !/^\d+$/.test(String(value))) return null;
    const id = Number(value);
    return Number.isSafeInteger(id) && id > 0 ? id : null;
};

// This is only a local display watermark. Server unread receipts and account
// history remain untouched, and no private report text is stored on the device.
export function createCivilianReportNoticeState(owner, { storage } = {}) {
    if (!/^\d+$/.test(String(owner))) throw new Error('Invalid account.');
    if (storage === undefined) { try { storage = globalThis.localStorage; } catch { storage = null; } }
    const key = `aksyres-report-notice:v1:${owner}`;
    let highest = noticeWatermarks.get(key) ?? 0, persistent = Boolean(storage);
    const read = () => {
        try {
            if (!storage) throw new Error('Storage unavailable.');
            const stored = eventId(storage.getItem(key));
            if (stored !== null) highest = Math.max(highest, stored);
        } catch { persistent = false; }
        highest = Math.max(highest, noticeWatermarks.get(key) ?? 0);
        noticeWatermarks.set(key, highest);
    };
    read();
    return {
        key,
        persistent: () => persistent,
        observe(notifications, { alertsEnabled = true } = {}) {
            // Re-read immediately before claiming IDs so another open tab's
            // already displayed notices are not displayed again here.
            read();
            const fresh = new Map();
            for (const event of Array.isArray(notifications) ? notifications : []) {
                const id = eventId(event?.id);
                if (reportEvent(event) && id !== null && id > highest) fresh.set(id, event);
            }
            if (!fresh.size) return null;
            const ids = [...fresh.keys()].sort((a, b) => b - a);
            highest = ids[0]; noticeWatermarks.set(key, highest);
            try {
                if (!storage) throw new Error('Storage unavailable.');
                storage.setItem(key, String(highest)); persistent = true;
            } catch { persistent = false; }
            return alertsEnabled ? { count: ids.length, latest: fresh.get(ids[0]), ids } : null;
        },
    };
}

export function safeCivilianReportUrl(value, origin) {
    if (typeof value !== 'string' || !value.trim() || typeof origin !== 'string') return null;
    try {
        const base = new URL(origin), url = new URL(value, base);
        if (!['http:', 'https:'].includes(base.protocol) || url.origin !== base.origin || url.username || url.password
            || url.search || url.hash || !/^\/reports\/[1-9]\d*$/.test(url.pathname)) return null;
        return url.href;
    } catch { return null; }
}

export function formatCivilianNoticeDate(value) {
    if (typeof value !== 'string' || !value.trim()) return 'Time unavailable';
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? 'Time unavailable' : new Intl.DateTimeFormat('en-PH', {
        timeZone: 'Asia/Manila', year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit',
    }).format(date) + ' (Philippine time)';
}

export function initializeCivilianReportNotices(root, { storage, onOpen = () => {} } = {}) {
    const state = createCivilianReportNoticeState(root.dataset.owner, { storage });
    const notice = root.querySelector('[data-civilian-report-notice]');
    const announcement = root.querySelector('[data-civilian-report-notice-announcement]');
    const title = root.querySelector('[data-civilian-report-notice-title]');
    const message = root.querySelector('[data-civilian-report-notice-message]');
    const count = root.querySelector('[data-civilian-report-notice-count]');
    const date = root.querySelector('[data-civilian-report-notice-date]');
    const open = root.querySelector('[data-civilian-report-notice-open]');
    const dismissButton = root.querySelector('[data-civilian-report-notice-dismiss]');
    const dismiss = () => { if (notice) notice.hidden = true; };
    open?.addEventListener('click', () => { dismiss(); onOpen(open); });
    dismissButton?.addEventListener('click', dismiss);
    return {
        dismiss,
        observe(data) {
            const batch = state.observe(data.notifications, { alertsEnabled: data.report_alerts_enabled !== false });
            if (data.report_alerts_enabled === false) dismiss();
            if (!batch || !notice || !title || !message || !count || !date) return batch;
            title.textContent = typeof batch.latest.title === 'string' ? batch.latest.title : 'Your report has an update';
            message.textContent = typeof batch.latest.message === 'string' ? batch.latest.message : 'Open Updates to view the latest report activity.';
            count.textContent = batch.count === 1 ? 'New report update' : `${batch.count} new report updates`;
            date.textContent = formatCivilianNoticeDate(batch.latest.occurred_at);
            if (announcement) announcement.textContent = `${count.textContent}. ${title.textContent}. ${message.textContent}`;
            // A polite, persistent notice never moves focus, opens a dialog,
            // reloads the page or interferes with an unfinished report.
            notice.hidden = false;
            return batch;
        },
    };
}
