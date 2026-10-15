import { onDomReady } from './dom-ready';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

const severityColors = {
    Minor: '#1f9d68',
    Serious: '#d18b1f',
    Fatal: '#c72626',
    Critical: '#c72626',
    'Needs review': '#d18b1f',
};

const severityTone = {
    Minor: 'green',
    Serious: 'amber',
    Fatal: 'red',
    Critical: 'red',
    'Needs review': 'amber',
};

const bontocCenter = [10.354270414923162, 124.97039989612004];
const mapStates = [];
let mapDecorationsInjected = false;

onDomReady( () => {
    initializeSidebar();
    initializeMaps();
    initializeNotificationToggle();
    initializeAlertCenter();
    initializeViewToggles();
    initializeReportReviewRows();
    initializeCommandReviewQueue();
    initializeCommandMapPanels();
    initializeCommandPagedLists();
    initializeCommandAuditModal();
    initializeSystemClock();
    subscribeToIncidentFeed();
});

window.StitchRefreshMaps = () => {
    initializeMaps();
    invalidateVisibleMaps();
};

function initializeSidebar() {
    const sidebar = document.querySelector('[data-sidebar]');
    const toggle = document.querySelector('[data-sidebar-toggle]');
    const reopen = document.querySelector('[data-sidebar-reopen]');
    const collapseToggle = document.querySelector('[data-sidebar-collapse-toggle]');
    const close = document.querySelector('[data-sidebar-close]');
    const backdrop = document.querySelector('[data-sidebar-backdrop]');
    if (!sidebar || !backdrop) return;

    // Must match admin-shell.css; civilian navigation keeps its existing threshold.
    const mobileBreakpoint = document.body.classList.contains('admin-portal') ? 1024 : 1380;
    const storageKey = 'stitch-sidebar-collapsed';
    let collapsed = false;
    let opener = null;
    try { collapsed = localStorage.getItem(storageKey) === 'true'; } catch (_) { /* Storage is optional. */ }
    const isDrawer = () => window.innerWidth <= mobileBreakpoint;
    const setExpanded = (expanded) => {
        [toggle, reopen].forEach((button) => button?.setAttribute('aria-expanded', String(expanded)));
    };
    const syncCollapsedState = () => {
        const active = collapsed && !isDrawer();
        document.body.classList.toggle('sidebar-collapsed', active);
        collapseToggle?.setAttribute('aria-expanded', String(!active));
        collapseToggle?.setAttribute('aria-label', active ? 'Expand sidebar' : 'Collapse sidebar');
        const label = collapseToggle?.querySelector('[data-sidebar-collapse-label]');
        if (label) label.textContent = active ? 'Expand' : 'Collapse';
        window.setTimeout(() => invalidateVisibleMaps(), 200);
    };
    const closeSidebar = (restoreFocus = false) => {
        sidebar.classList.remove('is-open');
        backdrop.hidden = true;
        document.body.classList.remove('sidebar-open');
        sidebar.removeAttribute('role');
        sidebar.removeAttribute('aria-modal');
        setExpanded(false);
        if (restoreFocus && opener && document.contains(opener)) opener.focus();
    };
    const openSidebar = (event) => {
        if (!isDrawer()) return;
        opener = event?.currentTarget || toggle || reopen;
        sidebar.classList.add('is-open');
        backdrop.hidden = false;
        document.body.classList.add('sidebar-open');
        sidebar.setAttribute('role', 'dialog');
        sidebar.setAttribute('aria-modal', 'true');
        sidebar.setAttribute('aria-label', 'Main navigation');
        setExpanded(true);
        close?.focus();
    };
    sidebar.querySelectorAll('.sidebar-nav a').forEach((link) => {
        const name = link.querySelector('span')?.textContent.trim();
        if (name) { link.setAttribute('aria-label', name); link.title = name; }
        if (link.classList.contains('active')) link.setAttribute('aria-current', 'page');
        link.addEventListener('click', () => closeSidebar());
    });
    toggle?.addEventListener('click', openSidebar);
    reopen?.addEventListener('click', openSidebar);
    close?.addEventListener('click', () => closeSidebar(true));
    backdrop.addEventListener('click', () => closeSidebar(true));
    collapseToggle?.addEventListener('click', () => {
        collapsed = !collapsed;
        try { localStorage.setItem(storageKey, String(collapsed)); } catch (_) { /* Still works for this visit. */ }
        syncCollapsedState();
    });
    let wasDrawer = isDrawer();
    window.addEventListener('resize', () => {
        const drawer = isDrawer();
        if (drawer !== wasDrawer) closeSidebar();
        wasDrawer = drawer;
        syncCollapsedState();
    });
    document.addEventListener('keydown', (event) => {
        if (!sidebar.classList.contains('is-open')) return;
        if (event.key === 'Escape') { event.preventDefault(); closeSidebar(true); }
        if (event.key !== 'Tab') return;
        const items = Array.from(sidebar.querySelectorAll('a[href], button:not([disabled])')).filter((item) => item.getClientRects().length);
        const first = items[0], last = items[items.length - 1];
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
    });
    syncCollapsedState();
}

function initializeMaps() {
    ensureMapDecorations();

    document.querySelectorAll('[data-incident-map]').forEach((element) => {
        if (element.dataset.mapInitialized === 'true') {
            return;
        }

        const points = mapPointsForElement(element);
        const route = parseJson(element.dataset.route);
        const map = L.map(element).setView(bontocCenter, 14);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap contributors',
        }).addTo(map);

        element.dataset.mapInitialized = 'true';

        const state = {
            element,
            map,
            markers: new Map(),
            rings: new Map(),
            points: new Map(),
            routeLayer: null,
            route,
            scope: element.dataset.mapScope ?? 'feed',
            reportId: element.dataset.reportId ?? null,
        };
        points.forEach((point) => upsertMarker(state, point));
        syncRouteOverlay(state);
        fitMap(state);
        window.setTimeout(() => {
            state.map.invalidateSize();
            fitMap(state);
        }, 180);
        mapStates.push(state);
    });
}

