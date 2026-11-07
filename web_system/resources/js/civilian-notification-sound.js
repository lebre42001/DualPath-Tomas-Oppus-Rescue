const accounts = new Map();
const validId = value => (typeof value === 'number' || typeof value === 'string')
    && /^\d+$/.test(String(value)) && Number.isSafeInteger(Number(value)) && Number(value) > 0;

// Sound has its own watermark: old unread notices can remain visible without
// chiming every time a page opens. Never store report content or audio readiness.
export function createCivilianSoundState(owner, { storage } = {}) {
    if (storage === undefined) { try { storage = globalThis.localStorage; } catch { storage = null; } }
    const key = `aksyres-notification-sound:v1:${owner}`;
    const preferenceKey = `${key}:enabled`;
    const account = accounts.get(key) ?? { highest: 0, enabled: true };
    accounts.set(key, account);
    let baselined = false;
    const read = name => { try { return storage?.getItem(name); } catch { return null; } };
    const write = (name, value) => { try { storage?.setItem(name, value); } catch { /* Same-page fallback stays usable. */ } };
    const enabled = () => {
        const saved = read(preferenceKey);
        if (saved === '0' || saved === '1') account.enabled = saved === '1';
        return account.enabled;
    };
    return {
        key, preferenceKey, enabled,
        setEnabled(value) { account.enabled = value === true; write(preferenceKey, account.enabled ? '1' : '0'); },
        observe(notifications, { reportAlertsEnabled = true } = {}) {
            if (!Array.isArray(notifications)) return [];
            const saved = read(key);
            if (validId(saved)) account.highest = Math.max(account.highest, Number(saved));
            const previous = account.highest;
            const unique = new Map();
            for (const event of notifications) {
                if (!event || !validId(event.id)) continue;
                const id = Number(event.id);
                account.highest = Math.max(account.highest, id);
                if (id > previous) unique.set(id, event);
            }
            write(key, String(account.highest));
            if (!baselined) { baselined = true; return []; }
            if (!enabled()) return [];
            return [...unique.values()].filter(event => event.kind === 'app_update_available'
                || (reportAlertsEnabled && typeof event.kind === 'string' && event.kind.startsWith('report_')
                    && !['report_submitted', 'report_submission'].includes(event.kind)));
        },
    };
}

// The supplied chime plays once, never loops or queues stale notifications.
export class CivilianNotificationSound {
    constructor(audio, { clock = globalThis, onChange = () => {} } = {}) {
        Object.assign(this, { audio, clock, onChange, enabled: true, ready: false, playing: false, blocked: false,
            starting: false, unlocking: false, generation: 0, timer: null });
        audio.loop = false;
        audio.volume = 0.75;
        audio.addEventListener('ended', () => { if (this.playing || this.starting) this.stop(); });
        audio.addEventListener('error', () => { if (this.playing || this.starting || this.unlocking) this.fail(); });
    }

    snapshot() { return { enabled: this.enabled, ready: this.ready, playing: this.playing, blocked: this.blocked }; }
    async enable() {
        this.enabled = true;
        if (this.ready || this.playing) { this.onChange(); return true; }
        if (this.unlocking || this.starting) return false;
        const generation = ++this.generation;
        this.unlocking = true; this.audio.muted = true; this.blocked = false;
        this.timer = this.clock.setTimeout(() => this.fail(), 15000);
        this.onChange();
        try {
            if (this.audio.error) this.audio.load();
            this.audio.currentTime = 0;
            await this.audio.play();
            if (generation !== this.generation) return false;
            this.stop(); this.ready = true; this.blocked = false; this.onChange();
            return true;
        } catch {
            if (generation === this.generation) this.fail();
            return false;
        }
    }
    play() { return this.start(); }
    test() { return this.start(); }
    async start() {
        if (!this.enabled || this.playing || this.starting) return false;
        if (this.unlocking) this.stop();
        const generation = ++this.generation;
        this.starting = true; this.audio.muted = false; this.audio.loop = false;
        this.timer = this.clock.setTimeout(() => this.fail(), 15000);
        this.onChange();
        try {
            if (this.audio.error) this.audio.load();
            this.audio.currentTime = 0;
            await this.audio.play();
            if (generation !== this.generation) return false;
            this.clock.clearTimeout(this.timer);
            this.starting = false; this.playing = true; this.ready = true; this.blocked = false;
            this.timer = this.clock.setTimeout(() => this.stop(), 8000);
            this.onChange();
            return true;
        } catch {
            if (generation === this.generation) this.fail();
            return false;
        }
    }
    stop() {
        ++this.generation;
        this.clock.clearTimeout(this.timer); this.timer = null;
        this.audio.pause(); this.audio.muted = false;
        try { this.audio.currentTime = 0; } catch { /* A source may not have loaded yet. */ }
        this.playing = false; this.starting = false; this.unlocking = false;
        this.onChange();
    }
    disable() { this.enabled = false; this.ready = false; this.stop(); }
    suspend() { this.ready = false; this.stop(); }
    fail() { this.blocked = true; this.ready = false; this.stop(); }
}

