// One bounded sound at a time. Arrival deduplication belongs to IncomingReportState.
export class ReportAlarm {
    constructor(audio, onChange = () => {}, clock = globalThis) {
        this.audio = audio;
        this.onChange = onChange;
        this.clock = clock;
        this.enabled = false;
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
        // Called directly from a click: a short sample also unlocks browser audio.
        return this.play(1200);
    }

    async play(duration = 10000) {
        if (!this.enabled || this.playing) return false;
        const generation = ++this.generation;
        this.playing = true;
        this.timer = this.clock.setTimeout(() => this.stop(), duration);
        this.onChange();
        try {
            this.audio.currentTime = 0;
            await this.audio.play();
            return generation === this.generation;
        } catch (_) {
            if (generation === this.generation) this.fail();
            return false;
        }
    }

    stop() {
        ++this.generation;
        this.clock.clearTimeout(this.timer);
        this.timer = null;
        this.audio.pause();
        this.playing = false;
        this.onChange();
    }

    disable() {
        this.enabled = false;
        this.stop();
    }

    fail() {
        this.blocked = true;
        this.disable();
    }
}
