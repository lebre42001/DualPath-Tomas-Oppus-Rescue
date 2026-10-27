import { guideBounds, judgeFace } from './face-guide-quality';

export function showVerifiedFace(frame) {
    if (!frame) return;
    frame.dataset.faceState = 'verified';
    frame.querySelector('.live-face-outline')?.setAttribute('hidden', '');
    const label = frame.querySelector('[data-face-guide-label]');
    if (label) { label.hidden = false; label.textContent = 'Blink check passed'; }
}

export function watchFaceGuide({ video, frame, onChange, onAutoCapture }) {
    let stopped = false, failed = false, detector = null, timer = null, stableSince = null;
    let anchor = null, lastVideoTime = -1, lastSample = 0, ready = false, attempted = false, lastState = '';
    const canvas = document.createElement('canvas'), context = canvas.getContext('2d');
    const closeDetector = () => {
        const current = detector; detector = null;
        try { current?.close(); } catch { /* Camera cleanup must still complete. */ }
    };
    const label = frame.querySelector('[data-face-guide-label]'), outline = frame.querySelector('.live-face-outline');
    const emit = (kind, message, isReady = false) => {
        if (stopped) return;
        ready = isReady;
        frame.dataset.faceState = kind;
        if (outline) outline.hidden = false;
        if (label) {
            label.hidden = false;
            label.textContent = kind === 'ready' ? 'Face ready' : kind === 'aligning' ? 'Hold still' : kind === 'loading' ? 'Preparing guide' : kind === 'unavailable' ? 'Guide unavailable' : 'Center your face';
        }
        const state = `${kind}:${message}`;
        if (state !== lastState) { lastState = state; onChange({ ready, kind, message }); }
    };
    const clearStability = () => { stableSince = null; anchor = null; ready = false; };
    const hidden = () => {
        if (document.hidden) { clearStability(); emit('missing', 'Keep the camera page open to continue.'); }
    };
    document.addEventListener('visibilitychange', hidden);
    emit('loading', 'Preparing the face guide. Keep your face inside the outline.');
    const unavailable = () => {
        failed = true; clearStability();
        emit('unavailable', 'Face detection could not load. Check your connection, then retry the camera in Chrome or Safari.');
    };
    const loadTimeout = setTimeout(unavailable, 45000);

    const tick = () => {
        if (stopped || failed) return;
        try {
            const now = performance.now();
            if (document.hidden || video.readyState < 2 || !video.srcObject?.active || video.currentTime === lastVideoTime) {
                if (document.hidden || now-lastSample > 1000) { clearStability(); emit('missing', 'Keep the live camera open and center your face.'); }
            } else {
                lastVideoTime = video.currentTime; lastSample = now;
                const scale = Math.min(1, 320 / Math.max(video.videoWidth, video.videoHeight));
                const w = Math.round(video.videoWidth*scale), h = Math.round(video.videoHeight*scale);
                if (canvas.width !== w || canvas.height !== h) { canvas.width = w; canvas.height = h; }
                const bounds = guideBounds(w, h, frame.clientWidth, frame.clientHeight);
                frame.style.setProperty('--face-guide-width', `${bounds.width}px`);
                frame.style.setProperty('--face-guide-height', `${bounds.height}px`);
                context.drawImage(video, 0, 0, w, h);
                const result = judgeFace(detector.detectForVideo(canvas, now).detections, w, h, bounds);
                if (!result.ready) { clearStability(); emit(result.kind, result.message); }
                else {
                    const box = result.box;
                    if (!anchor || Math.abs(box.cx-anchor.cx) > .035 || Math.abs(box.cy-anchor.cy) > .035 || Math.abs(box.w-anchor.w) > .05) {
                        anchor = box; stableSince = now;
                    }
                    const stable = now-stableSince;
                    if (stable < 650) emit('aligning', 'Face detected. Hold still for a moment.');
                    else {
                        emit('ready', attempted ? 'Face ready. Tap Check Face & Blink to retry.' : 'Face ready. Capture starts automatically. Blink when asked.', true);
                        if (stable >= 1800 && !attempted && onAutoCapture() !== false) attempted = true;
                    }
                }
            }
        } catch { unavailable(); }
        if (!stopped && !failed) timer = setTimeout(tick, 250);
    };
    void (async () => {
        try {
            if (!context || typeof WebAssembly === 'undefined') throw new Error('Unsupported face guide');
            const { createFaceDetector } = await import('./face-guide-detector');
            detector = await createFaceDetector();
            if (stopped || failed) { closeDetector(); return; }
            clearTimeout(loadTimeout);
            tick();
        } catch { clearTimeout(loadTimeout); if (!stopped) unavailable(); }
    })();
    return {
        get ready() { return ready && !document.hidden && video.srcObject?.active && performance.now()-lastSample < 1000; },
        markAttempted() { attempted = true; },
        stop() {
            stopped = true; ready = false;
            clearTimeout(timer); clearTimeout(loadTimeout);
            document.removeEventListener('visibilitychange', hidden);
            closeDetector();
            frame.dataset.faceState = 'idle';
            if (label) label.hidden = true;
            if (outline) outline.hidden = true;
        },
    };
}
