import { onDomReady } from './dom-ready';

onDomReady(() => {
    const list = document.querySelector('[data-lora-node-list]');
    const modal = document.querySelector('[data-node-restart-modal]');
    if (!list || !modal) return;
    document.body.append(modal);
    const confirm = modal.querySelector('[data-node-restart-confirm]');
    const cancel = modal.querySelector('[data-node-restart-cancel]');
    const feedback = modal.querySelector('[data-node-restart-feedback]');
    const title = modal.querySelector('[data-node-restart-title]');
    const message = modal.querySelector('[data-node-restart-message]');
    let trigger, nodeId, endpoint, busy = false, queued = false;
    const close = () => {
        if (busy) return;
        document.dispatchEvent(new CustomEvent('stitch:dialog-close', { detail: { modal } }));
        modal.hidden = true;
        document.dispatchEvent(new Event('stitch:node-restart-updated'));
    };
    list.addEventListener('click', event => {
        const button = event.target.closest('[data-node-restart]');
        if (!button || button.disabled || !modal.hidden) return;
        trigger = button; nodeId = button.dataset.nodeId; endpoint = button.dataset.restartUrl;
        busy = queued = false;
        title.textContent = `Restart ${nodeId}?`;
        message.textContent = nodeId === 'NODE_1'
            ? 'The gateway will briefly disconnect the whole mesh from the server. Saved pending reports stay queued and retry after reconnection. Devices using its Wi-Fi may need to reconnect.'
            : 'This node and nodes using it as a relay will briefly disconnect. Saved pending reports stay queued and retry after reconnection. An unfinished form on the node Wi-Fi may need to be reopened.';
        feedback.textContent = '';
        cancel.textContent = 'Cancel'; confirm.hidden = false; confirm.disabled = false;
        confirm.textContent = 'Restart node';
        document.dispatchEvent(new CustomEvent('stitch:dialog-open', { detail: { modal, close, opener: trigger } }));
        modal.hidden = false;
    });
    cancel.addEventListener('click', close);
    modal.addEventListener('click', event => { if (event.target === modal) close(); });
    confirm.addEventListener('click', async () => {
        if (busy || queued) return;
        busy = true; confirm.disabled = true; cancel.disabled = true;
        feedback.textContent = 'Requesting a restart…';
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), 15000);
        try {
            const response = await fetch(endpoint, {
                method: 'POST', credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
                headers: { Accept: 'application/json', 'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '' },
                body: JSON.stringify({ confirm: true }),
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok || response.redirected) throw new Error(Object.values(data.errors || {}).flat()[0] || data.message || 'The restart request could not be confirmed. Refresh the node list before trying again.');
            if (!data.command || data.command.node_id !== nodeId || !['queued', 'sent', 'accepted'].includes(data.command.status)) throw new Error('The server did not confirm a pending restart request. Refresh the node list before trying again.');
            queued = true;
            title.textContent = `Restart requested: ${nodeId}`;
            feedback.textContent = 'Queued for delivery. This does not mean the node has restarted. Check its restart status in LoRa Mesh; the request expires if it cannot be delivered in time.';
            confirm.hidden = true; cancel.textContent = 'OK';
        } catch (error) {
            feedback.textContent = error.name === 'AbortError'
                ? 'Request timed out. It may already be queued. Close this dialog and refresh the node list before trying again.'
                : error.message;
            // Close and refresh first, so a lost response cannot cause blind repeats.
            confirm.hidden = true; cancel.textContent = 'Close and refresh';
        } finally {
            clearTimeout(timer); busy = false; cancel.disabled = false; cancel.focus();
        }
    });
});