function ensureMapDecorations() {
    if (mapDecorationsInjected) {
        return;
    }

    const style = document.createElement('style');
    style.textContent = `
        .stitch-map-icon{background:transparent;border:0}
        .stitch-map-pin{position:relative;width:44px;height:54px;display:flex;align-items:flex-start;justify-content:center;filter:drop-shadow(0 12px 18px rgba(15,31,47,.28))}
        .stitch-map-pin__pulse{position:absolute;top:2px;width:42px;height:42px;border-radius:999px;background:var(--pin-color);opacity:.28;animation:stitch-map-pulse 1.8s ease-out infinite}
        .stitch-map-pin__core{position:relative;z-index:2;width:28px;height:28px;border-radius:999px;background:var(--pin-color);border:4px solid #fff;box-shadow:0 10px 22px rgba(15,31,47,.24)}
        .stitch-map-pin__tip{position:absolute;top:23px;z-index:1;width:18px;height:18px;background:var(--pin-color);transform:rotate(45deg);border-right:4px solid #fff;border-bottom:4px solid #fff;border-radius:3px}
        .stitch-map-pin.is-command-center .stitch-map-pin__core{width:18px;height:18px}
        .stitch-map-pin.is-sender .stitch-map-pin__core{width:22px;height:22px;box-shadow:0 12px 28px rgba(199,38,38,.30)}
        .stitch-map-label,.stitch-map-route-label{background:rgba(15,31,47,.92);border:0;border-radius:999px;color:#fff;font:700 12px/1.2 "Trebuchet MS",sans-serif;letter-spacing:.04em;padding:8px 12px;box-shadow:0 10px 24px rgba(15,31,47,.18)}
        .stitch-map-label-command{background:rgba(32,104,174,.96)}
        .stitch-map-label-sender{background:rgba(199,38,38,.94)}
        .stitch-map-route-label{background:rgba(255,255,255,.96);color:#0f1f2f;border:1px solid rgba(32,104,174,.14);border-radius:18px;padding:10px 12px;box-shadow:0 10px 20px rgba(15,31,47,.12)}
        @keyframes stitch-map-pulse{
            0%{transform:scale(.65);opacity:.70}
            70%{transform:scale(1.5);opacity:0}
            100%{transform:scale(1.5);opacity:0}
        }
    `;

    document.head.append(style);
    mapDecorationsInjected = true;
}

function initializeNotificationToggle() {
    const prompt = document.querySelector('[data-admin-notification-prompt]');
    const allow = prompt?.querySelector('[data-admin-notification-allow]');
    const dismiss = prompt?.querySelector('[data-admin-notification-dismiss]');
    const copy = prompt?.querySelector('[data-admin-notification-copy]');
    if (!prompt || !allow || !copy) return;
    let dismissed = false;
    try { dismissed = sessionStorage.getItem('stitch-notification-dismissed') === 'true'; } catch (_) { /* Optional storage. */ }
    const sync = () => {
        const supported = 'Notification' in window;
        const permission = supported ? Notification.permission : 'unsupported';
        prompt.hidden = dismissed || permission === 'granted';
        allow.hidden = !supported || permission === 'denied';
        copy.textContent = !supported
            ? 'Browser notifications are unavailable here. Keep Live Monitoring open to see incoming reports.'
            : permission === 'denied'
                ? 'Notifications are blocked. You can enable them in browser settings, or continue with Live Monitoring.'
                : 'Allow browser notifications for incoming reports, or continue with Live Monitoring.';
        if (permission === 'granted') {
            try { localStorage.setItem('stitch-live-alerts', 'granted'); } catch (_) { /* Optional storage. */ }
        }
    };
    dismiss?.addEventListener('click', () => {
        dismissed = true;
        try { sessionStorage.setItem('stitch-notification-dismissed', 'true'); } catch (_) { /* Optional storage. */ }
        sync();
    });
    allow.addEventListener('click', async () => {
        try {
            await new Promise((resolve, reject) => {
                const result = Notification.requestPermission(resolve);
                if (result && typeof result.then === 'function') result.then(resolve, reject);
            });
        } catch (_) {
            copy.textContent = 'Notification permission could not be requested. You can continue with Live Monitoring.';
            return;
        }
        sync();
    });
    sync();
}

function initializeAlertCenter() {
    const trigger = document.querySelector('[data-alert-center-toggle]');
    const panel = document.querySelector('[data-alert-center-panel]');
    const close = panel?.querySelector('[data-alert-center-close]');
    if (!trigger || !panel) return;
    const hide = (restoreFocus = false) => {
        panel.hidden = true;
        trigger.setAttribute('aria-expanded', 'false');
        if (restoreFocus) trigger.focus();
    };
    trigger.addEventListener('click', () => {
        panel.hidden = !panel.hidden;
        trigger.setAttribute('aria-expanded', String(!panel.hidden));
        if (!panel.hidden) close?.focus();
    });
    close?.addEventListener('click', () => hide(true));
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && !panel.hidden) hide(true);
    });
    document.addEventListener('click', event => {
        if (!panel.hidden && !panel.contains(event.target) && !trigger.contains(event.target)) hide();
    });
}

function initializeViewToggles() {
    document.querySelectorAll('[data-view-toggle-controls]').forEach((controls) => {
        const host = controls.closest('.panel')?.querySelector('[data-view-toggle]');
        if (!host) {
            return;
        }

        const buttons = [...controls.querySelectorAll('[data-view-mode]')];
        buttons.forEach((button) => {
            button.addEventListener('click', () => {
                const mode = button.dataset.viewMode ?? 'split';
                host.dataset.viewMode = mode;
                buttons.forEach((item) => item.classList.toggle('is-active', item === button));
                window.setTimeout(() => invalidateVisibleMaps(), 180);
            });
        });
    });
}

