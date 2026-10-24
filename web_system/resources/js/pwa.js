import { onDomReady } from './dom-ready';
const PWA_DISMISS_KEY = 'bontoc-rescue-pwa-dismissed';
const APP_VERSION_ENDPOINT = '/system/version';
const APP_VERSION_POLL_INTERVAL = 15000;
const UPDATE_RELOAD_QUERY_KEY = 'system_update';
let serviceWorkerRegistration = null;
let refreshingForUpdate = false;
let activeAppVersion = null;
let appVersionPollHandle = null;
let updateReloadHandle = null;
let installDismissedThisVisit = false;
let installedThisVisit = false;

const setInstallDismissed = (dismissed) => {
    installDismissedThisVisit = dismissed;
    try {
        if (dismissed) window.localStorage.setItem(PWA_DISMISS_KEY, '1');
        else window.localStorage.removeItem(PWA_DISMISS_KEY);
    } catch { /* Restricted storage must not block installation. */ }
};

const isIOSDevice = () =>
    /iPad|iPhone|iPod/.test(window.navigator.userAgent) ||
    (window.navigator.platform === 'MacIntel' && window.navigator.maxTouchPoints > 1);

const isSafariBrowser = () =>
    /Safari/i.test(window.navigator.userAgent) &&
    !/CriOS|FxiOS|EdgiOS|OPiOS|Android/i.test(window.navigator.userAgent);

const supportsServiceWorker = () => 'serviceWorker' in navigator;

const isSecureInstallContext = () => window.isSecureContext === true;

const inStandaloneMode = () =>
    window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;

const updateDisplayMode = () => {
    const displayMode = document.querySelector('[data-pwa-display-mode]');
    const standalone = inStandaloneMode();

    if (!displayMode) {
        document.body?.classList.toggle('is-standalone', standalone);
        document.body?.setAttribute('data-display-mode', standalone ? 'standalone' : 'browser');
        return;
    }

    displayMode.textContent = standalone ? 'Installed' : 'Browser';
    document.body?.classList.toggle('is-standalone', standalone);
    document.body?.setAttribute('data-display-mode', standalone ? 'standalone' : 'browser');
};

const updateNetworkStatus = () => {
    const pill = document.querySelector('[data-pwa-status-pill]');
    const label = document.querySelector('[data-pwa-status-label]');

    if (!pill || !label) {
        return;
    }

    const isOnline = window.navigator.onLine;
    pill.classList.toggle('is-offline', !isOnline);
    label.textContent = isOnline ? 'Online Ready' : 'Offline Mode';
};

const canOfferInstall = () => !installedThisVisit && !inStandaloneMode();

const updateInstallIndicator = () => {
    const indicator = document.querySelector('[data-pwa-install-toggle]');
    const visible = document.querySelector('[data-pwa-install-card]')?.hidden === false;
    const offer = canOfferInstall() && !!indicator;
    if (indicator) {
        indicator.hidden = !offer || visible;
        indicator.setAttribute('aria-expanded', String(visible));
    }
    document.body?.classList.toggle('has-pwa-install-offer', offer);
};

const toggleInstallCard = (visible) => {
    const card = document.querySelector('[data-pwa-install-card]');

    if (!card) {
        return;
    }

    const restoreFocus = card.contains(document.activeElement);
    card.hidden = !(visible && canOfferInstall());
    if (!card.hidden) card.scrollTop = 0;
    updateInstallIndicator();
    if (card.hidden && restoreFocus) document.querySelector('[data-pwa-install-toggle]:not([hidden])')?.focus({ preventScroll: true });
};

const toggleUpdateCard = (visible) => {
    const card = document.querySelector('[data-pwa-update-card]');

    if (!card) {
        return;
    }

    card.hidden = !visible;
};

const setUpdateCardContent = ({
    eyebrow = 'Updating App',
    title = 'Applying latest system update.',
    copy = 'The website and installed app will reload automatically with the newest emergency workflow and fixes.',
} = {}) => {
    const eyebrowLabel = document.querySelector('[data-pwa-update-eyebrow]');
    const titleLabel = document.querySelector('[data-pwa-update-title]');
    const copyLabel = document.querySelector('[data-pwa-update-copy]');

    if (eyebrowLabel) {
        eyebrowLabel.textContent = eyebrow;
    }

    if (titleLabel) {
        titleLabel.textContent = title;
    }

    if (copyLabel) {
        copyLabel.textContent = copy;
    }
};

