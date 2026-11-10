// Only a confirmed server receipt can open the Successful state.
export function initializeReportDeliveryFeedback({ document = globalThis.document } = {}) {
    const modal = document.querySelector('[data-report-delivery-result]');
    if (!modal) return null;
    document.body.append(modal);
    const title = modal.querySelector('[data-report-delivery-title]');
    const message = modal.querySelector('[data-report-delivery-message]');
    const reference = modal.querySelector('[data-report-delivery-reference]');
    const action = modal.querySelector('[data-report-delivery-action]');
    let finish;
    const close = () => {
        document.dispatchEvent(new CustomEvent('stitch:dialog-close', { detail: { modal } }));
        modal.hidden = true;
        const resolve = finish; finish = null; resolve?.();
    };
    action.addEventListener('click', close);
    modal.addEventListener('click', event => { if (event.target === modal && modal.dataset.state === 'failed') close(); });
    const show = (state, text, code = '') => new Promise(resolve => {
        finish = resolve;
        modal.dataset.state = state;
        title.textContent = state === 'successful' ? 'Successful' : 'Failed';
        message.textContent = text;
        reference.textContent = code ? `Reference: ${code}` : '';
        reference.hidden = !code;
        action.textContent = state === 'successful' ? 'OK' : 'Retry';
        document.dispatchEvent(new CustomEvent('stitch:dialog-open', { detail: { modal, close, opener: document.activeElement } }));
        modal.hidden = false;
        action.focus();
    });
    return {
        successful: code => show('successful', 'Your report was received successfully. It is now waiting for admin review.', code),
        failed: text => show('failed', text),
    };
}