function initializeReportReviewRows() {
    document.querySelectorAll('.incident-feed-row').forEach((row) => {
        if (!(row instanceof HTMLDetailsElement) || row.dataset.reportRowReady === 'true') {
            return;
        }

        const label = row.querySelector('[data-report-review-label]');
        const closeButtons = row.querySelectorAll('[data-report-row-close]');
        const sync = () => {
            if (label) {
                label.textContent = row.open ? 'Close' : 'Review';
            }

            refreshMapsInside(row);
        };

        closeButtons.forEach((button) => {
            button.addEventListener('click', () => {
                row.open = false;
                sync();
                row.scrollIntoView({ behavior: 'smooth', block: 'center' });
            });
        });

        row.addEventListener('toggle', sync);
        row.dataset.reportRowReady = 'true';
        sync();
    });
}

function initializeCommandReviewQueue() {
    document.querySelectorAll('[data-command-review-window]').forEach((viewport) => {
        const panel = viewport.closest('.command-focus-panel');
        const grid = panel?.closest('.command-dashboard-grid');
        const sideStack = panel?.nextElementSibling?.classList.contains('command-side-stack')
            ? panel.nextElementSibling
            : grid?.querySelector('.command-side-stack');
        const list = viewport.querySelector('[data-command-review-list]');
        const controls = panel?.querySelector('[data-command-review-controls]');
        const cards = [...viewport.querySelectorAll('[data-command-review-item]')];
        const upButton = controls?.querySelector('[data-command-review-nav="up"]');
        const downButton = controls?.querySelector('[data-command-review-nav="down"]');
        const pageSize = Math.max(1, Number.parseInt(viewport.dataset.pageSize ?? '2', 10));
        let pageIndex = 0;

        const syncPanelHeight = () => {
            if (!panel || !sideStack || window.innerWidth <= 1220) {
                panel?.style.removeProperty('--command-review-panel-height');
                return;
            }

            const height = Math.ceil(sideStack.getBoundingClientRect().height);
            if (height > 0) {
                panel.style.setProperty('--command-review-panel-height', `${height}px`);
            }
        };

        const syncWindowHeight = () => {
            if (window.innerWidth <= 1220) {
                viewport.style.removeProperty('--command-review-window-height');
                return;
            }

            const visibleCards = cards.slice(0, Math.min(pageSize, cards.length));
            if (visibleCards.length === 0) {
                viewport.style.removeProperty('--command-review-window-height');
                return;
            }

            const styles = list ? window.getComputedStyle(list) : null;
            const gap = Number.parseFloat(styles?.rowGap || styles?.gap || '0') || 0;
            const height = visibleCards.reduce((total, card) => total + card.offsetHeight, 0)
                + (gap * Math.max(0, visibleCards.length - 1));

            viewport.style.setProperty('--command-review-window-height', `${Math.ceil(height)}px`);
        };

        const syncLayout = () => {
            syncPanelHeight();
            syncWindowHeight();
        };

        syncLayout();

        if (!controls || !upButton || !downButton || cards.length <= pageSize) {
            if (controls) {
                controls.hidden = true;
            }

            window.addEventListener('resize', syncLayout);
            return;
        }

        const lastPageIndex = () => Math.floor((cards.length - 1) / pageSize) * pageSize;

        const scrollToPage = (index, behavior = 'smooth') => {
            pageIndex = Math.max(0, Math.min(index, lastPageIndex()));
            const target = cards[pageIndex];

            if (target) {
                viewport.scrollTo({
                    top: target.offsetTop,
                    behavior,
                });
            }

            upButton.disabled = pageIndex === 0;
            downButton.disabled = pageIndex + pageSize >= cards.length;
        };

        controls.hidden = false;
        upButton.addEventListener('click', () => scrollToPage(pageIndex - pageSize));
        downButton.addEventListener('click', () => scrollToPage(pageIndex + pageSize));
        window.addEventListener('resize', () => {
            syncLayout();
            scrollToPage(pageIndex, 'auto');
        });
        scrollToPage(0, 'auto');
    });
}

function initializeCommandMapPanels() {
    document.querySelectorAll('.command-map-panel').forEach((panel) => {
        const grid = panel.closest('.command-dashboard-grid');
        const companion = panel.nextElementSibling?.classList.contains('command-map-companion-stack')
            ? panel.nextElementSibling
            : grid?.querySelector('.command-map-companion-stack');

        const syncHeight = () => {
            if (!companion || window.innerWidth <= 1220) {
                panel.style.removeProperty('--command-map-panel-height');
                refreshMapsInside(panel);
                return;
            }

            const height = Math.ceil(companion.getBoundingClientRect().height);
            if (height > 0) {
                panel.style.setProperty('--command-map-panel-height', `${height}px`);
                refreshMapsInside(panel);
            }
        };

        syncHeight();
        window.addEventListener('resize', syncHeight);
        window.setTimeout(syncHeight, 250);
    });
}

function initializeCommandPagedLists() {
    document.querySelectorAll('[data-command-paged-list]').forEach((list) => {
        if (list.dataset.commandPagedReady === 'true') {
            return;
        }

        const panel = list.closest('.panel');
        const controls = panel?.querySelector('[data-command-pager-controls]');
        const prevButton = controls?.querySelector('[data-command-page-action="prev"]');
        const nextButton = controls?.querySelector('[data-command-page-action="next"]');
        const label = controls?.querySelector('[data-command-page-label]');
        const items = [...list.querySelectorAll('[data-command-page-item]')];
        const pageSize = Math.max(1, Number.parseInt(list.dataset.pageSize ?? '3', 10));
        const totalPages = Math.max(1, Math.ceil(items.length / pageSize));
        let page = 0;

        const render = () => {
            items.forEach((item, index) => {
                const itemPage = Math.floor(index / pageSize);
                item.classList.toggle('command-page-item-hidden', itemPage !== page);
            });

            if (label) {
                label.textContent = `Page ${page + 1} / ${totalPages}`;
            }

            if (prevButton) {
                prevButton.disabled = page === 0;
            }

            if (nextButton) {
                nextButton.disabled = page >= totalPages - 1;
            }
        };

        if (!controls || !prevButton || !nextButton || items.length <= pageSize) {
            if (controls) {
                controls.hidden = true;
            }

            items.forEach((item) => item.classList.remove('command-page-item-hidden'));
            list.dataset.commandPagedReady = 'true';
            return;
        }

        controls.hidden = false;
        prevButton.addEventListener('click', () => {
            page = Math.max(0, page - 1);
            render();
        });
        nextButton.addEventListener('click', () => {
            page = Math.min(totalPages - 1, page + 1);
            render();
        });

        list.dataset.commandPagedReady = 'true';
        render();
    });
}

