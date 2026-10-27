import { onDomReady } from './dom-ready';
import { captureLiveness, applyLiveness, clearLiveness, hasLiveness } from './face-liveness';

onDomReady(() => {
    const root = document.querySelector('[data-account-verification-root]');
    if (!root) return;

    const form = root.querySelector('[data-account-verification-form]');
    const input = root.querySelector('[data-account-verification-input]');
    const preview = root.querySelector('[data-account-verification-preview]');
    const canvas = root.querySelector('[data-account-verification-canvas]');
    const image = root.querySelector('[data-account-verification-image]');
    const placeholder = root.querySelector('[data-account-verification-placeholder]');
    const status = root.querySelector('[data-account-verification-status]');
    const startButton = root.querySelector('[data-account-verification-start]');
    const captureButton = root.querySelector('[data-account-verification-capture]');
    const fallbackButton = root.querySelector('[data-account-verification-fallback]');
    const submitButton = root.querySelector('[data-account-verification-submit]');
    if (!form || !input || !preview || !canvas || !image) return;

    let stream = null;
    let previewUrl = null;
    let state = 'idle';
    let busy = false;
    let submitting = false;
    let cameraRequest = 0;
    let liveController = null;

    const setStatus = (message) => { status.textContent = message; };
    const hasFile = () => Boolean(input.files?.length);
    const render = () => {
        root.dataset.verificationState = state;
        form.setAttribute('aria-busy', String(busy));
        startButton.hidden = state === 'live';
        startButton.disabled = busy;
        startButton.textContent = busy && !submitting && state !== 'live' ? 'Opening camera…' : (state === 'captured' ? 'Retake' : 'Open Front Camera');
        startButton.classList.toggle('btn-primary', state !== 'captured');
        startButton.classList.toggle('btn-secondary', state === 'captured');
        captureButton.hidden = state !== 'live';
        captureButton.disabled = busy || state !== 'live';
        captureButton.textContent = busy ? 'Checking...' : 'Check Face & Blink';
        submitButton.hidden = state !== 'captured';
        submitButton.disabled = busy || !hasFile() || !hasLiveness(form);
        submitButton.textContent = submitting ? 'Verifying…' : 'Verify Account';
        fallbackButton.disabled = busy;
        preview.hidden = state !== 'live';
        image.hidden = state !== 'captured';
        placeholder.hidden = state !== 'idle';
    };
    const stopCamera = () => {
        stream?.getTracks().forEach((track) => track.stop());
        stream = null;
        preview.srcObject = null;
    };
    const setSelectedFile = (file) => {
        if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 10 * 1024 * 1024 || !file.size) {
            setStatus('Use a JPG, PNG, or WebP selfie smaller than 10 MB. Please take another photo.');
            return false;
        }
        try {
            // Attach only the final frame approved by the server.
            if (input.files?.[0] !== file) {
                const transfer = new DataTransfer();
                transfer.items.add(file);
                input.files = transfer.files;
            }
            if (previewUrl) URL.revokeObjectURL(previewUrl);
            previewUrl = URL.createObjectURL(file);
            image.src = previewUrl;
            stopCamera();
            state = 'captured';
            setStatus('3 of 3 · Check your selfie, then tap Verify Account.');
            return true;
        } catch {
            setStatus('Could not prepare this selfie. Try an updated Chrome or Safari browser.');
            return false;
        }
    };
    const waitForVideo = () => new Promise((resolve, reject) => {
        if (preview.readyState >= 2 && preview.videoWidth > 0) { resolve(); return; }
        const timer = window.setTimeout(() => { cleanup(); reject(new Error('Camera preview timed out')); }, 10000);
        const ready = () => { if (preview.videoWidth > 0) { cleanup(); resolve(); } };
        const cleanup = () => { clearTimeout(timer); preview.removeEventListener('loadeddata', ready); };
        preview.addEventListener('loadeddata', ready);
    });
    const openCamera = async () => {
        if (busy) return;
        if (!navigator.mediaDevices?.getUserMedia) {
            setStatus('Live camera access is required. Open in Chrome or Safari and allow the camera.');
            return;
        }
        liveController?.abort();
        clearLiveness(form);
        input.value = '';
        state = 'idle';
        const request = ++cameraRequest;
        busy = true;
        render();
        setStatus('Allow camera access when your phone asks.');
        try {
            stopCamera();
            const openedStream = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: 'user', width: { ideal: 720 }, height: { ideal: 960 } }, audio: false,
            });
            if (request !== cameraRequest) { openedStream.getTracks().forEach((track) => track.stop()); return; }
            stream = openedStream;
            preview.srcObject = stream;
            await preview.play();
            await waitForVideo();
            if (request !== cameraRequest) return;
            state = 'live';
            stream.getVideoTracks()[0]?.addEventListener('ended', () => {
                stopCamera();
                state = hasFile() ? 'captured' : 'idle';
                busy = false;
                setStatus('Camera stopped. Open it again to complete the blink check.');
                render();
            }, { once: true });
            setStatus('2 of 3 · Center your face in good lighting, then tap Check Face & Blink.');
        } catch {
            if (request !== cameraRequest) return;
            stopCamera();
            state = hasFile() ? 'captured' : 'idle';
            setStatus('Allow camera access in your browser settings, then retry in Chrome or Safari.');
        } finally {
            if (request === cameraRequest) { busy = false; render(); }
        }
    };
    const captureFace = async () => {
        if (busy || state !== 'live') return;
        busy = true;
        clearLiveness(form);
        liveController = new AbortController();
        const controller = liveController;
        render();
        try {
            const result = await captureLiveness({ video: preview, form, purpose: 'account', onStatus: setStatus, signal: controller.signal });
            if (controller.signal.aborted) return;
            if (setSelectedFile(result.file)) {
                applyLiveness(form, result);
                setStatus('3 of 3 · Face and blink check passed. Tap Verify Account.');
            }
        } catch (error) {
            if (!controller.signal.aborted) setStatus(error.message || 'Live check failed. Please retry.');
        } finally { busy = false; render(); }
    };

    startButton.addEventListener('click', () => { void openCamera(); });
    captureButton.addEventListener('click', () => { void captureFace(); });
    fallbackButton.addEventListener('click', () => {
        setStatus('Blink verification needs live camera access. Allow camera permission and open this page in an updated Chrome or Safari browser.');
    });
    input.addEventListener('change', () => {
        clearLiveness(form);
        input.value = '';
        state = stream?.active ? 'live' : 'idle';
        setStatus('Complete the live camera blink check to continue.');
        render();
    });
    form.addEventListener('liveness:expired', () => {
        setStatus('Live verification expired. Tap Retake for a new blink check.');
        render();
    });
    form.addEventListener('submit', (event) => {
        if (busy || state !== 'captured' || !hasFile() || !hasLiveness(form)) {
            event.preventDefault();
            if (!busy) setStatus('Complete a new live face and blink check before verifying your account.');
            return;
        }
        busy = true;
        submitting = true;
        render();
        setStatus('Saving your verification. Please wait…');
    });
    window.addEventListener('pagehide', () => { ++cameraRequest; liveController?.abort(); clearLiveness(form); stopCamera(); });
    window.addEventListener('pageshow', () => {
        busy = false;
        submitting = false;
        state = hasFile() && hasLiveness(form) ? 'captured' : 'idle';
        setStatus(hasFile() && hasLiveness(form) ? '3 of 3 · Check your selfie, then tap Verify Account.' : '1 of 3 · Open the front camera to begin.');
        render();
    });
    render();
});
