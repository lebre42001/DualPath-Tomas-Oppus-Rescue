import { onDomReady } from './dom-ready';
const permissionStateClasses = {
    ready: 'tag green',
    info: 'tag blue',
    attention: 'tag amber',
    blocked: 'tag red',
    idle: 'tag neutral',
};

const permissionStateLabels = {
    ready: 'Ready',
    info: 'Check Info',
    attention: 'Needs Attention',
    blocked: 'Blocked',
    idle: 'Not checked',
};

const supportsPermissionsApi = () => 'permissions' in navigator && typeof navigator.permissions.query === 'function';

const inStandaloneMode = () =>
    window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;

const isSecurePageContext = () => window.isSecureContext || ['localhost', '127.0.0.1'].includes(window.location.hostname);

onDomReady( () => {
    const root = document.querySelector('[data-permission-readiness-root]');

    if (!root) {
        return;
    }

    const score = root.querySelector('[data-readiness-score]');
    const summary = root.querySelector('[data-readiness-summary]');
    const appModeValue = root.querySelector('[data-readiness-app-mode]');
    const appModeCopy = root.querySelector('[data-readiness-app-copy]');
    const networkValue = root.querySelector('[data-readiness-network-value]');
    const networkCopy = root.querySelector('[data-readiness-network-copy]');
    const locationResult = root.querySelector('[data-readiness-location-result]');
    const cameraPreview = root.querySelector('[data-readiness-camera-preview]');
    const stopCameraButton = root.querySelector('[data-readiness-stop-camera]');
    const refreshButton = root.querySelector('[data-readiness-refresh]');

    const states = {
        camera: 'idle',
        location: 'idle',
        notifications: 'idle',
        network: 'idle',
        app: 'idle',
    };

    let cameraStream = null;

    const setCardState = (key, state, copy) => {
        states[key] = state;

        const badge = root.querySelector(`[data-readiness-badge="${key}"]`);
        const copyNode = root.querySelector(`[data-readiness-copy="${key}"]`);

        if (badge) {
            badge.className = permissionStateClasses[state] ?? permissionStateClasses.idle;
            badge.textContent = permissionStateLabels[state] ?? permissionStateLabels.idle;
        }

        if (copyNode) {
            copyNode.textContent = copy;
        }

        updateSummary();
    };

    const updateSummary = () => {
        const stateValues = Object.values(states);
        const readyCount = stateValues.filter((value) => value === 'ready').length;
        const blockedCount = stateValues.filter((value) => value === 'blocked').length;
        const attentionCount = stateValues.filter((value) => value === 'attention').length;

        if (score) {
            score.textContent = `${readyCount} / ${stateValues.length}`;
        }

        if (!summary) {
            return;
        }

        if (blockedCount > 0) {
            summary.textContent = `${blockedCount} required checks are blocked. Fix those first before reporting live.`;
            return;
        }

        if (attentionCount > 0) {
            summary.textContent = `${attentionCount} checks still need attention before the device is fully ready.`;
            return;
        }

        if (readyCount === stateValues.length) {
            summary.textContent = 'This device is ready for reporting, live GPS, notifications, and installed-app use.';
            return;
        }

        summary.textContent = 'Run the checks to confirm device readiness before reporting.';
    };

    const stopCameraStream = () => {
        if (!cameraStream) {
            return;
        }

        cameraStream.getTracks().forEach((track) => track.stop());
        cameraStream = null;

        if (cameraPreview) {
            cameraPreview.pause();
            cameraPreview.srcObject = null;
            cameraPreview.hidden = true;
        }

        if (stopCameraButton) {
            stopCameraButton.hidden = true;
        }
    };

    const queryPermissionState = async (name) => {
        if (!supportsPermissionsApi()) {
            return null;
        }

        try {
            const result = await navigator.permissions.query({ name });
            return result.state ?? null;
        } catch {
            return null;
        }
    };

    const refreshAppState = async () => {
        const standalone = inStandaloneMode();
        const secureContext = isSecurePageContext();
        const hasServiceWorker = 'serviceWorker' in navigator;

        if (appModeValue) {
            appModeValue.textContent = standalone ? 'Installed' : 'Browser';
        }

        if (appModeCopy) {
            appModeCopy.textContent = standalone
                ? 'The emergency system is running in standalone mode on this device.'
                : 'The emergency system is still running in a browser tab.';
        }

        if (!secureContext) {
            setCardState('app', 'blocked', 'This page is not secure. Camera and GPS access will be limited until you use localhost or HTTPS.');
            return;
        }

        if (!hasServiceWorker) {
            setCardState('app', 'attention', 'This browser does not support service workers, so install and offline features are limited.');
            return;
        }

        setCardState('app', standalone ? 'ready' : 'info', standalone
            ? 'Installed app mode is active, and the page is secure.'
            : 'Browser mode is active, but the page is secure and install support is available on supported browsers.');
    };

    const refreshNetworkState = () => {
        const online = navigator.onLine;

        if (networkValue) {
            networkValue.textContent = online ? 'Online' : 'Offline';
        }

        if (networkCopy) {
            networkCopy.textContent = online
                ? 'The browser reports that this device is online right now.'
                : 'The browser reports that this device is offline. Use local drafts until connectivity returns.';
        }

        setCardState('network', online ? 'ready' : 'attention', online
            ? 'The browser currently reports an active network connection.'
            : 'The browser currently reports no active connection. Reporting should stay in local draft mode until signal returns.');
    };

    const refreshNotificationState = async () => {
        if (!('Notification' in window)) {
            setCardState('notifications', 'attention', 'This browser does not support notifications.');
            return;
        }

        const permission = Notification.permission;

        if (permission === 'granted') {
            setCardState('notifications', 'ready', 'Notification permission is already granted on this device.');
            return;
        }

        if (permission === 'denied') {
            setCardState('notifications', 'blocked', 'Notification permission is denied. Re-enable it from the browser site settings.');
            return;
        }

        setCardState('notifications', 'attention', 'Notification permission has not been granted yet.');
    };

    const refreshLocationState = async () => {
        if (!('geolocation' in navigator)) {
            setCardState('location', 'attention', 'This browser does not provide geolocation support.');
            return;
        }

        if (!isSecurePageContext()) {
            setCardState('location', 'blocked', 'GPS needs localhost or HTTPS. The current page context is not secure.');
            return;
        }

        const permissionState = await queryPermissionState('geolocation');

        if (permissionState === 'granted') {
            setCardState('location', 'ready', 'GPS permission is already granted and can be used for autofill.');
            return;
        }

        if (permissionState === 'denied') {
            setCardState('location', 'blocked', 'GPS permission is denied. Re-enable it from the browser site settings.');
            return;
        }

        setCardState('location', 'attention', 'GPS is supported, but permission still needs to be requested from this device.');
    };

    const refreshCameraState = async () => {
        if (!navigator.mediaDevices?.getUserMedia) {
            setCardState('camera', 'attention', 'This browser does not support direct camera capture from the web page.');
            return;
        }

        if (!isSecurePageContext()) {
            setCardState('camera', 'blocked', 'Camera access needs localhost or HTTPS. The current page context is not secure.');
            return;
        }

        const permissionState = await queryPermissionState('camera');

        if (permissionState === 'granted') {
            setCardState('camera', 'ready', 'Camera permission is already granted on this device.');
            return;
        }

        if (permissionState === 'denied') {
            setCardState('camera', 'blocked', 'Camera permission is denied. Re-enable it from the browser site settings.');
            return;
        }

        setCardState('camera', 'attention', 'Camera capture is supported, but permission still needs to be requested.');
    };

    const testCamera = async () => {
        if (!navigator.mediaDevices?.getUserMedia) {
            setCardState('camera', 'attention', 'This browser does not support direct camera capture from the web page.');
            return;
        }

        if (!isSecurePageContext()) {
            setCardState('camera', 'blocked', 'Camera access needs localhost or HTTPS. The current page context is not secure.');
            return;
        }

        stopCameraStream();

        try {
            cameraStream = await navigator.mediaDevices.getUserMedia({
                video: {
                    facingMode: 'environment',
                },
                audio: false,
            });

            if (cameraPreview) {
                cameraPreview.srcObject = cameraStream;
                cameraPreview.hidden = false;
                const playAttempt = cameraPreview.play();
                if (playAttempt?.catch) {
                    playAttempt.catch(() => {});
                }
            }

            if (stopCameraButton) {
                stopCameraButton.hidden = false;
            }

            setCardState('camera', 'ready', 'Camera stream opened successfully. Photo, video, and selfie capture should work on this device.');
        } catch (error) {
            setCardState('camera', 'blocked', 'Camera test failed. The browser denied access or the device camera is unavailable.');
        }
    };

    const testLocation = () => {
        if (!('geolocation' in navigator)) {
            setCardState('location', 'attention', 'This browser does not provide geolocation support.');
            return;
        }

        if (!isSecurePageContext()) {
            setCardState('location', 'blocked', 'GPS needs localhost or HTTPS. The current page context is not secure.');
            return;
        }

        if (locationResult) {
            locationResult.textContent = 'Getting current coordinates from the browser...';
        }

        navigator.geolocation.getCurrentPosition(
            (position) => {
                const latitude = position.coords.latitude.toFixed(6);
                const longitude = position.coords.longitude.toFixed(6);

                if (locationResult) {
                    locationResult.textContent = `Latest coordinates: ${latitude}, ${longitude}`;
                }

                setCardState('location', 'ready', `Location test succeeded. Browser GPS returned ${latitude}, ${longitude}.`);
            },
            (error) => {
                const messageMap = {
                    1: 'Location permission was denied.',
                    2: 'Location is unavailable on this device right now.',
                    3: 'The browser location request timed out.',
                };

                if (locationResult) {
                    locationResult.textContent = messageMap[error.code] ?? 'The browser could not return current coordinates.';
                }

                setCardState('location', error.code === 1 ? 'blocked' : 'attention', messageMap[error.code] ?? 'The browser could not return current coordinates.');
            },
            {
                enableHighAccuracy: true,
                timeout: 15000,
                maximumAge: 0,
            }
        );
    };

    const requestNotifications = async () => {
        if (!('Notification' in window)) {
            setCardState('notifications', 'attention', 'This browser does not support notifications.');
            return;
        }

        const permission = await Notification.requestPermission();

        if (permission === 'granted') {
            setCardState('notifications', 'ready', 'Notification permission is granted. Admin alerts can use browser notifications.');
            return;
        }

        if (permission === 'denied') {
            setCardState('notifications', 'blocked', 'Notification permission is denied. Re-enable it from the browser site settings.');
            return;
        }

        setCardState('notifications', 'attention', 'Notification permission is still undecided on this device.');
    };

    const refreshAll = async () => {
        await Promise.all([
            refreshCameraState(),
            refreshLocationState(),
            refreshNotificationState(),
        ]);
        refreshNetworkState();
        await refreshAppState();
    };

    root.addEventListener('click', (event) => {
        const actionButton = event.target.closest('[data-readiness-action]');

        if (actionButton) {
            const action = actionButton.getAttribute('data-readiness-action');

            if (action === 'camera') {
                void testCamera();
            } else if (action === 'location') {
                testLocation();
            } else if (action === 'notifications') {
                void requestNotifications();
            } else if (action === 'network') {
                refreshNetworkState();
            } else if (action === 'app') {
                void refreshAppState();
            }

            return;
        }

        if (event.target.closest('[data-readiness-stop-camera]')) {
            stopCameraStream();
            void refreshCameraState();
        }
    });

    refreshButton?.addEventListener('click', () => {
        void refreshAll();
    });

    window.addEventListener('online', refreshNetworkState);
    window.addEventListener('offline', refreshNetworkState);
    window.addEventListener('beforeunload', stopCameraStream);

    void refreshAll();
});