function initializeCommandAuditModal() {
    const modal = document.querySelector('[data-command-audit-modal]');

    if (!modal || modal.dataset.commandAuditReady === 'true') {
        return;
    }

    const title = modal.querySelector('[data-command-audit-modal-title]');
    const body = modal.querySelector('[data-command-audit-modal-body]');
    const closeButtons = modal.querySelectorAll('[data-command-audit-modal-close]');
    let activeRow = null;

    const closeModal = () => {
        modal.hidden = true;
        document.body.style.overflow = '';

        if (body) {
            body.innerHTML = '';
        }

        if (activeRow instanceof HTMLElement) {
            activeRow.focus({ preventScroll: true });
        }
    };

    const openModal = (row) => {
        if (!(row instanceof HTMLElement) || !body) {
            return;
        }

        const template = row.querySelector('[data-command-audit-template]');

        if (!(template instanceof HTMLTemplateElement)) {
            return;
        }

        activeRow = row;

        if (title) {
            title.textContent = row.getAttribute('data-command-audit-title') || 'Command action details';
        }

        body.innerHTML = '';
        body.appendChild(template.content.cloneNode(true));
        modal.hidden = false;
        document.body.style.overflow = 'hidden';

        const firstFocusable = modal.querySelector('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');

        if (firstFocusable instanceof HTMLElement) {
            firstFocusable.focus({ preventScroll: true });
        }
    };

    document.addEventListener('click', (event) => {
        const target = event.target;

        if (!(target instanceof Element)) {
            return;
        }

        if (target === modal || target.closest('[data-command-audit-modal-close]')) {
            closeModal();
            return;
        }

        const row = target.closest('[data-command-audit-row]');

        if (!row) {
            return;
        }

        event.preventDefault();
        openModal(row);
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !modal.hidden) {
            closeModal();
            return;
        }

        if (!['Enter', ' '].includes(event.key)) {
            return;
        }

        const row = event.target?.closest?.('[data-command-audit-row]');

        if (!row) {
            return;
        }

        event.preventDefault();
        openModal(row);
    });

    closeButtons.forEach((button) => button.addEventListener('click', closeModal));
    modal.dataset.commandAuditReady = 'true';
}

function initializeSystemClock() {
    const targets = document.querySelectorAll('[data-system-time]');
    if (targets.length === 0) {
        return;
    }

    const render = () => {
        const now = new Date();
        const formatted = now.toLocaleString('en-PH', {
            year: 'numeric',
            month: 'short',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
        });

        targets.forEach((target) => {
            target.textContent = formatted;
        });
    };

    render();
    window.setInterval(render, 1000);
}

function subscribeToIncidentFeed() {
    const needsLiveFeed =
        document.querySelector('[data-incident-map]') ||
        document.querySelector('[data-live-incident-list]') ||
        document.querySelector('[data-admin-notification-prompt]');

    if (!needsLiveFeed || !window.Echo) {
        return;
    }

    window.Echo.private('responders.incidents').listen('.incident.feed.updated', (payload) => {
        const report = payload.report ?? {};

        renderToast(payload.message ?? 'Incident feed updated.', report);
        void maybeShowBrowserNotification(payload.message ?? 'Incident feed updated.', report);
        prependAlert(report);
        updateLiveLists(report, payload.action ?? 'created');
        updateMaps(report);
        updateCounters(report, payload.action ?? 'created');
    });
}

function prependAlert(report) {
    if ((report.severity ?? '') !== 'Fatal') {
        return;
    }

    const list = document.querySelector('[data-alert-list]');
    const counter = document.querySelector('[data-alert-count]');

    if (!list) {
        return;
    }

    list.querySelector('.data-empty')?.remove();

    const item = document.createElement(report.detail_url ? 'a' : 'div');
    if (report.detail_url) {
        item.href = report.detail_url;
    }

    item.className = 'alert-item';
    item.innerHTML = `
        <span class="tag red">${escapeHtml(report.severity_display ?? report.severity ?? 'Critical')}</span>
        <strong>${escapeHtml(report.incident_type ?? 'Critical Incident')}</strong>
        <p>${escapeHtml(report.location_text ?? 'Unknown location')}</p>
        <p>${escapeHtml(report.priority_timer_label ?? 'New alert')}</p>
    `;

    list.prepend(item);
    trimList(list, 6, '.alert-item');

    if (counter) {
        counter.textContent = String(list.querySelectorAll('.alert-item').length);
    }
}

function updateMaps(report) {
    if (report.latitude == null || report.longitude == null) {
        return;
    }

    const point = {
        ...report,
        map_role: report.map_role ?? 'sender',
        readable_location: report.readable_location ?? report.location_text,
        google_maps_url: report.google_maps_url ?? mapSearchUrl(report),
    };

    mapStates.forEach((state) => {
        if (state.scope === 'single' && state.reportId !== String(report.id ?? '')) {
            return;
        }

        upsertMarker(state, point);
        syncRouteOverlay(state);
        fitMap(state);
    });
}

function invalidateVisibleMaps() {
    mapStates.forEach((state) => {
        if (!state.element.isConnected) {
            return;
        }

        state.map.invalidateSize();
    });
}

