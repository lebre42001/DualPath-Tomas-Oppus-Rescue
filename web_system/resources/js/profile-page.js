function initializeProfilePage() {
    const root = document.querySelector('[data-profile-editor-modal]');
    if (!root) return;

    const input = document.querySelector('[data-profile-photo-input]');
    const cameraInput = document.querySelector('[data-profile-photo-camera-input]');
    const cameraTrigger = document.querySelector('[data-profile-photo-camera-trigger]');
    const previewImage = document.querySelector('[data-profile-photo-preview-image]');
    const previewPlaceholder = document.querySelector('[data-profile-photo-preview-placeholder]');
    const fileName = document.querySelector('[data-profile-photo-file-name]');
    const cropStatus = document.querySelector('[data-profile-photo-crop-status]');
    const resetButton = document.querySelector('[data-profile-photo-reset]');
    const removeCheckbox = document.querySelector('[data-profile-photo-remove]');
    const removeTrigger = document.querySelector('[data-profile-photo-remove-trigger]');
    const passwordToggle = document.querySelector('[data-password-toggle]');
    const passwordFields = Array.from(document.querySelectorAll('[data-password-field]'));
    const profileEditorModal = document.querySelector('[data-profile-editor-modal]');
    const profileEditorPanel = document.querySelector('[data-profile-editor-panel]');
    const profileEditorToggles = Array.from(document.querySelectorAll('[data-profile-editor-toggle]'));
    const profileEditorClose = document.querySelector('[data-profile-editor-close]');
    let lastProfileEditorTrigger = null;

    const clearProfileHash = () => {
        if (window.location.hash === '#profile-form') {
            history.replaceState(null, '', `${window.location.pathname}${window.location.search}`);
        }
    };

    const openProfileEditor = (trigger = null) => {
        if (!profileEditorModal || !profileEditorPanel) {
            return;
        }

        lastProfileEditorTrigger = trigger;
        profileEditorModal.hidden = false;
        document.body.style.overflow = 'hidden';
        clearProfileHash();

        window.setTimeout(() => {
            profileEditorPanel.querySelector('input, select, textarea, button')?.focus();
        }, 60);
    };

    const closeProfileEditor = () => {
        if (!profileEditorModal) {
            return;
        }

        profileEditorModal.hidden = true;
        document.body.style.overflow = '';
        clearProfileHash();

        lastProfileEditorTrigger?.focus?.();
    };

    profileEditorToggles.forEach((toggle) => {
        toggle.addEventListener('click', (event) => {
            event.preventDefault();

            openProfileEditor(toggle);
        });
    });

    profileEditorClose?.addEventListener('click', () => {
        closeProfileEditor();
    });

    profileEditorModal?.addEventListener('click', (event) => {
        if (event.target === profileEditorModal) {
            closeProfileEditor();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (!profileEditorModal || profileEditorModal.hidden) return;

        if (event.key === 'Escape') {
            closeProfileEditor();
        }

        if (event.key === 'Tab') {
            const controls = Array.from(profileEditorPanel.querySelectorAll('a[href], button, input, select, textarea, [tabindex="0"]'))
                .filter((control) => !control.disabled && control.getClientRects().length > 0);
            const first = controls[0];
            const last = controls[controls.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last?.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first?.focus();
            }
        }
    });

    if (window.location.hash === '#profile-form' || (profileEditorModal && !profileEditorModal.hidden)) {
        openProfileEditor();
    }

    const autoCropSize = 512;
    const initialSrc = previewImage?.getAttribute('src') || '';
    const initialHasImage = initialSrc !== '';
    const initialLabel = fileName?.textContent || 'No photo selected yet';
    const initialCropStatus = cropStatus?.textContent || 'Auto-crop target: 512 x 512 pixels.';
    let activeObjectUrl = null;

    const updateCropStatus = (message) => {
        if (cropStatus) {
            cropStatus.textContent = message;
        }
    };

    const revokeActiveObjectUrl = () => {
        if (activeObjectUrl) {
            URL.revokeObjectURL(activeObjectUrl);
            activeObjectUrl = null;
        }
    };

    const renderProfilePhotoState = ({ showInitial = false, message = null } = {}) => {
        if (!previewImage || !previewPlaceholder || !fileName) {
            return;
        }

        revokeActiveObjectUrl();

        if (showInitial && initialHasImage && !(removeCheckbox?.checked)) {
            previewImage.src = initialSrc;
            previewImage.hidden = false;
            previewPlaceholder.hidden = true;
            fileName.textContent = initialLabel;
            updateCropStatus(initialCropStatus);
            return;
        }

        previewImage.hidden = true;
        previewImage.removeAttribute('src');
        previewPlaceholder.hidden = false;
        fileName.textContent = message ?? 'No photo selected yet';
        updateCropStatus(message ? 'Removal is queued until you save.' : 'Auto-crop target: 512 x 512 pixels.');
    };

    if (!input || !previewImage || !previewPlaceholder || !fileName) {
        return;
    }

    const readImageFile = (file) => new Promise((resolve, reject) => {
        const image = new Image();
        const objectUrl = URL.createObjectURL(file);

        image.onload = () => {
            URL.revokeObjectURL(objectUrl);
            resolve(image);
        };

        image.onerror = () => {
            URL.revokeObjectURL(objectUrl);
            reject(new Error('Unable to read image file.'));
        };

        image.src = objectUrl;
    });

    const createAutoCroppedFile = async (file) => {
        const image = await readImageFile(file);
        const sourceWidth = image.naturalWidth || image.width;
        const sourceHeight = image.naturalHeight || image.height;
        const sourceSize = Math.min(sourceWidth, sourceHeight);
        const offsetX = Math.max(0, (sourceWidth - sourceSize) / 2);
        const offsetY = Math.max(0, (sourceHeight - sourceSize) / 2);
        const canvas = document.createElement('canvas');
        const context = canvas.getContext('2d');

        if (!context) {
            throw new Error('Canvas is not available.');
        }

        canvas.width = autoCropSize;
        canvas.height = autoCropSize;
        context.drawImage(image, offsetX, offsetY, sourceSize, sourceSize, 0, 0, autoCropSize, autoCropSize);

        const mimeType = ['image/png', 'image/webp'].includes(file.type) ? file.type : 'image/jpeg';
        const extension = mimeType === 'image/png' ? 'png' : (mimeType === 'image/webp' ? 'webp' : 'jpg');
        const baseName = file.name.replace(/\.(jpe?g|png|webp)$/i, '');

        return new Promise((resolve, reject) => {
            canvas.toBlob((blob) => {
                if (!blob) {
                    reject(new Error('Unable to create cropped image.'));
                    return;
                }

                resolve(new File([blob], `${baseName}-square.${extension}`, { type: mimeType }));
            }, mimeType, 0.92);
        });
    };

    const applyFileToInput = (file) => {
        try {
            if (typeof DataTransfer === 'undefined') return false;
            const dataTransfer = new DataTransfer();
            dataTransfer.items.add(file);
            input.files = dataTransfer.files;
            return input.files.length === 1;
        } catch (error) {
            return false;
        }
    };

    // Native capture remains submittable when older browsers cannot replace a FileList.
    const selectPhotoInput = (sourceInput) => {
        input.removeAttribute('name');
        cameraInput?.removeAttribute('name');
        sourceInput.name = 'profile_photo';
    };

    const previewOriginalPhoto = (file, sourceInput) => {
        selectPhotoInput(sourceInput);
        revokeActiveObjectUrl();
        activeObjectUrl = URL.createObjectURL(file);
        previewImage.src = activeObjectUrl;
        previewImage.hidden = false;
        previewPlaceholder.hidden = true;
        fileName.textContent = file.name;
        updateCropStatus('Original photo selected. You can save it without auto-crop.');
    };

    const preparePhoto = async (file, sourceLabel, sourceInput = input) => {
        if (!file) {
            renderProfilePhotoState({ showInitial: true });
            return;
        }

        if (removeCheckbox) {
            removeCheckbox.checked = false;
        }

        const croppedFile = await createAutoCroppedFile(file);
        if (!applyFileToInput(croppedFile)) {
            previewOriginalPhoto(file, sourceInput);
            return;
        }
        selectPhotoInput(input);
        revokeActiveObjectUrl();
        activeObjectUrl = URL.createObjectURL(croppedFile);
        previewImage.src = activeObjectUrl;
        previewImage.hidden = false;
        previewPlaceholder.hidden = true;
        fileName.textContent = croppedFile.name;
        updateCropStatus(`${sourceLabel} ready: auto-cropped and resized to 512 x 512.`);
    };

    input.addEventListener('change', async () => {
        const [file] = input.files || [];

        if (!file) {
            renderProfilePhotoState({ showInitial: true });
            return;
        }

        try {
            await preparePhoto(file, 'Upload');
        } catch (error) {
            previewOriginalPhoto(file, input);
        }
    });

    cameraTrigger?.addEventListener('click', () => {
        cameraInput?.click();
    });

    cameraInput?.addEventListener('change', async () => {
        const [file] = cameraInput.files || [];

        if (!file) {
            return;
        }

        try {
            await preparePhoto(file, 'Camera capture', cameraInput);
        } catch (error) {
            previewOriginalPhoto(file, cameraInput);
        }
    });

    resetButton?.addEventListener('click', () => {
        selectPhotoInput(input);
        input.value = '';
        if (cameraInput) {
            cameraInput.value = '';
        }

        if (removeCheckbox?.checked) {
            renderProfilePhotoState({ message: 'Current photo will be removed after save' });
            return;
        }

        renderProfilePhotoState({ showInitial: true });
    });

    removeTrigger?.addEventListener('click', () => {
        selectPhotoInput(input);
        input.value = '';
        if (cameraInput) {
            cameraInput.value = '';
        }

        if (removeCheckbox) {
            removeCheckbox.checked = true;
        }

        renderProfilePhotoState({ message: 'Current photo will be removed after save' });
    });

    removeCheckbox?.addEventListener('change', () => {
        if (removeCheckbox.checked) {
            selectPhotoInput(input);
            input.value = '';
            if (cameraInput) {
                cameraInput.value = '';
            }
            renderProfilePhotoState({ message: 'Current photo will be removed after save' });
            return;
        }

        renderProfilePhotoState({ showInitial: true });
    });

    passwordToggle?.addEventListener('click', () => {
        const shouldShow = passwordFields.some((field) => field.type === 'password');

        passwordFields.forEach((field) => {
            field.type = shouldShow ? 'text' : 'password';
        });

        passwordToggle.textContent = shouldShow ? 'Hide passwords' : 'Show passwords';
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeProfilePage, { once: true });
} else {
    initializeProfilePage();
}
