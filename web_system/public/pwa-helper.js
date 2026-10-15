(function () {
    const dismissedKey = 'bontoc-rescue-pwa-helper-dismissed';
    const versionEndpoint = '/system/version';
    const versionPollInterval = 15000;
    const updateReloadQueryKey = 'system_update';
    let deferredPrompt = null;
    let serviceWorkerRegistration = null;
    let refreshingForUpdate = false;
    let activeAppVersion = null;
    let versionPollHandle = null;
    let reloadHandle = null;

    const standalone = function () {
        return window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    };

    const isIOSDevice = function () {
        return /iPad|iPhone|iPod/.test(window.navigator.userAgent) ||
            (window.navigator.platform === 'MacIntel' && window.navigator.maxTouchPoints > 1);
    };

    const isSafariBrowser = function () {
        return /Safari/i.test(window.navigator.userAgent) &&
            !/CriOS|FxiOS|EdgiOS|OPiOS|Android/i.test(window.navigator.userAgent);
    };

    const supportsServiceWorker = function () {
        return 'serviceWorker' in navigator;
    };

    const isSecureInstallContext = function () {
        return window.isSecureContext === true;
    };

    const ensureStyles = function () {
        if (document.getElementById('bontoc-pwa-helper-style')) {
            return;
        }

        const style = document.createElement('style');
        style.id = 'bontoc-pwa-helper-style';
        style.textContent = `
            .bontoc-pwa-helper {
                position: fixed;
                left: 16px;
                right: 16px;
                bottom: calc(16px + env(safe-area-inset-bottom, 0px));
                z-index: 1600;
                display: grid;
                gap: 12px;
                padding: 16px 18px;
                border-radius: 24px;
                background: rgba(15, 31, 47, 0.95);
                color: #fff;
                box-shadow: 0 24px 60px rgba(12, 25, 39, 0.18);
                backdrop-filter: blur(16px);
            }
            .bontoc-pwa-helper[hidden] { display: none !important; }
            .bontoc-pwa-helper__top {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 12px;
                flex-wrap: wrap;
            }
            .bontoc-pwa-helper__status {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                font-size: 11px;
                font-weight: 800;
                letter-spacing: 0.12em;
                text-transform: uppercase;
                color: rgba(255,255,255,0.78);
            }
            .bontoc-pwa-helper__status::before {
                content: "";
                width: 10px;
                height: 10px;
                border-radius: 999px;
                background: #38d39f;
                box-shadow: 0 0 0 6px rgba(56,211,159,.15);
            }
            .bontoc-pwa-helper__status.is-offline::before {
                background: #d18b1f;
                box-shadow: 0 0 0 6px rgba(209,139,31,.15);
            }
            .bontoc-pwa-helper__title {
                margin: 0;
                font-size: 16px;
                font-weight: 800;
                line-height: 1.35;
            }
            .bontoc-pwa-helper__copy {
                margin: 0;
                font-size: 14px;
                line-height: 1.7;
                color: rgba(255,255,255,0.78);
            }
            .bontoc-pwa-helper__actions {
                display: flex;
                flex-wrap: wrap;
                gap: 10px;
            }
            .bontoc-pwa-helper__btn {
                min-height: 44px;
                border: 1px solid transparent;
                border-radius: 999px;
                padding: 0 16px;
                font-size: 14px;
                font-weight: 800;
                cursor: pointer;
            }
            .bontoc-pwa-helper__btn--primary {
                background: linear-gradient(135deg, #c91c21, #9f1318);
                color: #fff;
            }
            .bontoc-pwa-helper__btn--ghost {
                background: rgba(255,255,255,0.08);
                border-color: rgba(255,255,255,0.16);
                color: #fff;
            }
            @media (max-width: 640px) {
                .bontoc-pwa-helper__actions {
                    flex-direction: column;
                }
                .bontoc-pwa-helper__btn {
                    width: 100%;
                }
            }
        `;

        document.head.appendChild(style);
    };

    const fetchSystemVersion = async function () {
        try {
            const response = await fetch(versionEndpoint, {
                cache: 'no-store',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (!response.ok) {
                return null;
            }

            const payload = await response.json();
            return typeof payload?.version === 'string' ? payload.version : null;
        } catch (error) {
            console.warn('PWA helper version check failed.', error);
            return null;
        }
    };

    const clearApplicationCaches = async function () {
        if (!('caches' in window)) {
            return;
        }

        try {
            const keys = await window.caches.keys();
            await Promise.all(
                keys
                    .filter(function (key) {
                        return key.indexOf('bontoc-rescue') === 0;
                    })
                    .map(function (key) {
                        return window.caches.delete(key);
                    })
            );
        } catch (error) {
            console.warn('PWA helper could not clear old caches.', error);
        }
    };

    const reloadWithFreshAssets = async function () {
        await clearApplicationCaches();

        const nextUrl = new URL(window.location.href);
        nextUrl.searchParams.set(updateReloadQueryKey, String(Date.now()));
        window.location.replace(nextUrl.toString());
    };

    const clearScheduledReload = function () {
        if (reloadHandle) {
            window.clearTimeout(reloadHandle);
            reloadHandle = null;
        }
    };

    const scheduleReload = function (delay) {
        clearScheduledReload();
        reloadHandle = window.setTimeout(function () {
            reloadWithFreshAssets();
        }, delay || 800);
    };

    const ensureHelper = function () {
        let helper = document.querySelector('[data-bontoc-pwa-helper]');

        if (helper) {
            return helper;
        }

        ensureStyles();
        helper = document.createElement('section');
        helper.hidden = true;
        helper.setAttribute('data-bontoc-pwa-helper', 'true');
        helper.className = 'bontoc-pwa-helper';
        helper.innerHTML = `
            <div class="bontoc-pwa-helper__top">
                <span class="bontoc-pwa-helper__status" data-bontoc-pwa-status>Online Ready</span>
                <strong class="bontoc-pwa-helper__title" data-bontoc-pwa-title>Install Bontoc Rescue Sender</strong>
            </div>
            <p class="bontoc-pwa-helper__copy" data-bontoc-pwa-copy>Install the civilian sender app for faster emergency reporting and quick access to your report status.</p>
            <div class="bontoc-pwa-helper__actions">
                <button type="button" class="bontoc-pwa-helper__btn bontoc-pwa-helper__btn--primary" data-bontoc-pwa-install data-install-mode="install">Install App</button>
                <button type="button" class="bontoc-pwa-helper__btn bontoc-pwa-helper__btn--ghost" data-bontoc-pwa-dismiss>Later</button>
            </div>
        `;

        document.body.appendChild(helper);

        helper.querySelector('[data-bontoc-pwa-install]').addEventListener('click', async function () {
            const installMode = this.getAttribute('data-install-mode');

            if (installMode === 'refresh') {
                if (serviceWorkerRegistration && serviceWorkerRegistration.waiting) {
                    refreshingForUpdate = true;
                    serviceWorkerRegistration.waiting.postMessage({ type: 'SKIP_WAITING' });
                } else {
                    window.location.reload();
                }
                return;
            }

            if (installMode !== 'install') {
                localStorage.setItem(dismissedKey, '1');
                helper.hidden = true;
                return;
            }

            if (!await launchInstallPrompt()) {
                showHelper({ forceManualPrompt: true });
            }
        });

        helper.querySelector('[data-bontoc-pwa-dismiss]').addEventListener('click', function () {
            localStorage.setItem(dismissedKey, '1');
            helper.hidden = true;
        });

        return helper;
    };

    const updateStatus = function () {
        const status = document.querySelector('[data-bontoc-pwa-status]');

        if (!status) {
            return;
        }

        const online = navigator.onLine;
        status.textContent = online ? 'Online Ready' : 'Offline Mode';
        status.classList.toggle('is-offline', !online);
    };

    const configureHelperCopy = function (options = {}) {
        const helper = ensureHelper();
        const title = helper.querySelector('[data-bontoc-pwa-title]');
        const copy = helper.querySelector('[data-bontoc-pwa-copy]');
        const install = helper.querySelector('[data-bontoc-pwa-install]');
        const forceManualPrompt = options.forceManualPrompt === true;

        if (standalone()) {
            helper.hidden = true;
            return helper;
        }

        if (!isSecureInstallContext()) {
            title.textContent = 'Use HTTPS to install the app';
            copy.textContent = 'This Wi-Fi test link can open Bontoc Rescue Sender, but Android and iPhone installation needs HTTPS. Use the online secure link to install it, then launch it from the home screen.';
            install.textContent = 'Use Browser Mode';
            install.setAttribute('data-install-mode', 'insecure-context');
            return helper;
        }

        if (isIOSDevice() && isSafariBrowser()) {
            title.textContent = 'Install on iPhone or iPad';
            copy.textContent = 'Tap Share in Safari, then choose Add to Home Screen. After that, open Bontoc Rescue Sender from your home screen like a normal app.';
            install.textContent = 'I Understand';
            install.setAttribute('data-install-mode', 'ios-guide');
            return helper;
        }

        if (!supportsServiceWorker()) {
            title.textContent = 'Browser mode is active';
            copy.textContent = 'This device can still use the system in the browser, but install and offline support need a newer Chrome on Android or Safari on iPhone and iPad.';
            install.textContent = 'Use Browser Mode';
            install.setAttribute('data-install-mode', 'browser-only');
            return helper;
        }

        if (!deferredPrompt && forceManualPrompt) {
            title.textContent = 'Install from your browser menu';
            copy.textContent = 'If the automatic install prompt is not ready yet, open your browser menu and choose Install app or Add to Home Screen. After that, launch Bontoc Rescue Sender like a normal app.';
            install.textContent = 'I Understand';
            install.setAttribute('data-install-mode', 'browser-install-help');
            return helper;
        }

        title.textContent = 'Install Bontoc Rescue Sender';
        copy.textContent = 'Install the civilian sender app for faster emergency reporting and quick access to your report status.';
        install.textContent = 'Install App';
        install.setAttribute('data-install-mode', 'install');
        return helper;
    };

    const launchInstallPrompt = async function () {
        if (!deferredPrompt || !isSecureInstallContext()) {
            return false;
        }

        const helper = document.querySelector('[data-bontoc-pwa-helper]');

        deferredPrompt.prompt();
        const choice = await deferredPrompt.userChoice;

        if (choice.outcome === 'accepted') {
            localStorage.removeItem(dismissedKey);
        }

        deferredPrompt = null;

        if (helper) {
            helper.hidden = true;
        }

        return true;
    };

    const showHelper = function (options = {}) {
        const helper = configureHelperCopy(options);
        updateStatus();
        helper.hidden = false;
        return helper;
    };

    const bindManualTriggers = function () {
        document.querySelectorAll('[data-open-pwa-helper]').forEach(function (trigger) {
            trigger.addEventListener('click', async function () {
                localStorage.removeItem(dismissedKey);

                if (await launchInstallPrompt()) {
                    return;
                }

                showHelper({ forceManualPrompt: true });
            });
        });
    };

    const showUpdateHelper = function () {
        const helper = ensureHelper();
        helper.querySelector('[data-bontoc-pwa-title]').textContent = 'Applying latest app update';
        helper.querySelector('[data-bontoc-pwa-copy]').textContent = 'The system found a newer version and will refresh automatically so the website and installed app show the latest changes.';
        helper.querySelector('[data-bontoc-pwa-install]').textContent = 'Refresh Now';
        helper.querySelector('[data-bontoc-pwa-install]').setAttribute('data-install-mode', 'refresh');
        updateStatus();
        helper.hidden = false;
    };

    const activateWaitingWorker = function (worker) {
        const waitingWorker = worker || serviceWorkerRegistration?.waiting;

        if (!waitingWorker) {
            return false;
        }

        waitingWorker.postMessage({ type: 'SKIP_WAITING' });
        return true;
    };

    const beginAutomaticUpdate = function (worker) {
        if (refreshingForUpdate) {
            return;
        }

        showUpdateHelper();
        refreshingForUpdate = true;
        const activatedWaitingWorker = activateWaitingWorker(worker);
        scheduleReload(activatedWaitingWorker ? 2500 : 800);
    };

    const requestServiceWorkerUpdate = async function () {
        if (!serviceWorkerRegistration) {
            return;
        }

        try {
            await serviceWorkerRegistration.update();
        } catch (error) {
            console.warn('PWA helper service worker update check failed.', error);
        }
    };

    const checkForApplicationUpdates = async function () {
        if (refreshingForUpdate) {
            return;
        }

        await requestServiceWorkerUpdate();

        if (serviceWorkerRegistration?.waiting) {
            beginAutomaticUpdate();
            return;
        }

        const latestVersion = await fetchSystemVersion();

        if (!latestVersion) {
            return;
        }

        if (!activeAppVersion) {
            activeAppVersion = latestVersion;
            return;
        }

        if (latestVersion !== activeAppVersion) {
            beginAutomaticUpdate();
        }
    };

    const scheduleVersionPolling = function () {
        if (versionPollHandle) {
            return;
        }

        versionPollHandle = window.setInterval(function () {
            checkForApplicationUpdates();
        }, versionPollInterval);
    };

    window.addEventListener('load', function () {
        if (supportsServiceWorker()) {
            fetchSystemVersion().then(function (version) {
                activeAppVersion = activeAppVersion || version;
                const workerUrl = version ? `/sw.js?v=${encodeURIComponent(version)}` : '/sw.js';
                return navigator.serviceWorker.register(workerUrl, { scope: '/' });
            }).then(function (registration) {
                serviceWorkerRegistration = registration;

                if (registration.waiting) {
                    beginAutomaticUpdate();
                }

                registration.addEventListener('updatefound', function () {
                    const newWorker = registration.installing;

                    if (!newWorker) {
                        return;
                    }

                    newWorker.addEventListener('statechange', function () {
                        if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
                            beginAutomaticUpdate(newWorker);
                        }
                    });
                });

                scheduleVersionPolling();
                window.setTimeout(function () {
                    checkForApplicationUpdates();
                }, 3000);
            }).catch(function (error) {
                console.warn('PWA helper service worker registration failed.', error);
            });
        }

        if (!standalone() && localStorage.getItem(dismissedKey) !== '1' && ((isIOSDevice() && isSafariBrowser()) || !supportsServiceWorker())) {
            const helper = configureHelperCopy();
            updateStatus();
            helper.hidden = false;
        }
    });

    window.addEventListener('beforeinstallprompt', function (event) {
        event.preventDefault();
        deferredPrompt = event;

        if (standalone() || localStorage.getItem(dismissedKey) === '1') {
            return;
        }

        const helper = configureHelperCopy();
        updateStatus();
        helper.hidden = false;
    });

    window.addEventListener('appinstalled', function () {
        deferredPrompt = null;
        localStorage.removeItem(dismissedKey);
        const helper = document.querySelector('[data-bontoc-pwa-helper]');
        if (helper) {
            helper.hidden = true;
        }
    });

    navigator.serviceWorker?.addEventListener('controllerchange', function () {
        if (!refreshingForUpdate) {
            return;
        }

        clearScheduledReload();
        refreshingForUpdate = false;
        reloadWithFreshAssets();
    });

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') {
            checkForApplicationUpdates();
        }
    });

    window.addEventListener('focus', function () {
        checkForApplicationUpdates();
    });

    window.addEventListener('pageshow', function (event) {
        if (event.persisted) {
            checkForApplicationUpdates();
        }
    });

    window.addEventListener('online', updateStatus);
    window.addEventListener('offline', updateStatus);
})();