export function initializeCivilianNotificationSound(root, { document: doc = document, window: win = window,
    navigator: nav = navigator, storage } = {}) {
    const audio = root.querySelector('[data-civilian-notification-audio]');
    if (!audio) return { observe() {}, suspend() {} };
    const state = createCivilianSoundState(root.dataset.owner, { storage });
    const status = root.querySelector('[data-civilian-sound-status]');
    const enable = root.querySelector('[data-civilian-sound-enable]');
    const test = root.querySelector('[data-civilian-sound-test]');
    const mute = root.querySelector('[data-civilian-sound-mute]');
    let suspended = false;
    const render = () => {
        const sound = player.snapshot();
        if (status) status.textContent = !sound.enabled ? 'Notification sound is muted on this browser.'
            : sound.blocked ? 'Sound could not play. Tap Enable sound, check your device volume, then try Test sound. Your updates are still shown.'
                : sound.playing ? 'Playing notification sound.'
                    : sound.ready ? 'Sound is enabled for new report and app updates while this page is visible and connected.'
                        : 'Sound is on. Tap Enable sound if your browser needs permission to play it.';
        if (enable) { enable.hidden = sound.enabled && sound.ready && !sound.blocked; enable.textContent = sound.enabled ? 'Enable sound' : 'Unmute sound'; }
        if (test) test.disabled = !sound.enabled;
        if (mute) mute.hidden = !sound.enabled;
    };
    const player = new CivilianNotificationSound(audio, { clock: win, onChange: render });
    const syncPreference = () => {
        if (!state.enabled()) player.disable();
        else if (!player.enabled) { player.enabled = true; render(); }
    };
    syncPreference(); render();
    enable?.addEventListener('click', () => { state.setEnabled(true); void player.enable(); });
    test?.addEventListener('click', () => { if (!suspended && !doc.hidden) void player.test(); });
    mute?.addEventListener('click', () => { state.setEnabled(false); player.disable(); });
    const prepare = event => {
        if (!event.isTrusted || doc.hidden || suspended || !state.enabled() || player.ready || player.blocked) return;
        if (event.type === 'keydown' && !['Enter', ' '].includes(event.key)) return;
        // A normal gesture only primes silently. It never replays an old alert.
        void player.enable();
    };
    doc.addEventListener('pointerdown', prepare);
    doc.addEventListener('keydown', prepare);
    doc.addEventListener('visibilitychange', () => { if (doc.hidden) player.suspend(); });
    win.addEventListener('pagehide', () => { suspended = true; player.suspend(); });
    win.addEventListener('pageshow', () => { suspended = false; });
    win.addEventListener('storage', event => { if (event.key === state.preferenceKey || event.key === null) syncPreference(); });
    return {
        async observe(data) {
            const claim = () => {
                syncPreference();
                const fresh = state.observe(data.notifications, { reportAlertsEnabled: data.report_alerts_enabled !== false });
                if (fresh.length && !doc.hidden && !suspended && nav.onLine !== false) return player.play();
                return false;
            };
            // Web Locks coordinates simultaneous polls from this account's tabs.
            // Storage rereads provide best-effort deduplication on older browsers.
            try {
                if (nav.locks?.request) await nav.locks.request(state.key, claim);
                else await claim();
            } catch { /* Audio failure must never hide report updates. */ }
        },
        suspend() { suspended = true; player.suspend(); },
    };
}
