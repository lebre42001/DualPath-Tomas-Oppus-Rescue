/* Built with the same compatibility targets as the application. */
function initializeShellDialogs() {
    const dialogStack = [];
    let previousOverflow = '';
    const focusableElements = (modal) => Array.from(modal.querySelectorAll('a[href], button, input, select, textarea, video[controls], [tabindex]:not([tabindex="-1"])'))
        .filter((element) => !element.disabled && element.getClientRects().length && getComputedStyle(element).visibility !== 'hidden');
    const activateDialog = (modal, close, opener = document.activeElement) => {
        if (dialogStack.some((entry) => entry.modal === modal)) return;
        if (!dialogStack.length) previousOverflow = document.body.style.overflow;
        dialogStack.push({ modal, close, opener });
        modal.hidden = false;
        document.body.style.overflow = 'hidden';
        const target = focusableElements(modal)[0] || modal;
        if (target === modal) modal.tabIndex = -1;
        target.focus();
    };
    const deactivateDialog = (modal) => {
        const index = dialogStack.findIndex((entry) => entry.modal === modal);
        if (index < 0) return;
        const entry = dialogStack.splice(index, 1)[0];
        modal.hidden = true;
        document.body.style.overflow = dialogStack.length ? 'hidden' : previousOverflow;
        if (entry.opener && entry.opener.isConnected && entry.opener.getClientRects().length) entry.opener.focus();
    };
    // A nested map or evidence viewer closes first; the report stays open.
    document.addEventListener('keydown', (event) => {
        const top = dialogStack[dialogStack.length - 1];
        if (!top) return;
        if (event.key === 'Escape') {
            event.preventDefault();
            event.stopImmediatePropagation();
            top.close();
        } else if (event.key === 'Tab') {
            const targets = focusableElements(top.modal);
            const first = targets[0], last = targets[targets.length - 1];
            if (!first) { event.preventDefault(); return; }
            if (!top.modal.contains(document.activeElement) || (event.shiftKey && document.activeElement === first)) {
                event.preventDefault();
                (event.shiftKey ? last : first).focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        }
    }, true);
    (() => {
        const modal = document.querySelector('[data-report-detail-modal]');
        if (!modal) {
            return;
        }

        const modalTitle = modal.querySelector('[data-report-detail-modal-title]');
        const modalBody = modal.querySelector('[data-report-detail-modal-body]');
        const closeButtons = modal.querySelectorAll('[data-report-detail-modal-close]');

        const closeModal = () => {
            deactivateDialog(modal);
            modalBody.innerHTML = '';
        };

        const resetClonedMaps = () => {
            modalBody.querySelectorAll('[data-incident-map]').forEach((map) => {
                map.removeAttribute('data-map-initialized');
                map.innerHTML = '';
                map.classList.remove('leaflet-container', 'leaflet-touch', 'leaflet-fade-anim', 'leaflet-grab', 'leaflet-touch-drag', 'leaflet-touch-zoom');
                map.removeAttribute('tabindex');
                map.removeAttribute('style');
            });
        };

        const openReportModal = (row, opener) => {
            const source = row?.querySelector('.incident-feed-expanded');
            if (!source) {
                return;
            }

            const title = row.getAttribute('data-report-title') || 'Incident details';
            const clone = source.cloneNode(true);

            modalTitle.textContent = title;
            modalBody.innerHTML = '';
            modalBody.appendChild(clone);
            resetClonedMaps();
            activateDialog(modal, closeModal, opener);

            window.setTimeout(() => {
                window.StitchRefreshMaps?.();
            }, 80);
        };

        document.addEventListener('click', (event) => {
            if (event.target.matches('[data-report-detail-modal-close]') || event.target === modal) {
                closeModal();
                return;
            }

            if (event.target.closest('[data-report-row-close]') && modal.contains(event.target)) {
                event.preventDefault();
                closeModal();
                return;
            }

            const trigger = event.target.closest('[data-report-modal-trigger], [data-report-review-label], .report-list-panel .incident-feed-row-summary');
            if (!trigger) {
                return;
            }

            const row = trigger.closest('[data-report-modal-row]');
            if (!row) {
                return;
            }

            event.preventDefault();
            openReportModal(row, trigger);
        });

        document.addEventListener('keydown', (event) => {
            const summary = event.target.closest?.('[data-report-modal-trigger], .report-list-panel .incident-feed-row-summary');
            if (!summary || !['Enter', ' '].includes(event.key)) {
                return;
            }

            const row = summary.closest('[data-report-modal-row]');
            event.preventDefault();
            openReportModal(row, summary);
        });

        closeButtons.forEach((button) => button.addEventListener('click', closeModal));

        const requestedReport = document.querySelector('[data-report-open-on-load]');
        if (requestedReport) {
            window.setTimeout(() => {
                openReportModal(requestedReport);
            }, 120);
        }
    })();

    (() => {
        const modal = document.querySelector('[data-report-map-modal]');
        if (!modal) {
            return;
        }

        const title = modal.querySelector('[data-report-map-modal-title]');
        const body = modal.querySelector('[data-report-map-modal-body]');
        const closeButtons = modal.querySelectorAll('[data-report-map-modal-close]');

        const closeMapModal = () => {
            deactivateDialog(modal);
            body.innerHTML = '';
        };

        const openMapModal = (trigger) => {
            const latitude = trigger.getAttribute('data-latitude');
            const longitude = trigger.getAttribute('data-longitude');

            if (!latitude || !longitude) {
                return;
            }

            title.textContent = trigger.getAttribute('data-map-title') || trigger.getAttribute('data-location-text') || 'Incident map location';
            body.innerHTML = '';

            const map = document.createElement('div');
            map.className = 'latest-inline-map report-map-modal-canvas';
            map.setAttribute('data-incident-map', '');
            map.setAttribute('data-map-scope', 'single');
            map.setAttribute('data-report-id', trigger.getAttribute('data-report-id') || '');
            map.setAttribute('data-latitude', latitude);
            map.setAttribute('data-longitude', longitude);
            map.setAttribute('data-location-text', trigger.getAttribute('data-location-text') || 'Civilian GPS location');
            map.setAttribute('data-points', trigger.getAttribute('data-points') || JSON.stringify([{
                id: `modal-map-${trigger.getAttribute('data-report-id') || 'report'}`,
                incident_type: 'Incident location',
                location_text: trigger.getAttribute('data-location-text') || 'Civilian GPS location',
                latitude: Number(latitude),
                longitude: Number(longitude),
                map_role: 'sender',
                readable_location: trigger.getAttribute('data-location-text') || 'Civilian GPS location',
            }]));

            body.appendChild(map);
            activateDialog(modal, closeMapModal, trigger);

            window.setTimeout(() => {
                window.StitchRefreshMaps?.();
            }, 80);
        };

        document.addEventListener('click', (event) => {
            if (event.target.matches('[data-report-map-modal-close]') || event.target === modal) {
                closeMapModal();
                return;
            }

            const trigger = event.target.closest('[data-report-map-trigger]');
            if (!trigger) {
                return;
            }

            event.preventDefault();
            openMapModal(trigger);
        });

        closeButtons.forEach((button) => button.addEventListener('click', closeMapModal));
    })();

    (() => {
        const viewer = document.querySelector('[data-media-viewer]');
        if (!viewer) {
            return;
        }

        const title = viewer.querySelector('[data-media-viewer-title]');
        const image = viewer.querySelector('[data-media-viewer-image]');
        const video = viewer.querySelector('[data-media-viewer-video]');

        const closeViewer = () => {
            deactivateDialog(viewer);
            image.hidden = true;
            image.removeAttribute('src');
            video.pause();
            video.hidden = true;
            video.removeAttribute('src');
            video.load();
        };

        document.addEventListener('click', (event) => {
            const trigger = event.target.closest('[data-media-viewer-trigger]');
            if (trigger) {
                event.preventDefault();
                const mediaType = trigger.getAttribute('data-media-type');
                const mediaSrc = trigger.getAttribute('data-media-src');
                const mediaTitle = trigger.getAttribute('data-media-title') || 'Evidence Preview';

                if (!mediaSrc) {
                    return;
                }

                title.textContent = mediaTitle;
                activateDialog(viewer, closeViewer, trigger);

                if (mediaType === 'video') {
                    image.hidden = true;
                    image.removeAttribute('src');
                    video.src = mediaSrc;
                    video.hidden = false;
                    video.load();
                } else {
                    video.pause();
                    video.hidden = true;
                    video.removeAttribute('src');
                    video.load();
                    image.src = mediaSrc;
                    image.hidden = false;
                }

                return;
            }

            if (event.target.matches('[data-media-viewer-close]') || event.target === viewer) {
                closeViewer();
            }
        });

    })();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeShellDialogs);
} else {
    initializeShellDialogs();
}
