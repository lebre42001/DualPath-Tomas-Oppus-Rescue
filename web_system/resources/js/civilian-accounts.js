import { onDomReady } from './dom-ready';
onDomReady( () => {
    const modal = document.querySelector('[data-civilian-account-modal]');

    if (!modal) {
        return;
    }

    const modalTitle = modal.querySelector('[data-civilian-account-modal-title]');
    const modalBody = modal.querySelector('[data-civilian-account-modal-body]');
    const closeButtons = modal.querySelectorAll('[data-civilian-account-modal-close]');

    let activeTrigger = null;

    const closeModal = () => {
        modal.hidden = true;
        document.body.style.overflow = '';

        if (modalBody) {
            modalBody.innerHTML = '';
        }

        if (activeTrigger instanceof HTMLElement) {
            activeTrigger.focus({ preventScroll: true });
        }
    };

    const openModal = (row, trigger = null) => {
        if (!(row instanceof HTMLElement) || !modalBody) {
            return;
        }

        const source = row.querySelector('.civilian-account-modal-source');

        if (!source) {
            return;
        }

        activeTrigger = trigger instanceof HTMLElement ? trigger : null;

        const clone = source.cloneNode(true);
        clone.classList.add('is-modal-copy');

        if (modalTitle) {
            modalTitle.textContent = row.getAttribute('data-civilian-account-title') || 'Civilian account details';
        }

        modalBody.innerHTML = '';
        modalBody.appendChild(clone);
        modal.hidden = false;
        document.body.style.overflow = 'hidden';

        const firstFocusable = modal.querySelector('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');

        if (firstFocusable instanceof HTMLElement) {
            firstFocusable.focus({ preventScroll: true });
        }
    };

    closeButtons.forEach((button) => {
        button.addEventListener('click', closeModal);
    });

    document.addEventListener('click', (event) => {
        const target = event.target;

        if (!(target instanceof Element)) {
            return;
        }

        if (target === modal || target.closest('[data-civilian-account-modal-close]')) {
            closeModal();
            return;
        }

        if (modal.contains(target) && target.closest('[data-civilian-account-close]')) {
            event.preventDefault();
            closeModal();
            return;
        }

        const trigger = target.closest('[data-civilian-account-modal-trigger]');

        if (!trigger) {
            return;
        }

        const row = trigger.closest('[data-civilian-account-row]');

        if (!row) {
            return;
        }

        event.preventDefault();
        openModal(row, trigger);
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !modal.hidden) {
            closeModal();
        }
    });

    if (window.location.hash?.startsWith('#civilian-account-')) {
        const targetRow = document.querySelector(window.location.hash);

        if (targetRow instanceof HTMLElement) {
            window.setTimeout(() => openModal(targetRow), 120);
        }
    }
});
