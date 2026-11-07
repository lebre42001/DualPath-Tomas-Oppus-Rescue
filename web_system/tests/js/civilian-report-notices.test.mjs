import test from 'node:test';
import assert from 'node:assert/strict';
import {
    createCivilianReportNoticeState,
    safeCivilianReportUrl,
    formatCivilianNoticeDate,
    initializeCivilianReportNotices,
} from '../../resources/js/civilian-report-notices.js';

const memory = () => {
    const values = new Map();
    return { getItem: key => values.get(key) ?? null, setItem: (key, value) => values.set(key, value) };
};
const reportEvent = (id, overrides = {}) => ({
    id, kind: 'report_status', title: 'Report acknowledged',
    message: 'Your road-accident report was acknowledged.',
    occurred_at: '2026-11-07T09:12:00+08:00', url: '/reports/42', ...overrides,
});

test('unseen persistent report updates are grouped once, with the newest event and stable numeric IDs', () => {
    const state = createCivilianReportNoticeState('101', { storage: memory() });
    const result = state.observe([reportEvent(4), reportEvent(6), reportEvent(5), reportEvent(6)]);
    assert.equal(result.count, 3);
    assert.equal(result.latest.id, 6);
    assert.deepEqual([...result.ids].sort((a, b) => a - b), [4, 5, 6]);
    assert.equal(state.observe([reportEvent(6), reportEvent(4)]), null);
    assert.equal(state.observe([]), null);
    assert.equal(state.observe([reportEvent(7)]).latest.id, 7);
});

test('toast deduplication survives reopening and remains isolated when accounts switch', async () => {
    const storage = memory();
    const first = createCivilianReportNoticeState('102', { storage });
    assert.equal(first.observe([reportEvent(8)]).count, 1);
    // A fresh module stands in for a full page reload with no in-memory map.
    const { createCivilianReportNoticeState: createReopened } = await import('../../resources/js/civilian-report-notices.js?notice-reopened');
    const reopened = createReopened('102', { storage });
    assert.equal(reopened.observe([reportEvent(8)]), null);
    const anotherAccount = createCivilianReportNoticeState('103', { storage });
    assert.equal(anotherAccount.observe([reportEvent(8)]).count, 1);
    assert.notEqual(first.key, anotherAccount.key);
    assert.equal(reopened.observe([reportEvent(9)]).count, 1);
});

test('tabs reread durable seen state before notifying so later polls do not repeat another tab alert', async () => {
    const storage = memory();
    // Separate module instances model separate tabs: they cannot share the
    // in-memory watermark and must coordinate through browser storage.
    const { createCivilianReportNoticeState: createTabA } = await import('../../resources/js/civilian-report-notices.js?notice-tab-a');
    const { createCivilianReportNoticeState: createTabB } = await import('../../resources/js/civilian-report-notices.js?notice-tab-b');
    const tabA = createTabA('104', { storage });
    const tabB = createTabB('104', { storage });
    assert.equal(tabA.observe([reportEvent(20)]).count, 1);
    assert.equal(tabB.observe([reportEvent(20)]), null);
    assert.equal(tabB.observe([reportEvent(21)]).count, 1);
    assert.equal(tabA.observe([reportEvent(20), reportEvent(21)]), null);
    assert.equal(tabA.observe([reportEvent(22)]).count, 1);
});

test('corrupted local seen data recovers without blocking future notices or repeating the same poll', () => {
    for (const [index, corrupt] of ['bad json', 'null', '[]', '"bad"', '{"latest":"oops"}', '-1'].entries()) {
        const storage = memory();
        const state = createCivilianReportNoticeState(String(110 + index), { storage });
        storage.setItem(state.key, corrupt);
        assert.equal(state.observe([reportEvent(30)]).count, 1, corrupt);
        assert.equal(state.observe([reportEvent(30)]), null, corrupt);
        assert.equal(createCivilianReportNoticeState(String(110 + index), { storage }).observe([reportEvent(30)]), null, corrupt);
    }
});

