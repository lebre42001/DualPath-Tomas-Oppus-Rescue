import { ReportAlarm } from './report-alarm.js';

// Arrival notifications are separate from incident severity and rescue status.
export class IncomingReportState {
    constructor(saved = {}) {
        this.cursor = Number.isSafeInteger(saved.cursor) && saved.cursor >= 0 ? saved.cursor : null;
        this.seen = new Set((Array.isArray(saved.seen) ? saved.seen : []).filter(Number.isSafeInteger));
        this.unread = new Map((Array.isArray(saved.unread) ? saved.unread : []).filter(item => item && Number.isSafeInteger(item.id)).map(item => [item.id, item]));
    }

    accept(payload, initial = false) {
        const report = payload.report ?? {};
        const id = Number(report.id);
        if (payload.action !== 'created' || !Number.isSafeInteger(id) || id <= 0) return false;
        if (this.seen.has(id) || (!initial && this.cursor !== null && id <= this.cursor)) return false;
        this.seen.add(id);
        this.unread.set(id, {
            id, report_code: report.report_code, incident_type: report.incident_type,
            transmission_type: report.transmission_type, lora_sender_id: report.lora_sender_id,
            detail_url: report.detail_url, location_text: report.location_text, created_at: report.created_at,
        });
        this.seen = new Set([...this.seen].slice(-200));
        while (this.unread.size > 100) this.unread.delete(this.unread.keys().next().value);
        return true;
    }

    snapshot() { return { cursor: this.cursor, seen: [...this.seen], unread: [...this.unread.values()] }; }
}

let controller = null;
export function receiveIncomingReport(payload) { controller?.receive(payload, false); }

