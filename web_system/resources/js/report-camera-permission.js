export function initializeReportCameraPermission(root, { navigator: nav = navigator, window: win = window, document: doc = document } = {}) {
    const button = root.querySelector('[data-report-camera-permission]');
    const status = root.querySelector('[data-report-camera-permission-status]');
    if (!button || !status) return null;
    let generation = 0, permission = null, stopped = false, busy = false;
    const message = state => {
        status.textContent = state === 'granted'
            ? 'Camera access is allowed. The camera opens only when you choose Capture Photo or start a live selfie check.'
            : state === 'denied'
                ? 'Camera access is blocked. Allow Camera for this site in your browser settings, then return and try again.'
                : 'Tap Allow camera to request access. Your browser controls how long permission is remembered.';
    };
    const invalidate = () => { generation++; busy = false; button.disabled = false; };
    const changed = () => {
        if (permission?.state !== 'granted') invalidate();
        if (!busy) message(permission?.state);
    };
    const refresh = async () => {
        const token = generation;
        let next = null;
        try { next = await nav.permissions?.query({ name: 'camera' }); } catch { /* Camera queries are not supported everywhere. */ }
        if (stopped || busy || token !== generation || doc.hidden) return;
        if (permission !== next) {
            permission?.removeEventListener?.('change', changed); permission = next;
            permission?.addEventListener?.('change', changed);
        }
        message(permission?.state);
    };
    const request = async () => {
        if (busy || stopped || doc.hidden) return false;
        const token = ++generation;
        busy = true; button.disabled = true; status.textContent = 'Requesting camera access...';
        let stream = null;
        try {
            if (!(win.isSecureContext || ['localhost', '127.0.0.1'].includes(win.location?.hostname)) || !nav.mediaDevices?.getUserMedia) {
                throw new Error('Camera access needs HTTPS and a supported browser. Open AksyRes in your device browser.');
            }
            stream = await nav.mediaDevices.getUserMedia({ video: true, audio: false });
            // This is a permission check, not a photo or a verification session.
            stream.getTracks().forEach(track => track.stop()); stream = null;
            if (stopped || token !== generation || doc.hidden) return false;
            message('granted'); return true;
        } catch (error) {
            if (stopped || token !== generation || doc.hidden) return false;
            status.textContent = ['NotAllowedError', 'SecurityError'].includes(error.name)
                ? 'Camera access was not allowed. You can enable it in browser site settings and try again.'
                : error.name === 'NotFoundError' ? 'No camera was found. Use a device with a camera for the live selfie check.'
                    : error.message || 'Camera access could not be checked. Close other camera apps and try again.';
            return false;
        } finally {
            stream?.getTracks().forEach(track => track.stop());
            if (token === generation) { busy = false; button.disabled = false; }
        }
    };
    const visibility = () => {
        if (doc.hidden) { invalidate(); status.textContent = 'Camera check stopped. Return to check access again.'; }
        else void refresh();
    };
    const pageHide = () => invalidate();
    button.addEventListener('click', request);
    doc.addEventListener('visibilitychange', visibility);
    win.addEventListener('pagehide', pageHide);
    void refresh();
    return { request, refresh, stop() {
        stopped = true; invalidate(); permission?.removeEventListener?.('change', changed);
        button.removeEventListener('click', request); doc.removeEventListener('visibilitychange', visibility);
        win.removeEventListener('pagehide', pageHide);
    } };
}
