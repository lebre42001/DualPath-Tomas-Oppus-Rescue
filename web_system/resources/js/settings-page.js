function initializeSettingsPage() {
    const root = document.querySelector('[data-settings-root]');
    if (!root) return;

    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
    const statusTitle = document.querySelector('[data-settings-status]');
    const statusMessage = document.querySelector('[data-settings-message]');
    const critical = document.querySelector('[data-setting-input="critical_alerts"]');
    const push = document.querySelector('[data-setting-input="push_notifications"]');
    const sms = document.querySelector('[data-setting-input="sms_backup"]');
    const connectivityButtons = Array.from(document.querySelectorAll('[data-connectivity-mode]'));
    const connectivityLabel = document.querySelector('[data-connectivity-label]');
    const themeButtons = Array.from(document.querySelectorAll('[data-theme-choice]'));
    const themeStatus = document.querySelector('[data-theme-status]');
    const themeController = window.StitchTheme;
    let connectivityMode = root.dataset.connectivityInitial || 'auto_select';

    const setStatus = (title, message) => {
        if (statusTitle) {
            statusTitle.textContent = title;
        }

        if (statusMessage) {
            statusMessage.textContent = message;
        }
    };

    const paintConnectivityButtons = () => {
        connectivityButtons.forEach((button) => {
            const active = button.dataset.connectivityMode === connectivityMode;
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
            button.style.borderColor = active ? 'rgba(32,104,174,.24)' : 'rgba(15,31,47,.08)';
            button.style.boxShadow = active ? '0 12px 24px rgba(32,104,174,.10)' : 'none';
        });

        if (connectivityLabel) {
            connectivityLabel.textContent = connectivityMode === 'lora_fallback'
                ? 'LoRa fallback is enabled for this account.'
                : 'Automatic connectivity selection is enabled for this account.';
        }
    };

    const paintThemeButtons = (state) => {
        themeButtons.forEach((button) => {
            const active = button.dataset.themeChoice === state.preference;
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
            button.style.borderColor = active ? 'rgba(201,28,33,.24)' : 'rgba(15,31,47,.08)';
            button.style.boxShadow = active ? '0 12px 24px rgba(201,28,33,.10)' : 'none';
        });
        if (themeStatus) {
            const mode = state.theme === 'dark' ? 'dark' : 'light';
            const description = state.preference === 'system'
                ? `Following your device: ${mode} mode.`
                : `${mode === 'dark' ? 'Dark' : 'Light'} mode is selected for this browser.`;
            themeStatus.textContent = state.storageAvailable
                ? description
                : `${description} Storage is unavailable; this choice lasts for this page.`;
        }
    };

    if (themeController) {
        themeController.subscribe(paintThemeButtons);
        themeButtons.forEach((button) => {
            button.addEventListener('click', () => {
                themeController.setPreference(button.dataset.themeChoice);
            });
        });
    }

    if (!csrf || !critical || !push || !sms) {
        return;
    }

    const saveSettings = async () => {
        setStatus('Saving', 'Updating your settings now...');

        try {
            const response = await fetch(root.dataset.settingsEndpoint, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: new URLSearchParams({
                    _token: csrf,
                    critical_alerts: critical.checked ? '1' : '0',
                    push_notifications: push.checked ? '1' : '0',
                    sms_backup: sms.checked ? '1' : '0',
                    connectivity_mode: connectivityMode,
                }),
            });

            if (!response.ok) {
                throw new Error('Unable to save settings.');
            }

            const data = await response.json();
            connectivityMode = data.connectivity_mode ?? connectivityMode;
            paintConnectivityButtons();
            setStatus('Saved', 'Notification and connectivity changes were saved successfully.');
        } catch (error) {
            setStatus('Save failed', 'The page could not save your settings. Please try again.');
        }
    };

    [critical, push, sms].forEach((input) => {
        input.addEventListener('change', saveSettings);
    });

    connectivityButtons.forEach((button) => {
        button.addEventListener('click', () => {
            connectivityMode = button.dataset.connectivityMode || 'auto_select';
            paintConnectivityButtons();
            saveSettings();
        });
    });

    paintConnectivityButtons();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeSettingsPage, { once: true });
} else {
    initializeSettingsPage();
}
