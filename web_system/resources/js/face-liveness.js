// Capture from the live stream; the server independently verifies face/blink frames.
const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const proofInput = (form) => form.querySelector('[data-liveness-proof]');
export const clearLiveness = (form) => {
    if (proofInput(form)) proofInput(form).value = '';
    delete form.dataset.livenessExpiresAt;
};
export const hasLiveness = (form) => Boolean(proofInput(form)?.value.length === 64
    && Number(form.dataset.livenessExpiresAt || 0) > Date.now());
export const applyLiveness = (form, result) => {
    proofInput(form).value = result.proof;
    form.dataset.livenessExpiresAt = String(Date.now() + result.expiresIn * 1000);
    const proof = result.proof;
    setTimeout(() => {
        if (proofInput(form).value !== proof) return;
        clearLiveness(form);
        form.dispatchEvent(new Event('liveness:expired'));
    }, result.expiresIn * 1000);
};
async function request(url, body, signal) {
    const controller = new AbortController();
    const abort = () => controller.abort();
    if (signal?.aborted) throw new Error('Live check cancelled.');
    signal?.addEventListener('abort', abort, { once: true });
    const timer = setTimeout(abort, 35000);
    try {
        const response = await fetch(url, {
            method: 'POST', credentials: 'same-origin', signal: controller.signal,
            headers: { Accept: 'application/json', 'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '' },
            body: JSON.stringify(body),
        });
        let data;
        try { data = await response.json(); } catch { throw new Error('Live face check is unavailable. Please retry.'); }
        if (!response.ok) throw new Error(Object.values(data.errors || {}).flat()[0] || 'Live face check is unavailable. Please retry.');
        return data;
    } catch (error) {
        if (controller.signal.aborted) throw new Error('Live check interrupted. Keep this page open and try again.');
        if (error instanceof TypeError) throw new Error('Could not reach the face check. Check your connection and retry.');
        throw error;
    } finally {
        clearTimeout(timer);
        signal?.removeEventListener('abort', abort);
    }
}
export async function captureLiveness({ video, form, purpose, onStatus, signal }) {
    const challenge = await request(form.dataset.livenessChallengeUrl, { purpose }, signal);
    if (![1,2].includes(challenge.blink_count) || ![6000,8000].includes(challenge.duration_ms)) throw new Error('Start a new live check.');
    const canvas = document.createElement('canvas');
    const scale = Math.min(1, 480 / Math.max(video.videoWidth, video.videoHeight));
    canvas.width = Math.round(video.videoWidth * scale);
    canvas.height = Math.round(video.videoHeight * scale);
    if (!canvas.width || !canvas.height) throw new Error('Camera preview is not ready. Please retry.');
    const context = canvas.getContext('2d');
    if (!context) throw new Error('This browser cannot capture the live camera. Try Chrome or Safari.');
    let frames = [], lastSecond = -1;
    const started = performance.now();
    try {
        while (performance.now() - started < challenge.duration_ms) {
            if (signal?.aborted || document.hidden || video.readyState < 2 || !video.srcObject?.active) throw new Error('Keep the live camera and this page open, then retry.');
            const ms = Math.round(performance.now() - started);
            const second = Math.ceil((challenge.duration_ms - ms) / 1000);
            if (second !== lastSecond) {
                onStatus(`Show one face. Blink ${challenge.blink_count} time${challenge.blink_count === 1 ? '' : 's'} slowly, then keep your eyes open. ${second}s left.`);
                lastSecond = second;
            }
            context.drawImage(video, 0, 0, canvas.width, canvas.height);
            frames.push({ ms, jpeg: canvas.toDataURL('image/jpeg', .65).split(',')[1] });
            if (frames.length > 90) throw new Error('Live capture interrupted. Please retry.');
            await wait(100);
        }
        onStatus('Checking your face and blink sequence…');
        const result = await request(form.dataset.livenessVerifyUrl, { challenge_id: challenge.challenge_id, frames }, signal);
        if (typeof result.proof !== 'string' || result.proof.length !== 64 || result.expires_in !== 120) throw new Error('The live check could not be verified. Please retry.');
        const bytes = Uint8Array.from(atob(frames[frames.length-1].jpeg), (c) => c.charCodeAt(0));
        return { file: new File([bytes], `live-selfie-${Date.now()}.jpg`, { type: 'image/jpeg' }), proof: result.proof, expiresIn: result.expires_in };
    } finally { frames = []; }
}
