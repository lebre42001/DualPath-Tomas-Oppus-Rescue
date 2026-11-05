export class ReportDeliveryError extends Error {
    constructor(reason, message) { super(message); this.name = 'ReportDeliveryError'; this.reason = reason; }
}

export function receivedReportUrl(data, origin = globalThis.location?.origin) {
    if (data?.received !== true || typeof data.redirect_url !== 'string') return null;
    try {
        const url = new URL(data.redirect_url, origin);
        return url.origin === origin && /^\/reports\/\d+\/success$/.test(url.pathname) ? url.href : null;
    } catch { return null; }
}

async function requestJson(url, options, { fetchImpl = globalThis.fetch, signal, timeoutMs = 90000 } = {}) {
    const controller = new AbortController();
    let timedOut = false;
    const abort = () => controller.abort();
    if (signal?.aborted) abort(); else signal?.addEventListener('abort', abort, { once: true });
    const timer = setTimeout(() => { timedOut = true; controller.abort(); }, timeoutMs);
    try {
        const response = await fetchImpl(url, {
            ...options, credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        if ([401, 403, 419].includes(response.status) || response.redirected) {
            throw new ReportDeliveryError('session', 'Sign in or refresh your account session, then continue this saved draft.');
        }
        let data;
        try { data = await response.json(); }
        catch (error) { if (controller.signal.aborted) throw error; throw new ReportDeliveryError('server', 'The server response could not be confirmed. Your draft is kept.'); }
        if (!response.ok) {
            if (response.status === 422) throw new ReportDeliveryError('validation', Object.values(data.errors ?? {}).flat()[0] || 'Review the report details and verification before sending again.');
            throw new ReportDeliveryError('server', 'The server could not confirm receipt. Your draft is kept for another attempt.');
        }
        return data;
    } catch (error) {
        if (error instanceof ReportDeliveryError) throw error;
        const reason = timedOut ? 'slow' : signal?.aborted ? (signal.reason === 'offline' ? 'offline' : 'interrupted') : 'connection';
        throw new ReportDeliveryError(reason, reason === 'slow'
            ? 'Sending took too long. Your draft is kept; delivery has not been confirmed.'
            : 'The connection was interrupted. Your draft is kept; delivery has not been confirmed.');
    } finally {
        clearTimeout(timer);
        signal?.removeEventListener('abort', abort);
    }
}

export const checkReportReceipt = (url, options = {}) => requestJson(url, { method: 'GET' }, { timeoutMs: 12000, ...options });
export const uploadReport = (url, body, options = {}) => requestJson(url, { method: 'POST', body }, options);
