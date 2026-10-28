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
            detail_url: report.detail_url, location_text: report.location_text,
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
    let saved = {};
    try { saved = JSON.parse(localStorage.getItem(storageKey) ?? '{}'); } catch (_) { /* Storage is optional. */ }
    const state = new IncomingReportState(saved && typeof saved === 'object' ? saved : {});
    const list = panel.querySelector('[data-alert-list]');
    const banner = document.querySelector('[data-incoming-banner]');
    const status = panel.querySelector('[data-incoming-status]');
    const soundButton = panel.querySelector('[data-incoming-sound]');
    let audio = null, sound = false, busy = false, stopped = false, timer = null;
    const save = () => { try { localStorage.setItem(storageKey, JSON.stringify(state.snapshot())); } catch (_) { /* Optional. */ } };
    const play = () => {
        if (!sound || audio?.state !== 'running') return;
        [0, 0.23].forEach(offset => {
            const oscillator = audio.createOscillator(), gain = audio.createGain();
            const at = audio.currentTime + offset;
            oscillator.frequency.value = offset ? 880 : 660;
            gain.gain.setValueAtTime(0.0001, at);
            gain.gain.exponentialRampToValueAtTime(0.15, at + 0.015);
            gain.gain.exponentialRampToValueAtTime(0.0001, at + 0.18);
            oscillator.connect(gain); gain.connect(audio.destination);
            oscillator.start(at); oscillator.stop(at + 0.2);
        });
    };
    const render = () => {
        const reports = [...state.unread.values()].sort((a, b) => b.id - a.id);
        document.querySelectorAll('[data-alert-count]').forEach(counter => {
            counter.textContent = String(reports.length);
            counter.closest('button')?.classList.toggle('has-unread-reports', reports.length > 0);
        });
        banner.hidden = reports.length === 0;
        if (reports.length) {
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
            link.addEventListener('click', () => { state.unread.delete(report.id); save(); render(); });
            list.append(link);
        });
    };
    controller = {
        receive(payload, initial) {
            const fresh = state.accept(payload, initial);
            if (fresh) { save(); render(); if (!initial) play(); }
            if (!initial && (fresh || payload.action !== 'created')) onReport(payload, fresh);
        },
    };
    panel.querySelector('[data-incoming-clear]').addEventListener('click', () => { state.unread.clear(); save(); render(); });
    soundButton.addEventListener('click', async () => {
        try {
            const Audio = window.AudioContext ?? window.webkitAudioContext;
            if (!Audio) { soundButton.textContent = 'Sound unavailable'; return; }
            audio ??= new Audio(); await audio.resume(); sound = !sound;
            soundButton.textContent = sound ? 'Sound on' : 'Enable sound';
            soundButton.setAttribute('aria-pressed', String(sound));
            if (sound) play();
        } catch (_) { sound = false; soundButton.textContent = 'Tap to enable sound'; }
    });
    const poll = async () => {
        if (busy || stopped || document.hidden) return;
        busy = true;
        const abort = new AbortController();
        const timeout = window.setTimeout(() => abort.abort(), 15000);
        try {
            const url = new URL(panel.dataset.feedUrl, window.location.origin);
            if (state.cursor !== null) url.searchParams.set('after', String(state.cursor));
            const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' }, signal: abort.signal });
            if ([401, 403].includes(response.status)) { stopped = true; throw new Error('Sign in again to receive reports.'); }
            if (!response.ok) throw new Error('Updates delayed; retrying.');
            const data = await response.json();
            for (const item of data.items) controller.receive(item, data.initial === true);
            if (Number.isSafeInteger(data.cursor)) state.cursor = Math.max(state.cursor ?? 0, data.cursor);
            save(); status.textContent = 'Checking for new reports every 5 seconds';
        } catch (error) { status.textContent = stopped ? error.message : 'Updates delayed; retrying.'; }
        finally { window.clearTimeout(timeout); busy = false; }
    };
    document.addEventListener('visibilitychange', () => { if (!document.hidden) void poll(); });
    window.addEventListener('pagehide', () => { stopped = true; window.clearInterval(timer); });
    render(); void poll(); timer = window.setInterval(poll, 5000);
}
