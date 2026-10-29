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
        audio.loop = false;
        audio.volume = 0.75;
        audio.addEventListener('ended', () => this.stop());
        audio.addEventListener('error', () => this.fail());
    }

    enable() {
        this.enabled = true;
        this.blocked = false;
        // A blocked arrival takes priority over the short activation sample.
        return this.start(this.pending ? 10000 : 1200, !this.pending);
    }

    play() { return this.start(10000, false); }

    async start(duration, sample) {
        if (!this.enabled) return false;
        if (this.playing || this.starting) {
            if (sample || !this.isSample) return false;
            // An arrival must not be reduced to the short activation sample.
            this.stop();
        }
        const generation = ++this.generation;
        this.pending = !sample;
        this.starting = true;
        this.isSample = sample;
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
        this.stop({ preservePending: true });
    }

    fail() {
        this.blocked = true;
        this.ready = false;
        // Autoplay rejection is not the administrator choosing Mute.
        this.stop({ preservePending: true });
    }
}