function updateLiveLists(report, action) {
    document.querySelectorAll('[data-live-incident-list]').forEach((list) => {
        const existing = list.querySelector(`[data-report-id="${report.id}"]`);
        const card = buildIncidentCard(report);

        if (existing) {
            existing.replaceWith(card);
            initializeMaps();
            return;
        }

        if (action === 'created') {
            list.querySelector('.data-empty')?.remove();

            const header = list.querySelector('.latest-incident-header');
            if (header) {
                header.after(card);
            } else {
                list.prepend(card);
            }

            trimList(list, Number.parseInt(list.dataset.maxItems ?? '8', 10), '[data-report-id]');
            initializeMaps();
        }
    });
}

function updateCounters(report, action) {
    if (action !== 'created') {
        return;
    }

    incrementCounter('active');
    incrementCounter('today');

    if (report.severity === 'Fatal') {
        incrementCounter('fatal');
    }
}

function incrementCounter(name) {
    document.querySelectorAll(`[data-live-stat="${name}"]`).forEach((element) => {
        const currentValue = Number.parseInt(element.textContent ?? '0', 10);
        if (Number.isFinite(currentValue)) {
            element.textContent = String(currentValue + 1);
        }
    });
}

function buildIncidentCard(report) {
    const severity = report.severity ?? 'Minor';
    const aiSource = String(report.ai_source ?? '');
    const isProvisional = report.severity_is_provisional || aiSource === 'text_review';
    const severityDisplay = isProvisional ? 'Needs review' : (report.severity_display ?? severity);
    const isTextAssessment = aiSource.startsWith('text_');
    const isHistoricalPhotoAssessment = ['python_model', 'image_model'].includes(aiSource);
    const aiScoreLabel = report.ai_score_label ?? (isTextAssessment
        ? (report.ai_confidence != null ? `Model score ${report.ai_confidence}% (uncalibrated)` : 'Model score unavailable')
        : (report.ai_confidence != null ? `Confidence ${report.ai_confidence}%` : 'Confidence unavailable'));
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
    const wrapper = document.createElement('article');
    const mapUrl = mapSearchUrl(report);
    const processState = adminProcessState(report);
    const hasCoordinates = report.latitude != null && report.longitude != null;
    const isLoRa = String(report.transmission_type ?? '').toLowerCase() === 'lora';
    const reporterMeta = isLoRa
        ? `Sender node ${report.lora_sender_id ?? 'unknown'}`
        : (report.reporter_contact ?? 'No contact provided');
    const analysisCell = isLoRa
        ? `<span>LoRa Source</span>
           <strong>Gateway ${escapeHtml(report.lora_gateway_id ?? 'unknown')}</strong>
           <p>No photo attached. Compact SOS payload from ${escapeHtml(report.lora_sender_id ?? 'sender node')}.</p>`
        : `<span>${isHistoricalPhotoAssessment ? 'Historical photo assessment' : 'Description assessment'}</span>
           <strong>${escapeHtml(severityDisplay)} · ${escapeHtml(aiScoreLabel)}</strong>
           <p>${escapeHtml(truncate(report.ai_summary ?? 'AI analysis is still pending for this civilian report.', 96))}</p>`;
    const inlineMap = hasCoordinates
        ? `<div class="latest-inline-map dashboard-mini-map" data-incident-map data-map-scope="single" data-report-id="${escapeAttribute(report.id ?? '')}" data-latitude="${escapeAttribute(report.latitude)}" data-longitude="${escapeAttribute(report.longitude)}" data-location-text="${escapeAttribute(report.location_text ?? 'Civilian GPS location')}" data-points="${escapeAttribute(JSON.stringify([{
                id: report.id,
                incident_type: report.incident_type ?? 'New Incident',
                location_text: report.location_text ?? 'Unknown location',
                severity,
                severity_display: severityDisplay,
                latitude: Number(report.latitude),
                longitude: Number(report.longitude),
                map_role: 'sender',
                readable_location: report.location_text ?? 'Unknown location',
                google_maps_url: mapUrl,
            }]))}"></div>`
        : '';
    const reviewButton = report.detail_url
        ? `<a href="${escapeAttribute(report.detail_url)}" class="action-btn primary">Review</a>`
        : '<span class="action-btn secondary">Review pending</span>';
    const deleteButton = report.destroy_url
        ? `<form method="POST" action="${escapeAttribute(report.destroy_url)}" onsubmit="return confirm('Delete this specific incident report and attached files?');">
                <input type="hidden" name="_token" value="${escapeAttribute(csrfToken)}">
                <input type="hidden" name="_method" value="DELETE">
                <input type="hidden" name="return_to" value="monitoring">
                <button type="submit" class="action-btn danger">Delete</button>
            </form>`
        : '';

    wrapper.className = `monitoring-incident-card latest-incident-row severity-${severity} ${processState.className}`;
    wrapper.dataset.reportId = String(report.id ?? '');
    wrapper.dataset.adminProcessState = processState.label;

    wrapper.innerHTML = `
        <div class="monitoring-card-top">
            <div class="monitoring-card-code">
                <span class="civilian-account-status-dot ${statusTone(report.status ?? 'received')}"></span>
                <span>
                    <strong>${escapeHtml(report.report_code ?? 'INCIDENT')}</strong>
                    <small>${escapeHtml(report.reporter_name ?? 'Unknown sender')} | ${escapeHtml(report.priority_timer_label ?? 'Just received')}</small>
                </span>
            </div>
            <span class="tag ${processState.tone}">${escapeHtml(displayStatusLabel(report.status ?? 'received'))}</span>
        </div>
        <div class="monitoring-card-body">
            <h3>${escapeHtml(report.incident_type ?? 'New Incident')}</h3>
            <div class="tag-row">
                <span class="tag ${severityTone[severityDisplay] ?? 'blue'}">${escapeHtml(severityDisplay)}</span>
                <span class="badge neutral">${escapeHtml(aiScoreLabel)}</span>
                <span class="tag neutral">${escapeHtml(String(report.transmission_type ?? 'online').toUpperCase())}</span>
                ${isHistoricalPhotoAssessment ? '<span class="badge neutral">Historical photo assessment</span>' : ''}
            </div>
            <p>${escapeHtml(truncate(report.ai_summary ?? report.description ?? 'No short description provided.', 98))}</p>
        </div>
        <div class="monitoring-card-map">
            ${inlineMap}
            <small>${hasCoordinates ? `GPS ${escapeHtml(Number(report.latitude).toFixed(6))}, ${escapeHtml(Number(report.longitude).toFixed(6))}` : escapeHtml(report.location_text ?? 'Manual location only')}</small>
        </div>
        <div class="monitoring-card-actions">
            ${reviewButton}
            ${deleteButton}
        </div>
    `;

    return wrapper;

    wrapper.innerHTML = `
        <div class="latest-cell latest-id">
            <span>ID</span>
            <strong>${escapeHtml(report.report_code ?? 'INCIDENT')}</strong>
            <small>#${escapeHtml(report.id ?? '')} · ${escapeHtml(report.priority_timer_label ?? 'Just received')}</small>
        </div>
        <div class="latest-cell">
            <span>Name</span>
            <strong>${escapeHtml(report.reporter_name ?? 'Unknown sender')}</strong>
            <small>${escapeHtml(reporterMeta)}</small>
        </div>
        <div class="latest-cell latest-ai-cell">
            ${analysisCell}
        </div>
        <div class="latest-cell latest-location-cell">
            <span>Location</span>
            <strong>${escapeHtml(report.location_text ?? 'Unknown location')}</strong>
            <small>${hasCoordinates ? `Pinpoint: ${escapeHtml(Number(report.latitude).toFixed(5))}, ${escapeHtml(Number(report.longitude).toFixed(5))}` : 'Manual location only'}</small>
            <a href="${escapeAttribute(mapUrl)}" target="_blank" rel="noopener">Open Map Pin</a>
            ${inlineMap}
        </div>
        <div class="latest-cell">
            <span>Type</span>
            <strong>${escapeHtml(report.incident_type ?? 'New Incident')}</strong>
            <span class="tag ${processState.tone} latest-process-tag">${escapeHtml(processState.label)}</span>
            <small>${escapeHtml(displayStatusLabel(report.status ?? 'received'))} · ${escapeHtml(String(report.transmission_type ?? 'online').toUpperCase())}</small>
        </div>
        <div class="latest-actions">
            ${reviewButton}
            ${deleteButton}
        </div>
    `;

    return wrapper;

    wrapper.innerHTML = `
        <div class="incident-rail severity-${escapeHtml(severityDisplay)}"></div>
        <div class="incident-meta">
            <span class="incident-code">${escapeHtml(report.report_code ?? 'INCIDENT')}</span>
            <strong class="incident-time">${escapeHtml(report.priority_timer_label ?? 'Just received')}</strong>
            <div class="tag-row">
                <span class="tag ${severityTone[severityDisplay] ?? 'blue'}">${escapeHtml(severityDisplay)}</span>
                <span class="tag ${statusTone(report.status ?? 'received')}">${escapeHtml(displayStatusLabel(report.status ?? 'received'))}</span>
            </div>
        </div>
        <div class="incident-content">
            <div class="incident-headline">
                <div>
                    <h3 class="incident-title">${escapeHtml(report.incident_type ?? 'New Incident')}</h3>
                    <p class="detail-copy"><strong>${escapeHtml(report.location_text ?? 'Unknown location')}</strong> • ${escapeHtml(report.assigned_responder_name ?? 'Admin receiver pending')}</p>
                </div>
                <div class="tag-row">
                    <span class="tag neutral">${escapeHtml(String(report.transmission_type ?? 'online').toUpperCase())}</span>
                    ${report.evidence_available ? `<span class="tag green">${escapeHtml(capitalize(report.evidence_type ?? 'evidence'))} Attached</span>` : ''}
                    ${report.selfie_available ? '<span class="tag neutral">Selfie Verified</span>' : ''}
                </div>
            </div>
            <p class="incident-summary">${escapeHtml(report.description ?? 'No description provided.')}</p>
        </div>
    `;

    return wrapper;
}

