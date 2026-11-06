import test from 'node:test';
import assert from 'node:assert/strict';
import { createCivilianDraftStore } from '../../resources/js/civilian-draft-store.js';

globalThis.document = { readyState: 'loading', addEventListener() {} };
const { initializeCivilianDrafts } = await import('../../resources/js/civilian-report-drafts.js');
const id = 'f06f156c-d08c-4d3b-939a-670b1cef6ad9';
const settle = () => new Promise(resolve => setTimeout(resolve, 180));

class Element extends EventTarget {
    children = []; dataset = {}; hidden = false; value = ''; files = []; textContent = ''; attributes = {};
    append(...nodes) { this.children.push(...nodes); }
    replaceChildren() { this.children = []; }
    setAttribute(name, value) { this.attributes[name] = value; }
    scrollIntoView() {}
    querySelectorAll(selector) { return this.children.flatMap(node => [...(selector === 'button' && node.tag === 'button' ? [node] : []), ...node.querySelectorAll(selector)]); }
}

const surface = ({ owner = '1', drafts = false, form = false, draftId = null } = {}) => {
    const doc = new EventTarget(), win = new EventTarget(), nav = { onLine: true }, context = new Element();
    context.dataset = { owner, createUrl: 'https://app.test/reports/create', receiptUrl: 'https://app.test/reports/submissions/__submission__' };
    const badge = new Element(), link = new Element(), mobileBadge = new Element(), mobileLink = new Element(), list = new Element(), empty = new Element(), counter = new Element(), notice = new Element();
    const panel = drafts ? new Element() : null;
    if (panel) panel.querySelector = selector => ({
        '[data-civilian-draft-list]': list, '[data-civilian-draft-empty]': empty,
        '[data-civilian-draft-count]': counter, '[data-civilian-draft-notice]': notice,
    })[selector];
    const reportForm = form ? new Element() : null;
    const fields = Object.fromEntries(['submission_id', 'description', 'location_text', 'evidence', 'evidence_photo_capture', 'selfie', 'selfie_capture'].map(name => [name, new Element()]));
    fields.submission_id.value = id;
    if (reportForm) {
        reportForm.elements = { namedItem: name => fields[name] };
        reportForm.querySelector = selector => {
            const name = selector.match(/^input\[name="(.+)"\]$/)?.[1];
            return name ? fields[name] : null;
        };
    }
    doc.querySelector = selector => ({
        '[data-civilian-drafts]': panel, '[data-civilian-draft-context]': context,
        '[data-report-draft-form][data-report-role="civilian"]': reportForm,
        '[data-civilian-draft-nav]': link,
    })[selector];
    doc.querySelectorAll = selector => selector === '[data-civilian-draft-nav-count]' ? [badge, mobileBadge] : selector === '[data-civilian-draft-nav]' ? [link, mobileLink] : [];
    doc.createElement = tag => Object.assign(new Element(), { tag });
    const destinations = [];
    win.location = { origin: 'https://app.test', href: 'https://app.test/reports/' + (drafts ? 'drafts' : 'create') + (draftId ? '?draft='+draftId : ''), assign: url => destinations.push(url) };
    win.setTimeout = setTimeout; win.clearTimeout = clearTimeout; win.confirm = () => true;
    globalThis.window = win; globalThis.location = win.location;
    initializeCivilianDrafts({ document: doc, window: win, navigator: nav });
    return { doc, win, reportForm, fields, badge, link, mobileBadge, mobileLink, list, empty, counter, destinations };
};

test('draft navigation badge follows saved work across app close/reopen, account switch and deletion without embedding a list in the form', async () => {
    const values = new Map();
    globalThis.localStorage = { getItem: key => values.get(key) ?? null, setItem: (key, value) => values.set(key, value) };
    globalThis.indexedDB = null;
    const editing = surface({ form: true }); await settle();
    assert.equal(editing.badge.hidden, true);
    assert.equal(editing.mobileBadge.hidden, true);
    editing.fields.description.value = 'Unsent accident report';
    editing.reportForm.dispatchEvent(new Event('input'));
    assert.match(values.get('aksyres-report-drafts-v2:1'), /Unsent accident report/, 'The last edit survives even without a lifecycle callback');
    await settle(); assert.equal(editing.badge.textContent, '1'); assert.equal(editing.badge.hidden, false);
    assert.equal(editing.mobileBadge.textContent, '1'); assert.equal(editing.mobileBadge.hidden, false);
    assert.equal(editing.list.children.length, 0, 'Only the navigation count is updated on the form');
    editing.win.dispatchEvent(new Event('pagehide')); await settle();
    const reopened = surface({ drafts: true }); await settle();
    assert.equal(reopened.list.children.length, 1); assert.equal(reopened.empty.hidden, true);
    assert.equal(reopened.link.attributes['aria-label'], 'Draft Reports, 1 saved');
    assert.equal(reopened.mobileLink.attributes['aria-label'], 'Draft Reports, 1 saved');
    reopened.list.children[0].querySelectorAll('button')[0].dispatchEvent(new Event('click')); await settle();
    assert.equal(reopened.destinations[0], 'https://app.test/reports/create?draft='+id);
    const other = surface({ owner: '2', drafts: true }); await settle();
    assert.equal(other.list.children.length, 0); assert.equal(other.badge.hidden, true); assert.equal(other.empty.hidden, false);
    assert.equal(other.mobileBadge.hidden, true); assert.equal(other.mobileLink.attributes['aria-label'], 'Draft Reports');
    const own = surface({ drafts: true }); await settle();
    own.list.children[0].querySelectorAll('button')[1].dispatchEvent(new Event('click')); await settle();
    assert.equal(own.list.children.length, 0); assert.equal(own.badge.hidden, true); assert.equal(own.counter.textContent, '0');
    assert.equal(own.mobileBadge.hidden, true); assert.equal(own.mobileLink.attributes['aria-label'], 'Draft Reports');
});

test('receipt reconciliation on a reopened form removes its draft and a pagehide save cannot recreate it', async () => {
    const store = createCivilianDraftStore(1);
    await store.put({ submissionId: id, fields: { description: 'Previously attempted report' }, attempted: true,
        createdAt: '2026-11-06T01:00:00Z', updatedAt: '2026-11-06T01:00:00Z' });
    const originalFetch = globalThis.fetch;
    let requests = 0;
    globalThis.fetch = async (_url, options) => {
        requests++; assert.equal(options.method, 'GET');
        return new Response(JSON.stringify({ received: true, redirect_url: 'https://app.test/reports/12/success' }), { status: 200 });
    };
    try {
        const reopened = surface({ form: true, draftId: id }); await settle();
        assert.equal(reopened.destinations[0], 'https://app.test/reports/12/success');
        reopened.fields.description.value = 'Old form content';
        reopened.win.dispatchEvent(new Event('pagehide')); await settle();
        assert.equal((await store.list()).length, 0); assert.equal(reopened.badge.hidden, true); assert.equal(requests, 1);
    } finally { globalThis.fetch = originalFetch; }
});
