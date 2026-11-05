// One bounded sound at a time. Arrival deduplication belongs to IncomingReportState.
export class ReportAlarm {
    constructor(audio, onChange = () => {}, clock = globalThis) {
        this.audio = audio;
        this.onChange = onChange;
        this.clock = clock;
        this.enabled = true;
        this.ready = false;
        this.pending = false;
        this.starting = false;
        this.isSample = false;
        this.playing = false;
        this.blocked = false;
        this.timer = null;
        this.generation = 0;
        this.unlocking = false;
        this.mutedBeforeUnlock = null;
        audio.loop = false;
        audio.volume = 0.75;
        audio.addEventListener('ended', () => {
            if (this.isSample) this.stop();
            else if (this.playing) {
                // Preserve the original alarm deadline if a browser emits
                // ended despite looping. A short source file is not the timer.
                const generation = this.generation;
                this.audio.currentTime = 0;
                void this.audio.play().catch(() => { if (generation === this.generation) this.fail(); });
            }
        });
        audio.addEventListener('error', () => this.fail());
    }

    enable() {
        this.enabled = true;
        this.blocked = false;
        this.onChange();
        // An explicit button may play a real pending arrival; general page
        // clicks only call unlock(), which never emits an audible sample.
        return this.pending ? this.play() : this.ready ? Promise.resolve(true) : this.unlock();
    }

    play() { return this.start(10000, false); }
    test() { return this.pending ? this.play() : this.start(1200, true); }

    async unlock() {
        if (!this.enabled || this.ready || this.unlocking || this.playing || this.starting) return false;
        const generation = ++this.generation;
        this.unlocking = true;
        this.mutedBeforeUnlock = this.audio.muted;
        this.audio.muted = true;
        this.audio.loop = false;
        this.timer = this.clock.setTimeout(() => this.fail(), 15000);
        this.onChange();
        try {
            if (this.audio.error) this.audio.load();
            this.audio.currentTime = 0;
            await this.audio.play();
            if (generation !== this.generation) return false;
            this.clock.clearTimeout(this.timer); this.timer = null;
            this.audio.pause(); this.audio.currentTime = 0;
            this.audio.muted = this.mutedBeforeUnlock;
            this.mutedBeforeUnlock = null; this.unlocking = false;
            this.ready = true; this.blocked = false;
            this.onChange();
            return true;
        } catch (_) {
            if (generation === this.generation) this.fail();
            return false;
        }
    }

    async start(duration, sample) {
        if (!this.enabled) return false;
        if (this.unlocking) this.stop({ preservePending: true });
        if (this.playing || this.starting) {
            if (sample || !this.isSample) return false;
            // An arrival must not be reduced to the short activation sample.
            this.stop();
        }
        const generation = ++this.generation;
        this.pending = !sample;
        this.starting = true;
        this.isSample = sample;
        this.audio.muted = false;
        this.audio.loop = !sample;
        // Bound a stalled network load too; the audible timer starts after play succeeds.
        this.timer = this.clock.setTimeout(() => this.fail(), 15000);
        this.onChange();
        try {
            // Retry the same supplied file after a temporary media/network failure.
            if (this.audio.error) this.audio.load();
            this.audio.currentTime = 0;
            await this.audio.play();
            if (generation !== this.generation) return false;
            this.clock.clearTimeout(this.timer);
            this.starting = false;
            this.playing = true;
            this.pending = false;
            this.ready = true;
            this.blocked = false;
            this.timer = this.clock.setTimeout(() => this.stop(), duration);
            this.onChange();
            return true;
        } catch (_) {
            if (generation === this.generation) this.fail();
            return false;
        }
    }

    stop({ preservePending = false } = {}) {
        ++this.generation;
        this.clock.clearTimeout(this.timer);
        this.timer = null;
        this.audio.pause();
        if (this.mutedBeforeUnlock !== null) this.audio.muted = this.mutedBeforeUnlock;
        this.mutedBeforeUnlock = null;
        this.unlocking = false;
        this.playing = false;
        this.starting = false;
        this.isSample = false;
        if (!preservePending) this.pending = false;
        this.onChange();
    }

    disable() {
        this.enabled = false;
        this.stop();
    }

    suspend() {
        this.ready = false;
        // Leaving a page must not replay an old unplayed alarm on refresh.
        this.stop();
    }

    fail() {
        if ((this.starting || this.playing) && !this.isSample) this.pending = true;
        this.blocked = true;
        this.ready = false;
        // Autoplay rejection is not the administrator choosing Mute.
        this.stop({ preservePending: true });
    }
}