function renderToast(message, report) {
    const stack = document.querySelector('[data-live-toast-stack]');
    if (!stack) {
        return;
    }

    const toast = document.createElement('div');
    toast.className = 'live-toast';
    toast.innerHTML = `<strong>${escapeHtml(report.incident_type ?? 'Live Incident')}</strong><div>${escapeHtml(message)}</div>`;
    stack.prepend(toast);
    window.setTimeout(() => toast.remove(), 5000);
}

async function maybeShowBrowserNotification(message, report) {
    // Notification delivery is optional and must never interrupt live incident updates.
    try {
        if (!('Notification' in window) || Notification.permission !== 'granted') {
            return;
        }

        const title = report.incident_type ?? 'New Incident';
        const options = {
            body: message,
            tag: `incident-${report.id ?? Date.now()}`,
        };

        try {
            if ('serviceWorker' in navigator && typeof navigator.serviceWorker.getRegistration === 'function') {
                // Unlike serviceWorker.ready, this does not wait for a worker to be installed.
                const registration = await navigator.serviceWorker.getRegistration();
                if (registration && typeof registration.showNotification === 'function') {
                    await registration.showNotification(title, options);
                    return;
                }
            }
        } catch (_) {
            // A missing/inactive worker or denied delivery can still use the desktop fallback.
        }

        // Mobile browsers may expose Notification but reject its constructor.
        new Notification(title, options);
    } catch (_) {
        // Keep in-page alerts, reports, maps and counters working when notifications fail.
    }
}