const setInstallCardContent = () => {
    const card = document.querySelector('[data-pwa-install-card]');

    if (!card) {
        return;
    }

    const title = card.querySelector('[data-pwa-install-title]');
    const copy = card.querySelector('[data-pwa-install-copy]');
    const primary = card.querySelector('[data-pwa-install-action]');
    const dismiss = card.querySelector('[data-pwa-dismiss-action]');

    if (!title || !copy || !primary || !dismiss) {
        return;
    }

    if (!isSecureInstallContext()) {
        title.textContent = 'Use HTTPS to install the app';
        copy.textContent = 'This Wi-Fi test link can open ResQMesh, but Android and iPhone installation needs HTTPS. Use the online secure link to install it, then launch it from the home screen.';
        primary.textContent = 'Use Browser Mode';
        primary.dataset.installMode = 'insecure-context';
        dismiss.textContent = 'Later';
        return;
    }

    if (isIOSDevice() && !inStandaloneMode()) {
        title.textContent = 'Install ResQMesh on iPhone or iPad';
        copy.textContent = 'Open this website in Safari. Tap Share, choose Add to Home Screen, then tap Add. Launch ResQMesh from your home screen.';
        primary.textContent = 'I Understand';
        primary.dataset.installMode = 'ios-instructions';
        dismiss.textContent = 'Later';
        return;
    }

    if (!supportsServiceWorker()) {
        title.textContent = 'Browser mode is active';
        copy.textContent = 'This device can still use the civilian sender in the browser, but installation needs a newer Chrome on Android or Safari on iPhone/iPad.';
        primary.textContent = 'Use Browser Mode';
        primary.dataset.installMode = 'compatibility-info';
        dismiss.textContent = 'Later';
        return;
    }

    title.textContent = 'Install the civilian sender app.';
    copy.textContent = window.RescueInstall?.available
        ? 'Add ResQMesh to your phone for faster emergency reporting and quick access to your report status.'
        : 'In Chrome, open the browser menu (\u22ee), choose Add to Home screen or Install app, then confirm Install. In Edge, choose Apps, then Install this site as an app. Use a normal tab. If already installed, open it from your apps.';
    primary.textContent = window.RescueInstall?.available ? 'Install App' : 'Close Instructions';
    primary.dataset.installMode = window.RescueInstall?.available ? 'install' : 'browser-install-help';
    dismiss.textContent = 'Later';
};

const shouldShowInstallCard = () => {
    if (!canOfferInstall() || installDismissedThisVisit
        || document.querySelector('[data-account-verification-root], [data-report-form-root]')) {
        return false;
    }

    try { return window.localStorage.getItem(PWA_DISMISS_KEY) !== '1'; } catch { return true; }
};

const clearPendingReload = () => {
    if (updateReloadHandle) {
        window.clearTimeout(updateReloadHandle);
        updateReloadHandle = null;
    }
};

const clearApplicationCaches = async () => {
    if (!('caches' in window)) {
        return;
    }

    try {
        const keys = await window.caches.keys();
        await Promise.all(
            keys
                .filter((key) => key.startsWith('bontoc-rescue'))
                .map((key) => window.caches.delete(key))
        );
    } catch (error) {
        console.warn('Unable to clear old app caches.', error);
    }
};

const reloadWithFreshAssets = async () => {
    await clearApplicationCaches();

    const nextUrl = new URL(window.location.href);
    nextUrl.searchParams.set(UPDATE_RELOAD_QUERY_KEY, String(Date.now()));
    window.location.replace(nextUrl.toString());
};

const scheduleReloadForUpdate = (delay = 800) => {
    clearPendingReload();

    updateReloadHandle = window.setTimeout(() => {
        void reloadWithFreshAssets();
    }, delay);
};

const activateWaitingWorker = (worker = null) => {
    const waitingWorker = worker ?? serviceWorkerRegistration?.waiting;

    if (!waitingWorker) {
        return false;
    }

    waitingWorker.postMessage({ type: 'SKIP_WAITING' });
    return true;
};

const beginAutomaticUpdate = (worker = null) => {
    setUpdateCardContent();
    toggleUpdateCard(true);
    refreshingForUpdate = true;

    const activatedWaitingWorker = activateWaitingWorker(worker);
    scheduleReloadForUpdate(activatedWaitingWorker ? 2500 : 800);
};

