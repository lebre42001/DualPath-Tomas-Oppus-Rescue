// The legacy loader can finish after DOMContentLoaded has already fired.
export function onDomReady(initialize) {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize, { once: true });
    } else {
        window.setTimeout(initialize, 0);
    }
}