function upsertMarker(state, point) {
    const identifier = String(point.id ?? '');
    if (!identifier) {
        return;
    }

    const coordinates = [Number(point.latitude), Number(point.longitude)];
    if (!Number.isFinite(coordinates[0]) || !Number.isFinite(coordinates[1])) {
        return;
    }

    state.points.set(identifier, point);

    const popup = createPopupMarkup(point);
    const existing = state.markers.get(identifier);

    if (existing) {
        existing
            .setLatLng(coordinates)
            .setIcon(createIncidentIcon(point))
            .bindPopup(popup)
            .unbindTooltip();

        applyMarkerTooltip(existing, point);
        upsertLocationRing(state, identifier, coordinates, point, popup);
        return;
    }

    const marker = L.marker(coordinates, {
        icon: createIncidentIcon(point),
        zIndexOffset: point.map_role === 'sender' ? 1000 : 0,
    }).bindPopup(popup);

    applyMarkerTooltip(marker, point);

    marker.addTo(state.map);
    state.markers.set(identifier, marker);
    upsertLocationRing(state, identifier, coordinates, point, popup);
}

function applyMarkerTooltip(marker, point) {
    if ((point.map_role ?? '') === 'command_center') {
        marker.bindTooltip('Command Center', {
            permanent: true,
            direction: 'top',
            offset: [0, -28],
            className: 'stitch-map-label stitch-map-label-command',
        });
        return;
    }

    if ((point.map_role ?? '') === 'sender') {
        marker.bindTooltip('Civilian pin', {
            permanent: true,
            direction: 'top',
            offset: [0, -38],
            className: 'stitch-map-label stitch-map-label-sender',
        });
    }
}

function upsertLocationRing(state, identifier, coordinates, point, popup) {
    const role = point.map_role ?? 'incident';
    const color = role === 'command_center'
        ? '#2068ae'
        : role === 'sender'
            ? '#c72626'
            : (severityColors[point.severity_display ?? point.severity] ?? '#2068ae');
    const ringStyle = {
        radius: role === 'sender' ? 12 : 9,
        color: '#ffffff',
        weight: role === 'sender' ? 4 : 3,
        fillColor: color,
        fillOpacity: role === 'sender' ? 0.92 : 0.84,
        opacity: 1,
    };
    const existing = state.rings?.get(identifier);

    if (existing) {
        existing.setLatLng(coordinates).setStyle(ringStyle).bindPopup(popup);
        return;
    }

    if (!state.rings) {
        state.rings = new Map();
    }

    const ring = L.circleMarker(coordinates, ringStyle).bindPopup(popup);
    ring.addTo(state.map);
    ring.bringToFront();
    state.rings.set(identifier, ring);
}

function createIncidentIcon(point) {
    const role = point.map_role ?? 'incident';
    const color = role === 'command_center'
        ? '#2068ae'
        : role === 'sender'
            ? '#c72626'
            : (severityColors[point.severity_display ?? point.severity] ?? '#2068ae');

    const classes = ['stitch-map-pin'];
    if (role === 'command_center') {
        classes.push('is-command-center');
    }
    if (role === 'sender') {
        classes.push('is-sender');
    }

    const pulse = role === 'sender' ? '<span class="stitch-map-pin__pulse"></span>' : '';

    return L.divIcon({
        className: 'stitch-map-icon',
        html: `
            <div class="${classes.join(' ')}" style="--pin-color:${color}">
                ${pulse}
                <span class="stitch-map-pin__core"></span>
                <span class="stitch-map-pin__tip"></span>
            </div>
        `,
        iconSize: [44, 54],
        iconAnchor: [22, 50],
        popupAnchor: [0, -38],
    });
}

function createPopupMarkup(point) {
    const role = point.map_role ?? 'incident';
    const locationLabel = point.readable_location ?? point.location_text ?? 'Unknown location';
    const roleLabel = role === 'command_center'
        ? 'Command Center'
        : role === 'sender'
            ? 'Sender Mobile Location'
            : (point.severity_display ?? point.severity ?? 'Incident');
    const barangayTown = point.barangay_town
        ? `<br><strong>Barangay / town:</strong> ${escapeHtml(point.barangay_town)}`
        : '';
    const distance = point.distance_from_command_center
        ? `<br><strong>Distance from Bontoc Command Center:</strong> ${escapeHtml(point.distance_from_command_center)}`
        : '';
    const travelTime = point.travel_time_from_command_center
        ? `<br><strong>${(point.route_status ?? '') === 'live' ? 'Travel time from Command Center' : 'Estimated travel time'}:</strong> ${escapeHtml(point.travel_time_from_command_center)}`
        : '';
    const mapsLink = point.google_maps_url
        ? `<br><a href="${escapeAttribute(point.google_maps_url)}" target="_blank" rel="noopener">Open in Google Maps</a>`
        : '';
    const directionsLink = point.directions_url && role === 'sender'
        ? `<br><a href="${escapeAttribute(point.directions_url)}" target="_blank" rel="noopener">Open route directions</a>`
        : '';

    return `
        <strong>${escapeHtml(point.incident_type ?? 'Incident')}</strong>
        <br>${escapeHtml(locationLabel)}
        <br>${escapeHtml(roleLabel)}
        ${barangayTown}
        ${distance}
        ${travelTime}
        ${mapsLink}
        ${directionsLink}
    `;
}

