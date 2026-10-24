import { onDomReady } from './dom-ready';

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
    let faceDetector = null;
    try {
        if (window.FaceDetector) faceDetector = new window.FaceDetector({ fastMode: true, maxDetectedFaces: 1 });
    } catch { /* Some browsers expose the API without supporting it. */ }

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
        captureButton.textContent = busy ? 'Capturing…' : 'Capture Face';
        submitButton.hidden = state !== 'captured';
        submitButton.disabled = busy || !hasFile();
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
            // Native camera selection is already attached; only captured blobs need a transfer.
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
            setStatus('Could not prepare this selfie. Use Phone Camera instead.');
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
            setStatus('Camera preview is unavailable here. Tap Use Phone Camera instead.');
            return;
        }
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
                setStatus('Camera stopped. Open it again, or use Phone Camera.');
                render();
            }, { once: true });
            setStatus('2 of 3 · Center your face in good lighting, then tap Capture Face.');
        } catch {
            if (request !== cameraRequest) return;
            stopCamera();
            state = hasFile() ? 'captured' : 'idle';
            setStatus('Camera access was denied or unavailable. Allow it in browser settings, or use Phone Camera.');
        } finally {
            if (request === cameraRequest) { busy = false; render(); }
        }
    };
    const captureFace = async () => {
        if (busy || state !== 'live') return;
        busy = true;
        render();
        try {
            if (!stream?.active || preview.readyState < 2 || !preview.videoWidth) throw new Error('Camera not ready');
            const { width, height } = preview.getBoundingClientRect();
            const ratio = width / height;
            let sw = preview.videoWidth;
            let sh = preview.videoHeight;
            if (sw / sh > ratio) sw = sh * ratio;
            else sh = sw / ratio;
            canvas.width = 720;
            canvas.height = Math.round(720 / ratio);
            const context = canvas.getContext('2d');
            if (!context) throw new Error('Canvas unavailable');
            context.drawImage(preview, (preview.videoWidth - sw) / 2, (preview.videoHeight - sh) / 2, sw, sh, 0, 0, canvas.width, canvas.height);
            if (faceDetector) {
                let faces = null;
                try { faces = await faceDetector.detect(canvas); } catch { /* Native camera fallback remains available. */ }
                if (faces && !faces.length) {
                    setStatus('No face detected. Center your face in good lighting, then capture again.');
                    return;
                }
            }
            const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', .92));
            if (!blob) throw new Error('Image unavailable');
            setSelectedFile(new File([blob], `account-face-${Date.now()}.jpg`, { type: 'image/jpeg' }));
        } catch {
            setStatus('Could not capture your selfie. Try again, or use Phone Camera.');
        } finally { busy = false; render(); }
    };

    startButton.addEventListener('click', () => { void openCamera(); });
    captureButton.addEventListener('click', () => { void captureFace(); });
    fallbackButton.addEventListener('click', () => { if (!busy) input.click(); });
    input.addEventListener('change', () => {
        const file = input.files?.[0];
        if (file) {
            if (!setSelectedFile(file)) { input.value = ''; state = stream?.active ? 'live' : 'idle'; }
        } else if (state === 'captured') { state = 'idle'; }
        render();
    });
    form.addEventListener('submit', (event) => {
        if (busy || state !== 'captured' || !hasFile()) {
            event.preventDefault();
            if (!busy) setStatus('Take a clear selfie before verifying your account.');
            return;
        }
        busy = true;
        submitting = true;
        render();
        setStatus('Saving your verification. Please wait…');
    });
    window.addEventListener('pagehide', () => { ++cameraRequest; stopCamera(); });
    window.addEventListener('pageshow', () => {
        busy = false;
        submitting = false;
        state = hasFile() ? 'captured' : 'idle';
        setStatus(hasFile() ? '3 of 3 · Check your selfie, then tap Verify Account.' : '1 of 3 · Open the front camera to begin.');
        render();
    });
    render();
});