export function initializeIncomingReports(onReport) {
    const panel = document.querySelector('[data-incoming-reports]');
    if (!panel || controller) return;
    const storageKey = `aksyr-incoming-${panel.dataset.userId}`;
    const alarmKey = `aksyr-report-alarm-${panel.dataset.userId}`;
    let saved = {};
    try { saved = JSON.parse(localStorage.getItem(storageKey) ?? '{}'); } catch (_) { /* Storage is optional. */ }
    const state = new IncomingReportState(saved && typeof saved === 'object' ? saved : {});
    const list = panel.querySelector('[data-alert-list]');
    const banner = document.querySelector('[data-incoming-banner]');
    const status = panel.querySelector('[data-incoming-status]');
    const soundButtons = document.querySelectorAll('[data-incoming-sound]');
    const stopButtons = document.querySelectorAll('[data-incoming-sound-stop]');
    let alarm = null, busy = false, stopped = false, unauthorized = false, timer = null, baselineNeeded = true;
    const syncSound = () => {
        if (alarm) {
            // Only the user's preference survives navigation. A stale pending
            // boolean must never become an alarm on a later refresh/click.
            try { localStorage.setItem(alarmKey, JSON.stringify({ enabled: alarm.enabled })); } catch (_) { /* Optional. */ }
        }
        soundButtons.forEach(button => {
            button.textContent = alarm?.enabled && alarm?.pending ? 'Play report alarm' : alarm?.enabled ? 'Test sound' : 'Enable sound';
            if (button.hasAttribute('data-incoming-sound-unlock')) button.hidden = !alarm?.pending;
        });
        document.querySelectorAll('[data-incoming-sound-mute]').forEach(button => { button.hidden = !alarm?.enabled; });
        stopButtons.forEach(button => { button.hidden = !alarm?.playing && !alarm?.starting && !alarm?.pending; });
        document.querySelectorAll('.incoming-alarm-toolbar').forEach(toolbar => {
            toolbar.dataset.alarmState = alarm?.enabled && (alarm?.blocked || alarm?.pending) ? 'attention' : 'ready';
        });
        document.querySelectorAll('[data-incoming-sound-status]').forEach(copy => {
            copy.textContent = !alarm ? 'Audio unavailable. Visual report alerts remain active.'
                : !alarm.enabled ? 'Sound muted on this browser. Tap Enable sound to turn it back on.'
                : alarm.pending && !alarm.starting && !alarm.playing ? 'A new report is waiting for sound. Tap Play report alarm; ordinary clicks stay silent.'
                : alarm.blocked ? 'Sound is on and remembered. The browser blocked playback; tap Test sound to activate it.'
                : alarm.starting ? 'Loading alarm sound...'
                : alarm?.playing ? (alarm.isSample ? 'Playing the requested short sound test.' : 'New-report alarm playing for 10 seconds. Stop alarm to end it sooner.')
                : alarm.ready ? 'Sound ready for new online and LoRa reports. Keep this website open.'
                : 'Sound is on and remembered across tabs in this navigation. Preparing audio silently; only new reports trigger an alarm.';
        });
    };
    try {
        const audio = new window.Audio(panel.dataset.alarmUrl);
        audio.preload = 'none';
        alarm = new ReportAlarm(audio, syncSound, window);
        let preference = {};
        try { preference = JSON.parse(localStorage.getItem(alarmKey) ?? '{}') ?? {}; } catch (_) { /* Optional. */ }
        alarm.enabled = preference.enabled !== false;
    } catch (_) { /* Visual notifications remain available without audio support. */ }
    const save = () => { try { localStorage.setItem(storageKey, JSON.stringify(state.snapshot())); } catch (_) { /* Optional. */ } };
    const render = () => {
        const reports = [...state.unread.values()].sort((a, b) => b.id - a.id);
        document.querySelectorAll('[data-alert-count]').forEach(counter => {
            counter.textContent = String(reports.length);
        });
        panel.dataset.unreadCount = String(reports.length);
        const summary = panel.querySelector('[data-incoming-unread-summary]');
        if (summary) summary.textContent = `${reports.length} unseen incoming report${reports.length === 1 ? '' : 's'}.`;
        if (typeof CustomEvent !== 'undefined') document.dispatchEvent(new CustomEvent('stitch:incoming-count', { detail: { count: reports.length } }));
        if (banner) banner.hidden = reports.length === 0;
        if (banner && reports.length) {
            banner.querySelector('[data-incoming-banner-title]').textContent = `${reports.length} unseen report${reports.length === 1 ? '' : 's'}`;
            const first = reports[0];
            banner.querySelector('[data-incoming-banner-copy]').textContent = `${first.report_code ?? 'Report'} · ${first.transmission_type === 'lora' ? first.lora_sender_id ?? 'LoRa' : 'Online'} · ${first.incident_type ?? 'Emergency'}`;
        }
        list.replaceChildren();
        if (!reports.length) {
            const empty = document.createElement('p'); empty.className = 'data-empty';
            empty.textContent = 'No unseen incoming reports.'; list.append(empty);
        }
        reports.forEach(report => {
            const link = document.createElement('a'); link.className = 'alert-item';
            const fallback = report.transmission_type === 'lora' ? '/lora-reports' : '/reports';
            let url;
            try { url = new URL(report.detail_url ?? fallback, window.location.origin); } catch (_) { url = new URL(fallback, window.location.origin); }
            link.href = url.origin === window.location.origin ? url.href : fallback;
            const source = document.createElement('span'); source.className = 'tag blue';
            source.textContent = report.transmission_type === 'lora' ? `LoRa · ${report.lora_sender_id ?? 'Node'}` : 'Online report';
            const title = document.createElement('strong'); title.textContent = report.report_code ?? 'New report';
            const detail = document.createElement('p'); detail.textContent = `${report.incident_type ?? 'Emergency'} · ${report.location_text ?? 'Location unavailable'}`;
            link.append(source, title, detail);
            if (report.created_at && Number.isFinite(new Date(report.created_at).getTime())) {
                const time = document.createElement('time'); time.dateTime = report.created_at;
                time.textContent = new Date(report.created_at).toLocaleString('en-PH', { timeZone: 'Asia/Manila' }) + ' (Philippines)'; link.append(time);
            }
            link.addEventListener('click', () => {
                if (typeof CustomEvent !== 'undefined') document.dispatchEvent(new CustomEvent('stitch:admin-activity', { detail: { event: 'report_notification_opened', report_id: report.id } }));
                alarm?.stop(); state.unread.delete(report.id); save(); render(); });
            list.append(link);
        });
    };
    controller = {
        receive(payload, initial) {
            if (stopped || unauthorized) return;
            const fresh = state.accept(payload, initial);
            if (fresh) { save(); render(); if (!initial) {
                void alarm?.play();
                if (typeof CustomEvent !== 'undefined') document.dispatchEvent(new CustomEvent('stitch:admin-activity', { detail: { event: 'report_notification_received', report_id: Number(payload.report.id) } }));
            } }
            if (!initial && (fresh || payload.action !== 'created')) onReport(payload, fresh);
        },
    };
    panel.querySelector('[data-incoming-clear]').addEventListener('click', () => {
        if (typeof CustomEvent !== 'undefined') document.dispatchEvent(new CustomEvent('stitch:admin-activity', { detail: { event: 'notifications_cleared' } }));
        alarm?.stop(); state.unread.clear(); save(); render(); });
    soundButtons.forEach(button => button.addEventListener('click', () => {
        if (!alarm) {
            button.textContent = 'Sound unavailable';
            return;
        }
        if (alarm.enabled && !alarm.pending) void alarm.test();
        else void alarm.enable();
    }));
    document.querySelectorAll('[data-incoming-sound-mute]').forEach(button => button.addEventListener('click', () => alarm?.disable()));
    stopButtons.forEach(button => button.addEventListener('click', () => alarm?.stop()));
    // Browser activation belongs to the document, not a localStorage flag.
    // Prime audio silently during a genuine gesture. Pending arrivals require
    // the explicit Play report alarm button; an unrelated click never replays one.
    const activate = event => {
        if (!event.isTrusted || event.target.closest?.('[data-incoming-sound], [data-incoming-sound-mute], [data-incoming-sound-stop], [data-incoming-clear], .alert-item')) return;
        if (alarm?.enabled && !alarm.ready && !alarm.starting && !alarm.playing) void alarm.unlock();
    };
    document.addEventListener('click', activate);
    document.addEventListener('keydown', activate);
    window.addEventListener('storage', event => {
        if (event.key !== alarmKey || !alarm) return;
        try {
            const preference = JSON.parse(event.newValue ?? '{}');
            if (preference.enabled === false) alarm.disable();
            else if (preference.enabled === true && !alarm.enabled) { alarm.enabled = true; syncSound(); }
        } catch (_) { /* Invalid optional preferences cannot stop report monitoring. */ }
    });
    const poll = async () => {
        if (busy || stopped) return;
        busy = true;
        const abort = new AbortController();
        const timeout = window.setTimeout(() => abort.abort(), 15000);
        try {
            const url = new URL(panel.dataset.feedUrl, window.location.origin);
            // Each new page establishes a silent history baseline. A stored
            // cursor from yesterday is not a fresh live arrival on refresh.
            if (!baselineNeeded && state.cursor !== null) url.searchParams.set('after', String(state.cursor));
            const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' }, signal: abort.signal });
            if ([401, 403].includes(response.status)) { alarm?.stop(); stopped = true; unauthorized = true; throw new Error('Sign in again to receive reports.'); }
            if (!response.ok) throw new Error('Updates delayed; retrying.');
            const data = await response.json();
            for (const item of data.items) controller.receive(item, baselineNeeded || data.initial === true);
            if (Number.isSafeInteger(data.cursor)) state.cursor = baselineNeeded ? data.cursor : Math.max(state.cursor ?? 0, data.cursor);
            baselineNeeded = false;
            save(); status.textContent = 'Checking for new reports every 5 seconds';
        } catch (error) { status.textContent = stopped ? error.message : 'Updates delayed; retrying.'; }
        finally { window.clearTimeout(timeout); busy = false; }
    };
    document.addEventListener('visibilitychange', () => { if (!document.hidden) void poll(); });
    window.addEventListener('pagehide', () => { alarm?.suspend(); stopped = true; window.clearInterval(timer); });
    window.addEventListener('pageshow', event => {
        if (!event.persisted || unauthorized) return;
        stopped = false;
        window.clearInterval(timer);
        void poll(); timer = window.setInterval(poll, 5000);
    });
    syncSound();
    // Restore the desired setting on every document without inventing a saved
    // browser activation. Failure stays visible; a genuine Test gesture can retry.
    if (alarm?.enabled) void alarm.unlock();
    render(); void poll(); timer = window.setInterval(poll, 5000);
}