const fetchSystemVersion = async () => {
    try {
        const response = await fetch(APP_VERSION_ENDPOINT, {
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
        console.warn('System version check failed.', error);
        return null;
    }
};

const requestServiceWorkerUpdate = async () => {
    if (!serviceWorkerRegistration) {
        return;
    }

    try {
        await serviceWorkerRegistration.update();
    } catch (error) {
        console.warn('Service worker update check failed.', error);
    }
};

const captureCurrentAppVersion = async () => {
    activeAppVersion = await fetchSystemVersion();
};

const checkForApplicationUpdates = async () => {
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

const scheduleVersionPolling = () => {
    if (appVersionPollHandle) {
        return;
    }

    appVersionPollHandle = window.setInterval(() => {
        void checkForApplicationUpdates();
    }, APP_VERSION_POLL_INTERVAL);
};

const registerServiceWorker = async () => {
    if (!supportsServiceWorker()) {
        return;
    }

    try {
        const version = activeAppVersion ?? await fetchSystemVersion();
        activeAppVersion = activeAppVersion ?? version;
        const workerUrl = version ? `/sw.js?v=${encodeURIComponent(version)}` : '/sw.js';
        serviceWorkerRegistration = await navigator.serviceWorker.register(workerUrl, { scope: '/' });

        if (serviceWorkerRegistration.waiting) {
            beginAutomaticUpdate();
        }

        serviceWorkerRegistration.addEventListener('updatefound', () => {
            const newWorker = serviceWorkerRegistration?.installing;

            if (!newWorker) {
                return;
            }

            newWorker.addEventListener('statechange', () => {
                if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
                    beginAutomaticUpdate(newWorker);
                }
            });
        });
    } catch (error) {
        console.warn('PWA service worker registration failed.', error);
    }
};

const installApp = async () => {
    const installButton = document.querySelector('[data-pwa-install-action]');
    const installMode = installButton?.dataset.installMode ?? 'install';

    if (installMode !== 'install') {
        setInstallDismissed(true);
        toggleInstallCard(false);
        return;
    }

    if (!window.RescueInstall?.available) {
        void window.RescueInstall?.prepare();
        setInstallCardContent();
        toggleInstallCard(true);
        return;
    }

    const outcome = await window.RescueInstall.request();

    if (outcome === 'unavailable' || outcome === 'error' || outcome === 'dismissed') {
        setInstallCardContent();
        toggleInstallCard(true);
        return;
    }

    if (outcome === 'accepted') {
        setInstallDismissed(false);
    }

    toggleInstallCard(false);
    updateDisplayMode();
};

onDomReady( () => {
    updateDisplayMode();
    updateNetworkStatus();
    setInstallCardContent();
    setUpdateCardContent();
    void captureCurrentAppVersion().then(() => registerServiceWorker());
    scheduleVersionPolling();
    window.setTimeout(() => {
        void checkForApplicationUpdates();
    }, 5000);

    const installButton = document.querySelector('[data-pwa-install-action]');
    const dismissButton = document.querySelector('[data-pwa-dismiss-action]');
    const installToggle = document.querySelector('[data-pwa-install-toggle]');
    const download = document.querySelector('[data-pwa-android-download]');
    if (download) download.hidden = !/Android/i.test(navigator.userAgent);
    installToggle?.addEventListener('click', () => {
        setInstallCardContent();
        toggleInstallCard(true);
        dismissButton?.focus({ preventScroll: true });
    });
    const syncInstallOffset = () => {
        const nav = document.querySelector('.mobile-quick-nav');
        const bounds = nav?.getBoundingClientRect();
        const offset = bounds?.height ? Math.max(16, window.innerHeight - bounds.top + 12) : 16;
        document.body.style.setProperty('--pwa-install-offset', `${Math.ceil(offset)}px`);
    };
    syncInstallOffset();
    window.addEventListener('resize', syncInstallOffset);
    window.visualViewport?.addEventListener('resize', syncInstallOffset);
    const nav = document.querySelector('.mobile-quick-nav');
    if (nav && 'ResizeObserver' in window) new ResizeObserver(syncInstallOffset).observe(nav);
    document.querySelector('[data-pwa-install-card]')?.addEventListener('keydown', event => {
        if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); setInstallDismissed(true); toggleInstallCard(false); }
    });

    installButton?.addEventListener('click', () => {
        void installApp();
    });

    dismissButton?.addEventListener('click', () => {
        setInstallDismissed(true);
        toggleInstallCard(false);
    });

    if (shouldShowInstallCard() && (window.RescueInstall?.available || (isIOSDevice() && isSafariBrowser()) || !supportsServiceWorker())) {
        toggleInstallCard(true);
    }
    updateInstallIndicator();
});

window.addEventListener('rescue:install-ready', () => {
    setInstallCardContent();
    const card = document.querySelector('[data-pwa-install-card]');
    toggleInstallCard(card?.hidden === false || shouldShowInstallCard());
});

window.addEventListener('appinstalled', () => {
    installedThisVisit = true;
    toggleInstallCard(false);
    updateDisplayMode();
});

navigator.serviceWorker?.addEventListener('controllerchange', () => {
    if (!refreshingForUpdate) {
        return;
    }

    clearPendingReload();
    refreshingForUpdate = false;
    void reloadWithFreshAssets();
});

navigator.serviceWorker?.addEventListener('message', (event) => {
    if (event.data?.type === 'APP_UPDATED_ACTIVE' && !refreshingForUpdate) {
        toggleUpdateCard(false);
    }
});

document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') {
        void checkForApplicationUpdates();
    }
});

window.addEventListener('pageshow', (event) => {
    if (event.persisted) {
        void checkForApplicationUpdates();
    }
});

window.addEventListener('focus', () => {
    void checkForApplicationUpdates();
});

window.addEventListener('online', () => {
    updateNetworkStatus();
    void checkForApplicationUpdates();
});

window.addEventListener('offline', () => {
    updateNetworkStatus();
});
