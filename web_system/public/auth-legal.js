const bootAuthLegalGates = () => {
    document.querySelectorAll('[data-legal-gate]').forEach((form) => {
        const submit = form.querySelector('[data-legal-gate-submit]');
        const status = form.querySelector('[data-legal-gate-status]');
        const checks = [...form.querySelectorAll('input[type="checkbox"][required]')];
        if (!submit || checks.length === 0) return;

        const sync = () => {
            const ready = checks.every((checkbox) => checkbox.checked);
            submit.disabled = !ready;
            submit.setAttribute('aria-disabled', String(!ready));
            if (status) status.textContent = ready
                ? 'You can now continue with Google.'
                : 'Agree to the terms and acknowledge the Privacy Policy to continue with Google.';
            return ready;
        };
        checks.forEach((checkbox) => checkbox.addEventListener('change', sync));
        form.addEventListener('reset', () => window.setTimeout(sync, 0));
        form.addEventListener('submit', (event) => {
            if (!sync()) {
                event.preventDefault();
                form.reportValidity();
            }
        });
        window.addEventListener('pageshow', sync);
        sync();
    });
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootAuthLegalGates, { once: true });
} else {
    bootAuthLegalGates();
}
