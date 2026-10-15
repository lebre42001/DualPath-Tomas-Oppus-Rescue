import { onDomReady } from './dom-ready';
onDomReady( () => {
    const root = document.querySelector('[data-account-verification-root]');

    if (!root) {
        return;
    }

    const form = root.querySelector('[data-account-verification-form]');
    const input = root.querySelector('[data-account-verification-input]');
    const preview = root.querySelector('[data-account-verification-preview]');
    const canvas = root.querySelector('[data-account-verification-canvas]');
    const image = root.querySelector('[data-account-verification-image]');
    const status = root.querySelector('[data-account-verification-status]');
    const startButton = root.querySelector('[data-account-verification-start]');
    const captureButton = root.querySelector('[data-account-verification-capture]');
    const fallbackButton = root.querySelector('[data-account-verification-fallback]');
    const submitButton = root.querySelector('[data-account-verification-submit]');

    let stream = null;
    let previewUrl = null;

    const faceDetectorClass = window.FaceDetector ?? null;
    const faceDetector = faceDetectorClass
        ? new faceDetectorClass({ fastMode: true, maxDetectedFaces: 1 })
        : null;

    const setStatus = (message) => {
        if (status) {
            status.textContent = message;
        }
    };

    const stopCamera = () => {
        if (!stream) {
            return;
        }

        stream.getTracks().forEach((track) => track.stop());
        stream = null;
    };

    const setSelectedFile = (file) => {
        if (!(input instanceof HTMLInputElement)) {
            return;
        }

        const transfer = new DataTransfer();
        transfer.items.add(file);
        input.files = transfer.files;

        if (previewUrl) {
            URL.revokeObjectURL(previewUrl);
        }

        previewUrl = URL.createObjectURL(file);

        if (image instanceof HTMLImageElement) {
            image.src = previewUrl;
            image.hidden = false;
        }

        if (preview instanceof HTMLVideoElement) {
            preview.hidden = true;
        }

        if (submitButton instanceof HTMLButtonElement) {
            submitButton.disabled = false;
        }

        setStatus('Face captured. Tap Verify Account to unlock emergency reporting.');
    };

    const getTargetAspectRatio = () => {
        if (window.matchMedia('(max-width: 700px)').matches) {
            return 3 / 4;
        }

        return 4 / 5;
    };

    const drawPortraitCrop = () => {
        if (!(preview instanceof HTMLVideoElement) || !(canvas instanceof HTMLCanvasElement)) {
            return false;
        }

        const videoWidth = preview.videoWidth || 720;
        const videoHeight = preview.videoHeight || 960;
        const targetAspectRatio = getTargetAspectRatio();
        const sourceAspectRatio = videoWidth / videoHeight;

        let sourceX = 0;
        let sourceY = 0;
        let sourceWidth = videoWidth;
        let sourceHeight = videoHeight;

        if (sourceAspectRatio > targetAspectRatio) {
            sourceWidth = videoHeight * targetAspectRatio;
            sourceX = (videoWidth - sourceWidth) / 2;
        } else {
            sourceHeight = videoWidth / targetAspectRatio;
            sourceY = Math.max(0, (videoHeight - sourceHeight) * 0.28);
        }

        canvas.width = 720;
        canvas.height = Math.round(canvas.width / targetAspectRatio);
        canvas.getContext('2d')?.drawImage(
            preview,
            sourceX,
            sourceY,
            sourceWidth,
            sourceHeight,
            0,
            0,
            canvas.width,
            canvas.height,
        );

        return true;
    };

    const canDetectFace = async () => {
        if (!faceDetector || !(canvas instanceof HTMLCanvasElement)) {
            return true;
        }

        try {
            const faces = await faceDetector.detect(canvas);

            if (!faces.length) {
                setStatus('No face detected yet. Center your face in the frame, use good lighting, then capture again.');
                return false;
            }

            setStatus('Face detected. Preparing your verification selfie...');

            return true;
        } catch {
            setStatus('Live face check is not available in this browser, so a front-camera selfie will be used instead.');

            return true;
        }
    };

    const openCamera = async () => {
        if (!navigator.mediaDevices?.getUserMedia) {
            setStatus('This browser cannot open the camera directly. Use Phone Camera instead.');
            return;
        }

        try {
            stopCamera();
            stream = await navigator.mediaDevices.getUserMedia({
                video: {
                    facingMode: 'user',
                    width: { ideal: 720 },
                    height: { ideal: 960 },
                    aspectRatio: { ideal: 0.75 },
                },
                audio: false,
            });

            if (preview instanceof HTMLVideoElement) {
                preview.srcObject = stream;
                preview.hidden = false;
                await preview.play();
            }

            if (captureButton instanceof HTMLButtonElement) {
                captureButton.disabled = false;
            }

            setStatus('Front camera is ready. Center your face, then tap Capture Face.');
        } catch {
            setStatus('Camera permission was denied or unavailable. Use Phone Camera or allow camera access in browser settings.');
        }
    };

    const captureFace = async () => {
        if (!(preview instanceof HTMLVideoElement) || !(canvas instanceof HTMLCanvasElement)) {
            return;
        }

        if (!drawPortraitCrop()) {
            setStatus('Could not prepare the face image. Try opening the camera again.');
            return;
        }

        if (!await canDetectFace()) {
            return;
        }

        canvas.toBlob((blob) => {
            if (!blob) {
                setStatus('Could not capture the face image. Try again.');
                return;
            }

            setSelectedFile(new File([blob], `account-face-${Date.now()}.jpg`, { type: 'image/jpeg' }));
            stopCamera();
        }, 'image/jpeg', 0.92);
    };

    startButton?.addEventListener('click', () => {
        void openCamera();
    });

    captureButton?.addEventListener('click', () => {
        void captureFace();
    });

    fallbackButton?.addEventListener('click', () => {
        input?.click();
    });

    input?.addEventListener('change', () => {
        const file = input.files?.[0] ?? null;

        if (file instanceof File) {
            setSelectedFile(file);
            stopCamera();
        }
    });

    form?.addEventListener('submit', (event) => {
        if (!(input instanceof HTMLInputElement) || !input.files?.[0]) {
            event.preventDefault();
            setStatus('Capture or choose a front-camera selfie before verifying your account.');
        }
    });
});