test('denied or full local storage keeps notices usable with in-memory same-page deduplication', () => {
    for (const [index, storage] of [null,
        { getItem() { throw new Error('Denied'); }, setItem() { throw new Error('Denied'); } },
        { getItem: () => null, setItem() { throw new Error('Quota'); } },
    ].entries()) {
        const state = createCivilianReportNoticeState(String(120 + index), { storage });
        assert.equal(state.observe([reportEvent(40)]).count, 1);
        assert.equal(state.persistent(), false);
        assert.equal(state.observe([reportEvent(40)]), null);
        assert.equal(state.observe([reportEvent(41)]).count, 1);
    }
});

test('profile-disabled alerts consume seen IDs without changing the server unread notifications', () => {
    const state = createCivilianReportNoticeState('130', { storage: memory() });
    const events = [reportEvent(50)];
    assert.equal(state.observe(events, { alertsEnabled: false }), null);
    assert.deepEqual(events, [reportEvent(50)]);
    assert.equal(state.observe(events, { alertsEnabled: true }), null);
    assert.equal(state.observe([reportEvent(51)], { alertsEnabled: true }).count, 1);
});

test('app-version and initial submission notices cannot masquerade as responder report updates', () => {
    const state = createCivilianReportNoticeState('131', { storage: memory() });
    assert.equal(state.observe([
        reportEvent(100, { kind: 'app_update_available' }),
        reportEvent(101, { kind: 'app_update_applied' }),
        reportEvent(102, { kind: 'report_submitted' }),
        reportEvent(103, { kind: 'report_submission' }),
        reportEvent(104, { kind: 'login' }),
        reportEvent(105, { kind: null }),
    ]), null);
    assert.equal(state.observe([reportEvent(60)]).count, 1);
});

test('malformed notification data and nonpositive or unsafe numeric identifiers are ignored', () => {
    const state = createCivilianReportNoticeState('132', { storage: memory() });
    assert.equal(state.observe(null), null);
    assert.equal(state.observe({ notifications: [] }), null);
    assert.equal(state.observe([null, {}, ...[-2, 0, 1.5, NaN, Infinity, Number.MAX_SAFE_INTEGER + 1, 'oops'].map(id => reportEvent(id))]), null);
    assert.equal(state.observe([reportEvent(70)]).count, 1);
});

test('report links are limited to credential-free report detail pages on this origin', () => {
    const origin = 'https://dualpath.bsit4bdevs.tech';
    assert.equal(safeCivilianReportUrl('/reports/42', origin), `${origin}/reports/42`);
    assert.equal(safeCivilianReportUrl(`${origin}/reports/42`, origin), `${origin}/reports/42`);
    for (const unsafe of [
        'javascript:alert(1)', 'data:text/html,<script>alert(1)</script>',
        'https://evil.example/reports/42', '//evil.example/reports/42',
        'https://user:pass@dualpath.bsit4bdevs.tech/reports/42',
        '/logout', '/reports', '/reports/0', '/reports/-1', '/reports/42/edit',
        '/reports/42?next=https://evil.example', '/reports/42#fragment',
        '/reports/%34%32', '', null, {},
    ]) assert.equal(safeCivilianReportUrl(unsafe, origin), null, String(unsafe));
});

test('notice dates identify Philippine local time and malformed dates have a readable fallback', () => {
    assert.match(formatCivilianNoticeDate('2026-11-07T01:12:00Z'), /2026/);
    assert.match(formatCivilianNoticeDate('2026-11-07T01:12:00Z'), /9:12|09:12/);
    for (const value of ['bad date', null, undefined, '']) assert.equal(formatCivilianNoticeDate(value), 'Time unavailable');
});

class Element extends EventTarget {
    hidden = true; dataset = {}; attributes = {}; textContent = ''; htmlWrites = 0;
    set innerHTML(value) { this.htmlWrites++; this.html = value; }
    get innerHTML() { return this.html ?? ''; }
    setAttribute(name, value) { this.attributes[name] = value; }
    removeAttribute(name) { delete this.attributes[name]; }
    focus() { this.focused = true; }
}
let fixtureOwner = 200;
const noticeFixture = () => {
    const root = new Element(); root.dataset.owner = String(fixtureOwner++);
    const names = ['civilian-report-notice', 'civilian-report-notice-announcement', 'civilian-report-notice-title', 'civilian-report-notice-message',
        'civilian-report-notice-count', 'civilian-report-notice-date', 'civilian-report-notice-open', 'civilian-report-notice-dismiss'];
    const elements = Object.fromEntries(names.map(name => [name, new Element()]));
    root.querySelector = selector => elements[selector.slice(6, -1)];
    const doc = new EventTarget(), win = new EventTarget(), opened = [];
    const controller = initializeCivilianReportNotices(root, {
        document: doc, window: win, storage: memory(), onOpen: opener => opened.push(opener),
    });
    return { controller, elements, opened, doc, win };
};

