(function () {
    // Capture the browser's single-use prompt before page scripts finish loading.
    if (window.RescueInstall) return;

    let promptEvent = null;
    let prompting = false;
    let installed = false;
    let registrationPromise = null;
    const scriptUrl = new URL(document.currentScript.src, window.location.href);
    const version = scriptUrl.searchParams.get('v');

    const notify = function (name, detail) {
        window.dispatchEvent(new CustomEvent(name, { detail: detail }));
    };

    const prepare = function () {
        if (!window.isSecureContext || !('serviceWorker' in navigator)) {
            return Promise.resolve(null);
        }
        if (!registrationPromise) {
            const workerUrl = version ? '/sw.js?v=' + encodeURIComponent(version) : '/sw.js';
            registrationPromise = navigator.serviceWorker.register(workerUrl, { scope: '/' })
                .catch(function () {
                    registrationPromise = null;
                    return null;
                });
        }
        return registrationPromise;
    };

    window.RescueInstall = {
        get available() { return promptEvent !== null && !prompting; },
        prepare: prepare,
        request: async function () {
            if (prompting) return 'busy';
            if (!promptEvent) {
                void prepare();
                return 'unavailable';
            }
            const candidate = promptEvent;
            promptEvent = null;
            prompting = true;
            let outcome;
            try {
                // Keep this call inside the user's click, before any await.
                await candidate.prompt();
                const choice = await candidate.userChoice;
                outcome = installed ? 'installed' : (choice.outcome === 'accepted' ? 'accepted' : 'dismissed');
            } catch (error) {
                outcome = 'error';
            } finally {
                prompting = false;
            }
            notify('rescue:install-result', { outcome: outcome });
            return outcome;
        },
    };

    window.addEventListener('beforeinstallprompt', function (event) {
        event.preventDefault();
        installed = false;
        promptEvent = event;
        notify('rescue:install-ready');
    });

    window.addEventListener('appinstalled', function () {
        installed = true;
        promptEvent = null;
        notify('rescue:installed');
    });

    void prepare();
})();
