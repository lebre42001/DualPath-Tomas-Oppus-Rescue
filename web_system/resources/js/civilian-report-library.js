import { onDomReady } from './dom-ready.js';
import { createCivilianDraftStore } from './civilian-draft-store.js';
import { DRAFT_STATES, draftReportPage, reportPageNumbers } from './civilian-report-list.js';

export function initializeCivilianReportLibrary(root, { document: doc = document, window: win = window, navigator: nav = navigator, fetchImpl = fetch, store = createCivilianDraftStore(root.dataset.owner) } = {}) {
    const field = name => root.querySelector(`[data-library-${name}]`);
    const modal = field('modal'), detail = field('detail'), form = field('filters'), rows = field('rows'), message = field('message');
    const states = new Map();
    let mode = 'history', applied = {}, page = 1, lastPage = 1, pendingPage = 1, loading = false, controller, detailController, photoUrl;
    const node = (tag, text = '', className = '') => { const element = doc.createElement(tag); element.textContent = text; element.className = className; return element; };
    const button = (text, action, className = 'btn btn-secondary') => { const element = node('button', text, className); element.type = 'button'; element.addEventListener('click', action); return element; };
    const formatted = value => value ? new Date(value).toLocaleString('en-PH', { timeZone: 'Asia/Manila', dateStyle: 'medium', timeStyle: 'short' }) : 'Not recorded';
    const dialogOpen = (target, close, opener) => { doc.dispatchEvent(new CustomEvent('stitch:dialog-open', { detail: { modal: target, close, opener } })); target.hidden = false; };
    const dialogClose = target => { doc.dispatchEvent(new CustomEvent('stitch:dialog-close', { detail: { modal: target } })); target.hidden = true; };
    const request = async (url, signal) => {
        const response = await fetchImpl(url, { credentials: 'same-origin', cache: 'no-store', signal, headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
        const data = await response.json().catch(() => ({}));
        if (!response.ok || response.redirected) throw new Error([401, 403, 419].includes(response.status) || response.redirected ? 'Sign in again to view your reports.' : Object.values(data.errors ?? {}).flat()[0] || 'Reports could not be loaded. Try again.');
        return data;
    };
    const controls = () => {
        field('previous').disabled = loading || page <= 1; field('next').disabled = loading || page >= lastPage;
        for (const target of field('pages').querySelectorAll('button')) target.disabled = loading;
        rows.setAttribute('aria-busy', String(loading));
    };
    const closeDetail = () => {
        detailController?.abort(); detailController = null; dialogClose(detail);
        field('detail-body').replaceChildren();
        if (photoUrl) { win.URL.revokeObjectURL(photoUrl); photoUrl = null; }
    };
    const paragraph = (parent, title, value) => { const section = node('section'); section.append(node('strong', title), node('p', value || 'Not recorded')); parent.append(section); };
    const photo = (parent, title, url) => {
        if (!url) return;
        const section = node('section'), image = node('img');
        image.src = url; image.alt = title; image.loading = 'lazy'; image.className = 'civilian-library-photo';
        section.append(node('strong', title), image); parent.append(section);
    };
    async function openDetail(report, opener, draft = mode === 'drafts') {
        detailController?.abort(); const current = new AbortController(); detailController = current;
        const body = field('detail-body'), feedback = field('detail-message');
        body.replaceChildren(); feedback.textContent = 'Loading report details…'; field('detail-title').textContent = report.code;
        dialogOpen(detail, closeDetail, opener);
        const timeout = win.setTimeout(() => current.abort(), 15000);
        try {
            if (draft) {
                const record = await store.get(report.id);
                if (detailController !== current || detail.hidden) return;
                if (!record) throw new Error('This draft is no longer saved for this account on this device.');
                paragraph(body, 'Status', DRAFT_STATES[record.state] ?? DRAFT_STATES.editing);
                paragraph(body, 'Saved (Philippine time)', formatted(record.updatedAt));
                paragraph(body, 'Location', record.fields.location_text); paragraph(body, 'Description', record.fields.description);
                if (record.evidenceFile instanceof Blob) {
                    photoUrl = win.URL.createObjectURL(record.evidenceFile); photo(body, 'Saved scene photo', photoUrl);
                } else paragraph(body, 'Scene photo', record.evidenceName ? 'Reattach the scene photo when continuing.' : 'No photo saved yet.');
                paragraph(body, 'Before sending', 'A draft is not confirmed as received. Continue with the same draft, lock GPS and complete a fresh live selfie check.');
                const actions = node('div', '', 'civilian-library-actions');
                actions.append(button('Continue draft', () => {
                    if (nav.onLine === false) { feedback.textContent = 'Reconnect to open the report form. Your draft remains saved on this device.'; return; }
                    const url = new URL(root.dataset.createUrl, win.location.href); url.searchParams.set('draft', record.submissionId); win.location.assign(url.href);
                }, 'btn btn-primary'), button('Delete draft', async () => {
                    if (!win.confirm('Delete this local draft? This does not delete any report received by the server.')) return;
                    try { await store.remove(record.submissionId); closeDetail(); if (!modal.hidden) await load(page); }
                    catch { feedback.textContent = 'This draft could not be deleted. Please try again.'; }
                })); body.append(actions);
            } else {
                const data = await request(report.detailsUrl, current.signal);
                if (detailController !== current || detail.hidden) return;
                if (!data.report) throw new Error('Report details could not be confirmed.');
                const item = data.report;
                paragraph(body, 'Report', `${item.code} · ${item.type}`); paragraph(body, 'Status', item.statusLabel);
                paragraph(body, 'Submitted (Philippine time)', formatted(item.submittedAt));
                paragraph(body, 'Status updated (Philippine time)', formatted(item.updatedAt));
                paragraph(body, 'Location', item.location); paragraph(body, 'Description', item.description);
                paragraph(body, 'Admin response', item.response || 'No admin message yet.'); paragraph(body, 'Assigned admin', item.assignedTo || 'Not assigned yet.');
                photo(body, 'Scene photo', item.evidenceUrl); photo(body, 'Verification selfie', item.selfieUrl);
                const link = node('a', 'Open full report', 'btn btn-secondary'); link.href = item.showUrl; body.append(link);
            }
            feedback.textContent = '';
        } catch (error) {
            if (detailController === current && !detail.hidden) {
                feedback.textContent = error.name === 'AbortError' ? 'Opening timed out. Try again.' : error.message;
                body.append(button('Try again', () => { closeDetail(); void openDetail(report, opener, draft); }));
            }
        } finally { win.clearTimeout(timeout); }
    }
    const render = data => {
        page = data.pagination.page; lastPage = data.pagination.lastPage;
        states.set(mode, { applied: { ...applied }, page });
        rows.replaceChildren();
        for (const report of data.reports) {
            const row = node('tr');
            for (const value of [report.code, report.location, report.statusLabel, formatted(mode === 'status' ? report.updatedAt || report.submittedAt : report.submittedAt)]) row.append(node('td', value));
            const cell = node('td'), view = button('View', () => void openDetail(report, view));
            view.setAttribute('aria-label', `View ${report.code}`); cell.append(view); row.append(cell); rows.append(row);
        }
        if (!data.reports.length) { const row = node('tr'), cell = node('td', 'No reports match these filters.'); cell.colSpan = 5; row.append(cell); rows.append(row); }
        const p = data.pagination;
        field('count').textContent = p.total ? `${p.from}–${p.to} of ${p.total} ${mode === 'drafts' ? 'drafts' : 'reports'}` : `0 ${mode === 'drafts' ? 'drafts' : 'reports'}`;
        field('page-label').textContent = `Page ${page} of ${lastPage}`;
        field('pages').replaceChildren();
        for (const number of reportPageNumbers(page, lastPage)) {
            const target = button(String(number), () => void load(number));
            target.setAttribute('aria-label', `Page ${number}`); if (number === page) target.setAttribute('aria-current', 'page'); field('pages').append(target);
        }
        field('scroll').scrollTop = 0; field('scroll').scrollLeft = 0; message.textContent = '';
    };
    async function load(requested = 1) {
        pendingPage = requested;
        controller?.abort(); const current = new AbortController(); controller = current;
        loading = true; controls(); rows.replaceChildren(); field('retry').hidden = true; message.textContent = 'Loading reports…';
        const currentMode = mode, timeout = win.setTimeout(() => current.abort(), 15000);
        try {
            let data;
            if (mode === 'drafts') data = draftReportPage(await store.list(), applied, requested);
            else {
                const url = new URL(root.dataset.reportsUrl, win.location.href);
                for (const [name, value] of Object.entries(applied)) url.searchParams.set(name, value);
                url.searchParams.set('page', requested); url.searchParams.set('view', mode);
                data = await request(url, current.signal);
            }
            if (controller !== current || modal.hidden || mode !== currentMode) return;
            if (!Array.isArray(data.reports) || !data.pagination) throw new Error('Report list could not be confirmed.');
            if (data.pagination.page > data.pagination.lastPage) return void load(data.pagination.lastPage);
            render(data);
        } catch (error) {
            if (controller !== current || modal.hidden) return;
            message.textContent = error.name === 'AbortError' ? 'Loading timed out. Try again.' : error.message;
            field('count').textContent = 'Reports unavailable'; field('retry').hidden = false;
            field('pages').replaceChildren(); field('page-label').textContent = ''; page = lastPage = 1;
        } finally {
            win.clearTimeout(timeout);
            if (controller === current) {
                loading = false; controls();
                if (!modal.hidden && detail.hidden && !modal.contains(doc.activeElement)) (field('pages').querySelector('[aria-current="page"]') ?? field('close')).focus({ preventScroll: true });
            }
        }
    }
    const close = () => { controller?.abort(); controller = null; if (!detail.hidden) closeDetail(); dialogClose(modal); };
    const open = (selected, opener) => {
        mode = selected;
        const saved = states.get(mode); applied = saved?.applied ?? { search: '', status: '', from: '', to: '', sort: 'newest' }; page = saved?.page ?? 1;
        const title = mode === 'drafts' ? 'Draft Reports' : mode === 'status' ? 'Report Status' : 'Report History';
        field('title').textContent = title; field('caption').textContent = `${title} for your signed-in account`;
        field('help').textContent = mode === 'drafts' ? 'Saved on this device for your account. Dates use Philippine time (UTC+8).' : 'Your submitted reports only. Date filters use the submission date in Philippine time (UTC+8).';
        field('date-heading').textContent = mode === 'drafts' ? 'Last saved' : mode === 'status' ? 'Latest status' : 'Submitted';
        const statuses = mode === 'drafts' ? DRAFT_STATES : { received: 'Pending', acknowledged: 'Acknowledged', dispatched: 'Dispatched', responding: 'Responding', resolved: 'Resolved', rejected: 'Cancelled' };
        const select = field('status'); select.replaceChildren();
        for (const [value, label] of [['', 'All statuses'], ...Object.entries(statuses)]) { const option = node('option', label); option.value = value; select.append(option); }
        for (const [name, value] of Object.entries(applied)) form.elements.namedItem(name).value = value;
        dialogOpen(modal, close, opener); void load(page);
    };
    doc.addEventListener('click', event => {
        const trigger = event.target.closest?.('[data-civilian-library-open]');
        if (trigger && ['drafts', 'history', 'status'].includes(trigger.dataset.civilianLibraryOpen)) { event.preventDefault(); open(trigger.dataset.civilianLibraryOpen, trigger); }
        const draft = event.target.closest?.('[data-civilian-library-draft]');
        if (draft) { event.preventDefault(); void openDetail({ id: draft.dataset.civilianLibraryDraft, code: 'Draft report' }, draft, true); }
    });
    form.addEventListener('submit', event => {
        event.preventDefault(); const values = Object.fromEntries(new FormData(form));
        if (values.from && values.to && values.from > values.to) { message.textContent = 'The end date must be on or after the start date.'; return; }
        applied = values; void load(1);
    });
    field('reset').addEventListener('click', () => { applied = { search: '', status: '', from: '', to: '', sort: 'newest' }; for (const [name, value] of Object.entries(applied)) form.elements.namedItem(name).value = value; void load(1); });
    field('close').addEventListener('click', close); field('detail-close').addEventListener('click', closeDetail);
    modal.addEventListener('click', event => { if (event.target === modal) close(); });
    detail.addEventListener('click', event => { if (event.target === detail) closeDetail(); });
    field('previous').addEventListener('click', () => { if (!loading && page > 1) void load(page - 1); });
    field('next').addEventListener('click', () => { if (!loading && page < lastPage) void load(page + 1); });
    field('retry').addEventListener('click', () => void load(pendingPage));
    win.addEventListener('stitch:civilian-drafts-changed', event => { if (event.detail?.owner === root.dataset.owner && mode === 'drafts' && !modal.hidden) void load(page); });
    win.addEventListener('storage', event => { if ((event.key === null || store.ownsStorageKey(event.key)) && mode === 'drafts' && !modal.hidden) void load(page); });
}

onDomReady(() => { const root = document.querySelector('[data-civilian-report-library]'); if (root) initializeCivilianReportLibrary(root); });