test('a new report event displays an actionable nonblocking notice without moving focus or opening a dialog', () => {
    const { controller, elements, opened, doc, win } = noticeFixture();
    let dialogs = 0, reloads = 0;
    doc.addEventListener('stitch:dialog-open', () => dialogs++);
    win.addEventListener('stitch:apply-application-update', () => reloads++);
    controller.observe({ notifications: [reportEvent(201), reportEvent(202)] });
    assert.equal(elements['civilian-report-notice'].hidden, false);
    assert.equal(elements['civilian-report-notice-title'].textContent, 'Report acknowledged');
    assert.match(elements['civilian-report-notice-count'].textContent, /2 new report updates/);
    assert.match(elements['civilian-report-notice-date'].textContent, /Philippine time/);
    assert.match(elements['civilian-report-notice-announcement'].textContent, /2 new report updates\. Report acknowledged/);
    assert.equal(opened.length, 0); assert.equal(dialogs, 0); assert.equal(reloads, 0);
    assert(Object.values(elements).every(element => !element.focused));
    elements['civilian-report-notice-open'].dispatchEvent(new Event('click'));
    assert.equal(opened.length, 1);
    assert.equal(elements['civilian-report-notice'].hidden, true);
});

test('dismissing a notice does not acknowledge the server event and duplicate polls do not show it again', () => {
    const { controller, elements, opened } = noticeFixture();
    const events = [reportEvent(211)];
    controller.observe({ notifications: events });
    elements['civilian-report-notice-dismiss'].dispatchEvent(new Event('click'));
    assert.equal(elements['civilian-report-notice'].hidden, true);
    assert.equal(opened.length, 0);
    assert.deepEqual(events, [reportEvent(211)]);
    controller.observe({ notifications: events });
    assert.equal(elements['civilian-report-notice'].hidden, true);
    controller.observe({ notifications: [...events, reportEvent(212, { title: 'Report resolved' })] });
    assert.equal(elements['civilian-report-notice'].hidden, false);
    assert.equal(elements['civilian-report-notice-title'].textContent, 'Report resolved');
});

test('a profile setting suppresses active notices, hides an existing banner, and does not replay old alerts when enabled', () => {
    const { controller, elements } = noticeFixture();
    controller.observe({ notifications: [reportEvent(221)] });
    assert.equal(elements['civilian-report-notice'].hidden, false);
    controller.observe({ report_alerts_enabled: false, notifications: [reportEvent(221), reportEvent(222)] });
    assert.equal(elements['civilian-report-notice'].hidden, true);
    controller.observe({ report_alerts_enabled: true, notifications: [reportEvent(221), reportEvent(222)] });
    assert.equal(elements['civilian-report-notice'].hidden, true);
    controller.observe({ report_alerts_enabled: true, notifications: [reportEvent(223)] });
    assert.equal(elements['civilian-report-notice'].hidden, false);
});

test('hostile server event text is treated as text and cannot create markup or a navigation target', () => {
    const { controller, elements } = noticeFixture();
    const title = '<img src=x onerror=alert(1)>', message = '<script>location="https://evil.example"</script>';
    controller.observe({ notifications: [reportEvent(231, { title, message, url: 'javascript:alert(1)' })] });
    assert.equal(elements['civilian-report-notice-title'].textContent, title);
    assert.equal(elements['civilian-report-notice-message'].textContent, message);
    assert(Object.values(elements).every(element => element.htmlWrites === 0));
    assert.equal(elements['civilian-report-notice-open'].attributes.href, undefined);
});

test('pages without notice markup remain safe while consuming seen report events', () => {
    const root = new Element(); root.dataset.owner = '250'; root.querySelector = () => null;
    const controller = initializeCivilianReportNotices(root, { document: {}, window: {}, storage: memory() });
    assert.equal(controller.observe({ notifications: [reportEvent(251)] }).count, 1);
    assert.equal(controller.observe({ notifications: [reportEvent(251)] }), null);
    assert.doesNotThrow(() => controller.dismiss());
});