function syncRouteOverlay(state) {
    if (state.routeLayer) {
        state.map.removeLayer(state.routeLayer);
        state.routeLayer = null;
    }

    const points = [...state.points.values()];
    const sender = points.find((point) => (point.map_role ?? '') === 'sender');
    const commandCenter = points.find((point) => (point.map_role ?? '') === 'command_center');

    if (!sender || !commandCenter) {
        return;
    }

    const senderCoordinates = [Number(sender.latitude), Number(sender.longitude)];
    const commandCoordinates = [Number(commandCenter.latitude), Number(commandCenter.longitude)];

    if (!Number.isFinite(senderCoordinates[0]) || !Number.isFinite(senderCoordinates[1])) {
        return;
    }

    if (!Number.isFinite(commandCoordinates[0]) || !Number.isFinite(commandCoordinates[1])) {
        return;
    }

    const routeGeometry = Array.isArray(state.route?.geometry)
        ? state.route.geometry
            .map((point) => [Number(point?.lat), Number(point?.lng)])
            .filter((coordinates) => Number.isFinite(coordinates[0]) && Number.isFinite(coordinates[1]))
        : [];
    const hasLiveRoute = (state.route?.status ?? '') === 'live' && routeGeometry.length >= 2;
    const routeSummary = [
        sender.distance_from_command_center ? `Distance: ${sender.distance_from_command_center}` : null,
        sender.travel_time_from_command_center ? `${hasLiveRoute ? 'Travel time' : 'Estimated travel time'}: ${sender.travel_time_from_command_center}` : null,
    ].filter(Boolean).join('<br>');

    state.routeLayer = L.polyline(hasLiveRoute ? routeGeometry : [commandCoordinates, senderCoordinates], {
        color: hasLiveRoute ? '#145da0' : '#2068ae',
        weight: hasLiveRoute ? 5 : 4,
        opacity: hasLiveRoute ? 0.92 : 0.78,
        dashArray: hasLiveRoute ? null : '10 10',
        lineCap: 'round',
        lineJoin: 'round',
    }).bindTooltip(routeSummary || 'Estimated route from Command Center', {
        permanent: true,
        direction: 'center',
        className: 'stitch-map-route-label',
        opacity: 0.95,
    });

    state.routeLayer.addTo(state.map);
}

function fitMap(state) {
    const markers = [...state.markers.values()];
    if (markers.length === 0) {
        state.map.setView(bontocCenter, 14);
        return;
    }

    if (markers.length === 1) {
        state.map.setView(markers[0].getLatLng(), state.scope === 'single' ? 17 : 16);
        return;
    }

    const bounds = L.featureGroup(markers).getBounds();
    state.map.fitBounds(bounds.pad(0.25));
}

function refreshMapsInside(root) {
    window.setTimeout(() => {
        mapStates.forEach((state) => {
            if (!root.contains(state.element)) {
                return;
            }

            state.map.invalidateSize();
            fitMap(state);
        });
    }, 80);
}

function trimList(list, maxItems, selector) {
    if (!Number.isFinite(maxItems)) {
        return;
    }

    list.querySelectorAll(selector).forEach((item, index) => {
        if (index >= maxItems) {
            item.remove();
        }
    });
}

function parseJson(value) {
    try {
        return JSON.parse(value ?? '[]');
    } catch {
        return [];
    }
}

function mapPointsForElement(element) {
    const points = parseJson(element.dataset.points);

    if (Array.isArray(points) && points.length > 0) {
        return points;
    }

    const latitude = Number(element.dataset.latitude);
    const longitude = Number(element.dataset.longitude);
    if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) {
        return [];
    }

    return [{
        id: element.dataset.reportId ? `sender-${element.dataset.reportId}` : `sender-${latitude}-${longitude}`,
        incident_type: 'Civilian sender location',
        location_text: element.dataset.locationText || 'Civilian GPS location',
        severity: 'Fatal',
        latitude,
        longitude,
        map_role: 'sender',
        readable_location: element.dataset.locationText || 'Civilian GPS location',
        google_maps_url: mapSearchUrl({ latitude, longitude }),
    }];
}

function adminProcessState(report) {
    const status = String(report.status ?? 'received').toLowerCase();

    if (status === 'received') {
        return { label: 'Pending admin review', tone: 'amber', className: 'process-pending' };
    }

    if (status === 'acknowledged') {
        return { label: 'Under admin review', tone: 'blue', className: 'process-active' };
    }

    if (status === 'dispatched') {
        return { label: 'Response started', tone: 'blue', className: 'process-active' };
    }

    if (status === 'responding') {
        return { label: 'Admin responded', tone: 'blue', className: 'process-active' };
    }

    if (status === 'resolved') {
        return { label: 'Resolved', tone: 'green', className: 'process-complete' };
    }

    if (status === 'rejected') {
        return { label: 'Cancelled', tone: 'red', className: 'process-cancelled' };
    }

    return { label: capitalize(status), tone: 'neutral', className: 'process-neutral' };
}

function displayStatusLabel(status) {
    const normalizedStatus = String(status ?? 'received').toLowerCase();

    if (normalizedStatus === 'received') {
        return 'Pending';
    }

    if (normalizedStatus === 'acknowledged') {
        return 'Under review';
    }

    if (normalizedStatus === 'dispatched') {
        return 'Response started';
    }

    if (normalizedStatus === 'responding') {
        return 'Responded';
    }

    if (normalizedStatus === 'rejected') {
        return 'Cancelled';
    }

    return capitalize(normalizedStatus);
}

function statusTone(status) {
    const normalizedStatus = String(status ?? 'received').toLowerCase();

    if (normalizedStatus === 'received') {
        return 'amber';
    }

    if (normalizedStatus === 'resolved') {
        return 'green';
    }

    if (normalizedStatus === 'rejected') {
        return 'red';
    }

    return 'blue';
}

function capitalize(value) {
    if (!value) {
        return '';
    }

    return value.charAt(0).toUpperCase() + value.slice(1);
}

function truncate(value, maxLength) {
    const text = String(value ?? '');

    if (text.length <= maxLength) {
        return text;
    }

    return `${text.slice(0, Math.max(0, maxLength - 1))}…`;
}

function mapSearchUrl(report) {
    if (report.latitude != null && report.longitude != null) {
        return `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(`${report.latitude},${report.longitude}`)}`;
    }

    return `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(report.location_text ?? 'Bontoc Command Center')}`;
}

function escapeHtml(value) {
    return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function escapeAttribute(value) {
    return escapeHtml(value);
}
