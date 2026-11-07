export function isFreshReportPosition(position, now = Date.now()) {
    const { latitude, longitude } = position?.coords ?? {};
    return Number.isFinite(latitude) && Number.isFinite(longitude)
        && Math.abs(latitude) <= 90 && Math.abs(longitude) <= 180
        && Number.isFinite(position?.timestamp) && Math.abs(now - position.timestamp) <= 30000;
}

// Browser permission is distinct from a working device location service. Never
// persist coordinates or a fabricated grant, and never watch location in background.
export function createReportLocationAccess({ navigator: nav = navigator, window: win = window, document: doc = document,
    automatic = false, onClear = () => {}, onBusy = () => {}, onPosition = () => {},
    onError = () => {}, onPermission = () => {}, now = Date.now } = {}) {
    let active = null, generation = 0, inspection = 0, permission = null, started = false, stopped = false;
    const secure = () => win.isSecureContext || ['localhost', '127.0.0.1'].includes(win.location?.hostname);
    const reset = () => {
        generation++; inspection++;
        const previous = active; active = null;
        previous?.resolve(false);
        onClear(); onBusy(false);
    };
    const request = () => {
        if (stopped || doc.hidden) return Promise.resolve(false);
        if (active) return active.promise;
        const current = { generation: ++generation, resolve: () => {}, promise: null };
        active = current; onClear(); onBusy(true);
        current.promise = new Promise(resolve => {
            current.resolve = resolve;
            const finish = (position, error) => {
                if (stopped || active !== current || current.generation !== generation) return;
                if (doc.hidden) { reset(); return; }
                if (!error && !isFreshReportPosition(position, now())) {
                    error = { code: 0, message: 'A fresh GPS reading could not be obtained. Enable device location and try again.' };
                }
                active = null;
                if (error) { onClear(); onError(error); } else onPosition(position);
                onBusy(false); resolve(!error);
            };
            if (!secure() || typeof nav.geolocation?.getCurrentPosition !== 'function') {
                finish(null, { code: 0, message: 'Location requires HTTPS and a supported browser. Open AksyRes in your browser, then allow location access.' });
                return;
            }
            try {
                nav.geolocation.getCurrentPosition(position => finish(position), error => finish(null, error), {
                    enableHighAccuracy: true, maximumAge: 0, timeout: 15000,
                });
            } catch (error) { finish(null, error); }
        });
        return current.promise;
    };
    const changed = () => {
        if (stopped) return;
        const state = permission?.state ?? 'unknown';
        if (state !== 'granted') reset();
        onPermission(state);
        if (automatic && state === 'granted' && !doc.hidden) void request();
    };
    const detachPermission = () => {
        permission?.removeEventListener?.('change', changed);
        if (permission?.onchange === changed) permission.onchange = null;
    };
    const refresh = async () => {
        if (stopped || doc.hidden) return false;
        const token = ++inspection;
        let result = null;
        try { result = await nav.permissions?.query({ name: 'geolocation' }); } catch { /* Manual access still works. */ }
        if (stopped || doc.hidden || token !== inspection) return false;
        if (permission !== result) {
            detachPermission(); permission = result;
            if (permission?.addEventListener) permission.addEventListener('change', changed);
            else if (permission) permission.onchange = changed;
        }
        const state = permission?.state ?? 'unknown';
        // An initial permission query must not cancel a user-triggered request
        // that is currently displaying the browser's Allow prompt.
        if (state !== 'granted' && (!active || state === 'denied')) reset();
        onPermission(state);
        return automatic && state === 'granted' ? request() : false;
    };
    const visibility = () => { if (doc.hidden) reset(); else void refresh(); };
    const pageShow = event => { if (event.persisted) { reset(); void refresh(); } };
    const pageHide = () => reset();
    return {
        request, reset, refresh,
        start() {
            if (stopped) return Promise.resolve(false);
            if (!started) {
                started = true; reset();
                doc.addEventListener('visibilitychange', visibility);
                win.addEventListener('pageshow', pageShow);
                win.addEventListener('pagehide', pageHide);
            }
            return refresh();
        },
        stop() {
            stopped = true; reset(); detachPermission();
            doc.removeEventListener('visibilitychange', visibility);
            win.removeEventListener('pageshow', pageShow);
            win.removeEventListener('pagehide', pageHide);
        },
    };
}
