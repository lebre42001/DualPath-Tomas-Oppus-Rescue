import { onDomReady } from './dom-ready';
const permissionStateClasses = {
    ready: 'tag green',
    info: 'tag blue',
    attention: 'tag amber',
    blocked: 'tag red',
    idle: 'tag neutral',
    allowed: 'tag blue',
    checking: 'tag blue',
};

const permissionStateLabels = {
    ready: 'Ready',
    info: 'Check Info',
    attention: 'Needs Attention',
    blocked: 'Blocked',
    idle: 'Not checked',
    allowed: 'Allowed',
    checking: 'Checking',
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
    const photoInput = root.querySelector('[data-readiness-photo-input]');
    const cameraCard = root.querySelector('details[data-readiness-card="camera"]');
    const isCivilian = root.dataset.readinessRole === 'civilian';

    const states = {
        camera: 'idle',
        location: 'idle',
        notifications: 'idle',
        network: 'idle',
        app: 'idle',
    };

    let cameraStream = null;
    let cameraRequestId = 0;
    let locationRequestId = 0;
    let cameraCheckPending = false;
    let locationCheckPending = false;
    let pagePaused = false;
    let selectedPhotoName = '';
    const permissionWatchers = new Map();
    const pageIsActive = () => !pagePaused && !document.hidden;

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
        if (isCivilian) {
            const core = ['camera', 'location', 'network'];
            const ready = core.filter((key) => states[key] === 'ready').length;
            if (score) score.textContent = `${ready} / ${core.length}`;
            if (!summary) return;
            summary.textContent = states.camera === 'blocked'
                ? 'Allow camera access for scene photos and verification selfies.'
                : states.location === 'blocked'
                    ? 'Allow location in browser settings and turn on device location. A report needs both GPS and a barangay, road, or landmark.'
                    : states.network !== 'ready'
                        ? 'Check your connection before sending a report.'
                        : ready === core.length
                            ? 'Camera, location, and connection checks passed. Notifications and installation are optional.'
                            : 'Use Allow camera and Allow location to check this device. Notifications and installation are optional.';
            return;
        }
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
        cameraStream?.getTracks().forEach((track) => track.stop());
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

    const invalidateCameraCheck = () => {
        cameraRequestId += 1;
        stopCameraStream();
        if (cameraCheckPending) {
            setCardState('camera', 'attention', isCivilian
                ? 'Camera check paused. Tap Allow camera to try again.'
                : 'Camera check paused. Tap Test camera to try again.');
        } else if (!isCivilian && states.camera === 'ready') {
            setCardState('camera', 'attention', 'Camera preview is closed. Test the camera again when needed.');
        }
        cameraCheckPending = false;
    };

    const invalidateLocationCheck = () => {
        locationRequestId += 1;
        locationCheckPending = false;
        if (locationResult) locationResult.textContent = 'A new location check is needed to confirm current GPS.';
        if (['ready', 'checking'].includes(states.location)) {
            setCardState('location', 'attention', 'Location needs a fresh check. Keep device location on and try again.');
        }
    };

    const queryPermissionState = async (name) => {
        if (!supportsPermissionsApi()) {
            return null;
        }

        try {
            const result = await navigator.permissions.query({ name });
            const previous = permissionWatchers.get(name);
            if (previous?.status !== result && result.addEventListener) {
                if (previous) previous.status.removeEventListener?.('change', previous.listener);
                const listener = () => {
                    if (name === 'camera') {
                        if (cameraCheckPending && result.state === 'granted') return;
                        invalidateCameraCheck();
                        if (pageIsActive()) void refreshCameraState();
                    } else if (name === 'geolocation') {
                        if (locationCheckPending && result.state === 'granted') return;
                        invalidateLocationCheck();
                        if (pageIsActive()) void refreshLocationState();
                    }
                };
                result.addEventListener('change', listener);
                permissionWatchers.set(name, { status: result, listener });
            }
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
        const requestId = locationRequestId;
        if (!('geolocation' in navigator)) {
            setCardState('location', 'attention', 'This browser does not provide geolocation support.');
            return;
        }

        if (!isSecurePageContext()) {
            setCardState('location', 'blocked', 'GPS needs localhost or HTTPS. The current page context is not secure.');
            return;
        }

        const permissionState = await queryPermissionState('geolocation');
        if (requestId !== locationRequestId || !pageIsActive() || locationCheckPending) return;

        if (permissionState === 'granted') {
            if (locationResult) locationResult.textContent = 'Permission is allowed; device GPS still needs a current location check.';
            setCardState('location', 'allowed', isCivilian
                ? 'Location permission is allowed. Tap Allow location / test GPS to check device location now. The report form checks automatically when permission is already allowed.'
                : 'Location permission is allowed. Tap Test location to confirm GPS can return current coordinates.');
            return;
        }

        if (permissionState === 'denied') {
            setCardState('location', 'blocked', 'GPS permission is denied. Re-enable it from the browser site settings.');
            return;
        }

        setCardState('location', 'attention', permissionState === null
            ? 'This browser does not expose location permission status. Use the location button to check access. Keep device location on.'
            : 'Allow location when asked. Keep device location on; the app cannot switch on device GPS.');
    };

    const refreshCameraState = async () => {
        const requestId = cameraRequestId;
        if (!navigator.mediaDevices?.getUserMedia) {
            setCardState('camera', 'attention', 'This browser does not support direct camera capture from the web page.');
            return;
        }

        if (!isSecurePageContext()) {
            setCardState('camera', 'blocked', 'Camera access needs localhost or HTTPS. The current page context is not secure.');
            return;
        }

        const permissionState = await queryPermissionState('camera');
        if (requestId !== cameraRequestId || !pageIsActive() || cameraCheckPending) return;

        if (permissionState === 'granted') {
            if (states.camera !== 'ready') setCardState('camera', 'allowed', isCivilian
                ? 'Camera permission is allowed. Use Allow camera to test it; the camera is opened only when needed for capture or selfie verification.'
                : 'Camera permission is allowed. Tap Test camera to confirm a camera can open.');
            return;
        }

        if (permissionState === 'denied') {
            setCardState('camera', 'blocked', 'Camera permission is denied. Re-enable it from the browser site settings.');
            return;
        }

        setCardState('camera', 'attention', permissionState === null
            ? 'This browser does not expose camera permission status. Use the camera button to check access.'
            : 'Allow camera when asked to use scene capture and the live verification selfie.');
    };

    const testCamera = async () => {
        if (cameraCheckPending || !pageIsActive()) return;
        if (!navigator.mediaDevices?.getUserMedia) {
            setCardState('camera', 'attention', 'This browser does not support direct camera capture from the web page.');
            return;
        }

        if (!isSecurePageContext()) {
            setCardState('camera', 'blocked', 'Camera access needs localhost or HTTPS. The current page context is not secure.');
            return;
        }

        stopCameraStream();
        const requestId = ++cameraRequestId;
        cameraCheckPending = true;
        setCardState('camera', 'checking', 'Opening the camera...');

        try {
            const permissionState = await queryPermissionState('camera');
            if (requestId !== cameraRequestId || !pageIsActive() || (isCivilian && cameraCard && !cameraCard.open)) {
                if (requestId === cameraRequestId) invalidateCameraCheck();
                return;
            }
            if (permissionState === 'denied') {
                setCardState('camera', 'blocked', 'Camera access is blocked. Allow it in browser site settings or open the app on your phone.');
                return;
            }
            const stream = await navigator.mediaDevices.getUserMedia({
                video: {
                    facingMode: 'environment',
                },
                audio: false,
            });
            if (requestId !== cameraRequestId || !pageIsActive() || (isCivilian && cameraCard && !cameraCard.open)) {
                stream.getTracks().forEach((track) => track.stop());
                if (requestId === cameraRequestId) invalidateCameraCheck();
                return;
            }
            if (isCivilian) {
                stream.getTracks().forEach((track) => track.stop());
                setCardState('camera', 'ready', 'Camera access worked. The test camera is now closed. Use scene capture or the verification selfie when you are ready to report.');
                return;
            }
            cameraStream = stream;

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

            setCardState('camera', 'ready', 'Camera opened successfully. Stop the preview when finished. Reporting also requests a verification selfie.');
        } catch (error) {
            if (requestId !== cameraRequestId || !pageIsActive()) return;
            const denied = ['NotAllowedError', 'SecurityError'].includes(error.name);
            const message = denied
                ? 'Camera access is blocked. Allow it in browser site settings or open the app on your phone.'
                : error.name === 'NotFoundError'
                    ? 'No camera was found. Connect a camera or test on your phone.'
                    : 'The camera could not open. Close other camera apps and try again.';
            setCardState('camera', denied ? 'blocked' : 'attention', message);
        } finally {
            if (requestId === cameraRequestId) cameraCheckPending = false;
        }
    };

    const testLocation = () => {
        if (locationCheckPending || !pageIsActive()) return;
        if (!('geolocation' in navigator)) {
            setCardState('location', 'attention', 'This browser does not provide geolocation support.');
            return;
        }
        if (!isSecurePageContext()) {
            setCardState('location', 'blocked', 'GPS needs localhost or HTTPS. The current page context is not secure.');
            return;
        }
        const requestId = ++locationRequestId;
        locationCheckPending = true;

        if (locationResult) {
            locationResult.textContent = 'Getting current coordinates from the browser...';
        }
        setCardState('location', 'checking', 'Getting your location...');

        try {
            navigator.geolocation.getCurrentPosition(
            (position) => {
                if (requestId !== locationRequestId || !pageIsActive()) return;
                locationCheckPending = false;
                const { latitude: rawLatitude, longitude: rawLongitude, accuracy } = position?.coords ?? {};
                const timestamp = position?.timestamp;
                const now = Date.now();
                if (!Number.isFinite(rawLatitude) || rawLatitude < -90 || rawLatitude > 90
                    || !Number.isFinite(rawLongitude) || rawLongitude < -180 || rawLongitude > 180
                    || !Number.isFinite(accuracy) || accuracy < 0
                    || !Number.isFinite(timestamp) || now - timestamp > 30000 || timestamp - now > 30000) {
                    if (locationResult) locationResult.textContent = 'The device did not return a valid current location. Keep device location on and try again.';
                    setCardState('location', 'attention', 'Current GPS could not be confirmed. Try the location check again.');
                    return;
                }
                const latitude = rawLatitude.toFixed(6);
                const longitude = rawLongitude.toFixed(6);

                if (locationResult) {
                    locationResult.textContent = `Latest coordinates: ${latitude}, ${longitude}`;
                }

                setCardState('location', 'ready', `Location test succeeded. Browser GPS returned ${latitude}, ${longitude}.`);
            },
            (error) => {
                if (requestId !== locationRequestId || !pageIsActive()) return;
                locationCheckPending = false;
                const messageMap = {
                    1: 'Location permission was denied. Allow access in browser site settings.',
                    2: 'Location is unavailable. Turn on device location and try again; the app cannot switch on GPS.',
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
        } catch {
            if (requestId !== locationRequestId || !pageIsActive()) return;
            locationCheckPending = false;
            setCardState('location', 'attention', 'The location check could not start. Check browser access and device location, then try again.');
        }
    };

    const requestNotifications = async () => {
        if (!('Notification' in window)) {
            setCardState('notifications', 'attention', 'This browser does not support notifications.');
            return;
        }

        let permission;
        try {
            permission = await Notification.requestPermission();
        } catch {
            setCardState('notifications', 'attention', 'This browser could not request notification permission. Check site settings; notifications are optional.');
            return;
        }

        if (permission === 'granted') {
            setCardState('notifications', 'ready', 'Notification permission is granted for this browser.');
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
        if (isCivilian && !selectedPhotoName) {
            setCardState('files', 'idle', 'Choose a photo to test your device picker. Only a photo you select is accessible; no access to all files is requested.');
        }
    };

    photoInput?.addEventListener('change', () => {
        const file = photoInput.files?.[0];
        // Some browsers dispatch an empty change when the picker is cancelled.
        if (!file) return;
        const supportedType = ['image/jpeg', 'image/png', 'image/webp'].includes(file.type)
            || (!file.type && /\.(jpe?g|png|webp)$/i.test(file.name));
        if (!supportedType || file.size === 0) {
            setCardState('files', 'attention', 'Choose a JPG, PNG, or WebP photo that is not empty. This check does not upload files.');
            return;
        }
        selectedPhotoName = file.name;
        setCardState('files', 'ready', `Photo selected: ${selectedPhotoName}. Picker access worked. This test photo is not uploaded or saved.`);
    });

    root.addEventListener('click', (event) => {
        const actionButton = event.target.closest('[data-readiness-action]');

        if (actionButton) {
            const action = actionButton.getAttribute('data-readiness-action');

            if (action === 'camera') {
                void testCamera();
            } else if (action === 'location') {
                testLocation();
            } else if (action === 'files') {
                // Keep the native picker directly in the user's click gesture.
                photoInput?.click();
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
            invalidateCameraCheck();
            void refreshCameraState();
        }
    });

    refreshButton?.addEventListener('click', () => {
        invalidateCameraCheck();
        invalidateLocationCheck();
        void refreshAll();
    });

    const suspendChecks = () => {
        pagePaused = true;
        invalidateCameraCheck();
        invalidateLocationCheck();
    };
    const resumeChecks = () => {
        pagePaused = false;
        invalidateLocationCheck();
        void refreshAll();
    };

    window.addEventListener('online', refreshNetworkState);
    window.addEventListener('offline', refreshNetworkState);
    window.addEventListener('beforeunload', suspendChecks);
    window.addEventListener('pagehide', suspendChecks);
    window.addEventListener('pageshow', resumeChecks);
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) suspendChecks();
        else resumeChecks();
    });
    root.querySelector('details[data-readiness-card="camera"]')?.addEventListener('toggle', (event) => {
        if (!event.target.open) invalidateCameraCheck();
    });

    void refreshAll();
});
