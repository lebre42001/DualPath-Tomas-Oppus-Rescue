import test from 'node:test';
import assert from 'node:assert/strict';
globalThis.document = { readyState: 'loading', addEventListener() {} };
const { initializeAdminSystemLog } = await import('../../resources/js/admin-system-log.js');

function fixture({ nextDay = '2026-11-07', response = {}, wait } = {}) {
    class Node extends EventTarget {
        childNodes = []; textContent = ''; dataset = {}; writes = 0;
        contains() { return false; }
        replaceChildren(...items) { this.childNodes = items; this.writes++; }
    }
    const doc = new EventTarget(), win = new EventTarget(), root = new Node(), form = new Node();
    root.dataset = { dailyView: 'true', logDay: '2026-11-07' };
    const names = ['.admin-log-table tbody', '[data-admin-log-pagination]', '.admin-duty-list', '[data-admin-log-status]'];
    const nodes = Object.fromEntries(names.map(name => [name, new Node()]));
    root.querySelector = selector => selector === '[data-admin-log-filters]' ? form : nodes[selector];
    const updated = new Node(); updated.dataset.logDay = nextDay;
    updated.querySelector = selector => ({ childNodes: [{ value: `updated ${selector}` }] });
    doc.hidden = false; doc.importNode = node => ({ ...node });
    const assignments = [], requests = [];
    win.location = { href: 'https://app.test/admin/system-log?category=report&page=2', assign: value => assignments.push(value) };
    win.DOMParser = class { parseFromString() { return { querySelector: () => updated }; } };
    win.setInterval = () => 1; win.clearInterval = () => {};
    const fetchImpl = async (url, options) => { requests.push({ url, options }); if (wait) await wait; return { ok: true, status: 200, redirected: false, text: async () => 'same-origin log HTML', ...response }; };
    const controller = initializeAdminSystemLog(root, { document: doc, window: win, fetchImpl });
    return { controller, root, doc, win, form, nodes, assignments, requests };
}

test('live log refresh fetches private uncached HTML and updates rows, duty, pagination and count without changing filters', async () => {
    const f = fixture(); await f.controller.refresh();
    assert.equal(f.requests.length, 1);
    assert.equal(f.requests[0].options.credentials, 'same-origin'); assert.equal(f.requests[0].options.cache, 'no-store');
    assert.equal(f.requests[0].options.headers['X-Requested-With'], 'XMLHttpRequest');
    assert(Object.values(f.nodes).every(node => node.writes === 1)); assert.equal(f.assignments.length, 0);
});

test('editing filters, backgrounding or leaving the page pauses live updates', async () => {
    const dirty = fixture(); dirty.form.dispatchEvent(new Event('input')); await dirty.controller.refresh();
    assert.equal(dirty.requests.length, 0); assert.match(dirty.nodes['[data-admin-log-status]'].textContent, /paused/);
    const hidden = fixture(); hidden.doc.hidden = true; await hidden.controller.refresh(); assert.equal(hidden.requests.length, 0);
    hidden.doc.hidden = false; hidden.win.dispatchEvent(new Event('pagehide')); await hidden.controller.refresh(); assert.equal(hidden.requests.length, 0);
});

test('Philippine midnight starts the new daily view at page one while retaining the selected category', async () => {
    const f = fixture({ nextDay: '2026-11-08' }); await f.controller.refresh();
    assert.equal(f.assignments.length, 1);
    const next = new URL(f.assignments[0]); assert.equal(next.searchParams.has('page'), false); assert.equal(next.searchParams.get('category'), 'report');
    assert(Object.values(f.nodes).every(node => node.writes === 0));
    const historical = fixture({ nextDay: '2026-11-08' }); historical.root.dataset.dailyView = 'false'; await historical.controller.refresh();
    assert.equal(historical.assignments.length, 0, 'a historical search must never be reset to today');
});

test('expired authentication stops refresh without inserting a login document into the activity table', async () => {
    const f = fixture({ response: { status: 401, ok: false } }); await f.controller.refresh(); await f.controller.refresh();
    assert.equal(f.requests.length, 1); assert(Object.values(f.nodes).every(node => node.writes === 0));
    assert.match(f.nodes['[data-admin-log-status]'].textContent, /Sign in again/);
});

test('an in-flight refresh cannot replace records after the user starts editing', async () => {
    let finish; const wait = new Promise(resolve => { finish = resolve; });
    const f = fixture({ wait }); const pending = f.controller.refresh();
    f.form.dispatchEvent(new Event('input')); finish(); await pending;
    assert(Object.values(f.nodes).every(node => node.writes === 0));
});
