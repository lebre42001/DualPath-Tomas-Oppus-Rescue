function initializeReportIndex() {
    (() => {
        const exportRoot = document.querySelector('[data-report-export]');
        const form = exportRoot?.querySelector('[data-export-form]');
        if (!exportRoot || !form) {
            return;
        }

        const period = form.querySelector('[data-export-period]');
        const customFields = form.querySelector('[data-export-custom-fields]');
        const dateFrom = form.querySelector('[data-export-date-from]');
        const dateTo = form.querySelector('[data-export-date-to]');
        const previewButton = form.querySelector('[data-export-preview]');
        const feedback = form.querySelector('[data-export-feedback]');
        const downloads = form.querySelector('[data-export-downloads]');
        const csvLink = form.querySelector('[data-export-csv]');
        const xlsxLink = form.querySelector('[data-export-xlsx]');
        let requestVersion = 0;

        const paramsForForm = () => {
            const params = new URLSearchParams();
            new FormData(form).forEach((value, key) => {
                if (typeof value === 'string' && value.trim() !== '') {
                    params.set(key, value.trim());
                }
            });
            return params;
        };

        const clearPreview = () => {
            requestVersion += 1;
            downloads.hidden = true;
            feedback.hidden = true;
            feedback.textContent = '';
        };

        const syncCustomFields = () => {
            const custom = period.value === 'custom';
            customFields.hidden = !custom;
            dateFrom.disabled = !custom;
            dateTo.disabled = !custom;
            dateFrom.required = custom;
            dateTo.required = custom;
            clearPreview();
        };

        const buildDownloadUrl = (format) => {
            const params = paramsForForm();
            params.set('format', format);
            return `${exportRoot.dataset.downloadUrl}?${params.toString()}`;
        };

        period.addEventListener('change', syncCustomFields);
        form.addEventListener('input', clearPreview);
        form.addEventListener('change', (event) => {
            if (event.target !== period) {
                clearPreview();
            }
        });
        form.addEventListener('submit', (event) => event.preventDefault());

        previewButton.addEventListener('click', async () => {
            if (!form.reportValidity()) {
                return;
            }

            const thisRequest = ++requestVersion;
            const params = paramsForForm();
            previewButton.disabled = true;
            feedback.hidden = false;
            feedback.textContent = 'Checking matching reports…';
            downloads.hidden = true;

            try {
                const response = await fetch(`${exportRoot.dataset.previewUrl}?${params.toString()}`, {
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                });
                const result = await response.json();
                if (thisRequest !== requestVersion) {
                    return;
                }

                if (!response.ok) {
                    const firstError = Object.values(result.errors || {}).flat()[0];
                    throw new Error(firstError || result.message || 'Could not preview this export. Check the selected dates and filters.');
                }

                feedback.textContent = result.count === 0
                    ? `No reports found from ${result.from} to ${result.to} (${result.timezone}).`
                    : `${result.count} report${result.count === 1 ? '' : 's'} will be included, from ${result.from} to ${result.to} (${result.timezone}).`;

                if (result.count > 0) {
                    csvLink.href = buildDownloadUrl('csv');
                    xlsxLink.href = buildDownloadUrl('xlsx');
                    downloads.hidden = false;
                }
            } catch (error) {
                if (thisRequest === requestVersion) {
                    feedback.textContent = error.message || 'Could not preview this export. Please try again.';
                    downloads.hidden = true;
                }
            } finally {
                previewButton.disabled = false;
            }
        });
    })();

    (() => {
        const modal = document.querySelector('[data-report-filter-modal]');
        const openButton = document.querySelector('[data-report-filter-open]');
        if (!modal || !openButton) {
            return;
        }

        const searchInput = modal.querySelector('#modal-search');
        const closeButtons = modal.querySelectorAll('[data-report-filter-close]');
        let previousOverflow = '';
        const openModal = () => {
            previousOverflow = document.body.style.overflow;
            modal.hidden = false;
            document.body.style.overflow = 'hidden';
            window.setTimeout(() => searchInput?.focus(), 60);
        };
        const closeModal = () => {
            modal.hidden = true;
            document.body.style.overflow = previousOverflow;
            openButton.focus();
        };

        openButton.addEventListener('click', openModal);
        closeButtons.forEach((button) => button.addEventListener('click', closeModal));
        modal.addEventListener('click', (event) => {
            if (event.target === modal) {
                closeModal();
            }
        });
        document.addEventListener('keydown', (event) => {
            if (modal.hidden) {
                return;
            }

            if (event.key === 'Escape') {
                event.preventDefault();
                closeModal();
                return;
            }

            if (event.key === 'Tab') {
                const focusable = [...modal.querySelectorAll('a[href], button, input, select')]
                    .filter((element) => !element.disabled && element.getClientRects().length);
                const first = focusable[0];
                const last = focusable[focusable.length - 1];

                if (event.shiftKey && document.activeElement === first) {
                    event.preventDefault();
                    last?.focus();
                } else if (!event.shiftKey && document.activeElement === last) {
                    event.preventDefault();
                    first?.focus();
                }
            }
        });
    })();

    (() => {
        const modal = document.querySelector('[data-civilian-report-modal]');
        if (!modal) {
            return;
        }

        const modalTitle = modal.querySelector('[data-civilian-report-modal-title]');
        const modalBody = modal.querySelector('[data-civilian-report-modal-body]');
        let lastTrigger = null;

        const closeModal = () => {
            modal.hidden = true;
            modalBody.innerHTML = '';
            document.body.style.overflow = '';

            if (lastTrigger) {
                lastTrigger.focus();
            }
        };

        const openModal = (row, trigger) => {
            const source = row?.querySelector('[data-civilian-report-modal-source]');
            if (!source) {
                return;
            }

            const clone = source.cloneNode(true);
            clone.hidden = false;
            modalTitle.textContent = row.getAttribute('data-civilian-report-title') || 'Report details';
            modalBody.innerHTML = '';
            modalBody.appendChild(clone);
            modal.hidden = false;
            document.body.style.overflow = 'hidden';
            lastTrigger = trigger || null;
        };

        document.addEventListener('click', (event) => {
            if (event.target.matches('[data-civilian-report-modal-close]') || event.target === modal || event.target.closest('[data-civilian-report-modal-content-close]')) {
                event.preventDefault();
                closeModal();
                return;
            }

            const trigger = event.target.closest('[data-civilian-report-modal-trigger]');
            if (!trigger) {
                return;
            }

            const targetId = trigger.getAttribute('data-civilian-report-target');
            const row = trigger.closest('[data-civilian-report-row]')
                || (targetId ? document.querySelector(`[data-civilian-report-row][data-civilian-report-id="${CSS.escape(targetId)}"]`) : null);
            if (!row) {
                return;
            }

            event.preventDefault();
            openModal(row, trigger);
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && !modal.hidden) {
                closeModal();
                return;
            }

            if (!['Enter', ' '].includes(event.key)) {
                return;
            }

            const trigger = event.target.closest?.('[data-civilian-report-modal-trigger]');
            if (!trigger) {
                return;
            }

            const targetId = trigger.getAttribute('data-civilian-report-target');
            const row = trigger.closest('[data-civilian-report-row]')
                || (targetId ? document.querySelector(`[data-civilian-report-row][data-civilian-report-id="${CSS.escape(targetId)}"]`) : null);
            if (!row) {
                return;
            }

            event.preventDefault();
            openModal(row, trigger);
        });
    })();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeReportIndex, { once: true });
} else {
    initializeReportIndex();
}
