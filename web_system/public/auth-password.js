const bootAuthPasswordToggles = () => {
    document.querySelectorAll('[data-auth-toggle-target]').forEach((button) => {
        button.addEventListener('click', () => {
            const targetId = button.getAttribute('data-auth-toggle-target');

            if (!targetId) {
                return;
            }

            const input = document.getElementById(targetId);

            if (!(input instanceof HTMLInputElement)) {
                return;
            }

            const shouldShow = input.type === 'password';
            input.type = shouldShow ? 'text' : 'password';
            button.textContent = shouldShow ? 'Hide' : 'Show';
            button.setAttribute('aria-pressed', shouldShow ? 'true' : 'false');
        });
    });
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootAuthPasswordToggles, { once: true });
} else {
    bootAuthPasswordToggles();
}
