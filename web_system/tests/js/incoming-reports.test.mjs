import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import { IncomingReportState } from '../../resources/js/incoming-reports.js';
import { ReportAlarm } from '../../resources/js/report-alarm.js';
const report = (id, severity = 'Minor') => ({ action: 'created', report: { id, severity, transmission_type: 'lora', lora_sender_id: `NODE_${id}` } });
test('all severities notify; Echo and polling duplicates notify only once', () => {
    const state = new IncomingReportState({ cursor: 0 });
    assert.equal(state.accept(report(4)), true);
    assert.equal(state.accept(report(3, 'Serious')), true);
    assert.equal(state.accept(report(4)), false);
    assert.equal(state.unread.size, 2);
    assert.equal(state.cursor, 0, 'Echo cannot advance the poll cursor past an unseen arrival');
});
test('marking arrivals seen survives reload and never changes rescue status', () => {
    const state = new IncomingReportState(); state.accept(report(3)); state.unread.clear();
    const restored = new IncomingReportState(state.snapshot());
    assert.equal(restored.accept(report(3)), false); assert.equal(restored.unread.size, 0);
});
test('initial unreviewed history is visible without treating status updates as new arrivals', () => {
    const state = new IncomingReportState({ cursor: 10 });
    assert.equal(state.accept(report(3), true), true);
    assert.equal(state.accept(report(5)), false);
    assert.equal(state.accept({ action: 'updated', report: { id: 11 } }), false);
});
test('malformed optional saved data does not disable the feed', () => {
    const state = new IncomingReportState({ cursor: -1, seen: {}, unread: [null] });
    assert.equal(state.cursor, null); assert.equal(state.accept(report(2)), true);
});
const echoSource = readFileSync(new URL('../../resources/js/echo.js', import.meta.url), 'utf8')
    .replace(/^import .*;\r?\n/gm, '').replaceAll('import.meta.env', '({})');
test('missing realtime configuration cannot prevent the polling module from loading', () => {
    const context = { window: {}, Pusher: {}, Echo: function () { throw new Error('must not construct'); } };
    assert.doesNotThrow(() => vm.runInNewContext(echoSource, context));
    assert.equal(context.window.Echo, null);
});
test('a realtime constructor failure is isolated from the rest of the application', () => {
    const context = { window: { __STITCH_RUNTIME__: { reverb: { key: 'test' } } }, Pusher: {},
        Echo: function () { throw new Error('invalid config'); }, console: { warn() {} } };
    assert.doesNotThrow(() => vm.runInNewContext(echoSource, context));
    assert.equal(context.window.Echo, null);
});

const incomingSource = readFileSync(new URL('../../resources/js/incoming-reports.js', import.meta.url), 'utf8').replace(/^import .*;\r?\n/gm, '').replaceAll('export ', '');
function pageFixture(saved = new Map(), userId = '701', rejectAudio = false) {
    class Node extends EventTarget {
        dataset = {}; hidden = false; textContent = ''; children = []; classList = { toggle() {} };
        hasAttribute(name) { return this.attributes?.includes(name) ?? false; }
        closest() { return null; }
        append(...items) { this.children.push(...items); }
        replaceChildren(...items) { this.children = items; }
    }
    const doc = new EventTarget(), win = new EventTarget(), audio = [], sound = new Node(), mute = new Node(), stop = new Node(), toolbar = new Node(), soundStatus = new Node();
    const list = new Node(), feedStatus = new Node(), clear = new Node(), banner = new Node(), count = new Node(), panel = new Node();
    panel.dataset = { userId, alarmUrl: '/sounds/admin-report-alarm-993.wav', feedUrl: '/feed' };
    panel.querySelector = selector => ({ '[data-alert-list]': list, '[data-incoming-status]': feedStatus, '[data-incoming-clear]': clear })[selector];
    banner.querySelector = () => new Node();
    doc.querySelector = selector => selector === '[data-incoming-reports]' ? panel : selector === '[data-incoming-banner]' ? banner : null;
    doc.querySelectorAll = selector => ({ '[data-incoming-sound]': [sound], '[data-incoming-sound-stop]': [stop], '[data-incoming-sound-mute]': [mute], '.incoming-alarm-toolbar': [toolbar], '[data-incoming-sound-status]': [soundStatus], '[data-alert-count]': [count] })[selector] ?? [];
    doc.createElement = () => new Node();
    win.location = { origin: 'https://app.test' };
    win.setTimeout = setTimeout; win.clearTimeout = clearTimeout; win.setInterval = () => 1; win.clearInterval = () => {};
    win.Audio = class extends EventTarget {
        starts = []; pauses = 0; currentTime = 0;
        constructor(url) { super(); this.url = url; audio.push(this); }
        play() { this.starts.push({ muted: this.muted, loop: this.loop }); return rejectAudio ? Promise.reject(new Error('Blocked')) : Promise.resolve(); }
        pause() { this.pauses++; }
    };
    const context = { document: doc, window: win, ReportAlarm, URL, AbortController,
        localStorage: { getItem: key => saved.get(key) ?? null, setItem: (key, value) => saved.set(key, value) },
        fetch: async () => ({ status: 200, ok: true, json: async () => ({ cursor: 0, initial: true, items: [] }) }),
    };
    vm.runInNewContext(incomingSource, context);
    context.initializeIncomingReports(() => {});
    return { sound, mute, stop, soundStatus, toolbar, audio, saved, win,
        receive: item => context.receiveIncomingReport(item), close: () => win.dispatchEvent(new Event('pagehide')) };
}
const settleAudio = () => new Promise(resolve => setImmediate(resolve));

test('Sound on survives full navigation and restores the new WAV silently without another Enable step', async () => {
    const saved = new Map(), first = pageFixture(saved);
    await settleAudio();
    assert.equal(first.sound.textContent, 'Test sound');
    assert.equal(first.audio[0].url, '/sounds/admin-report-alarm-993.wav');
    assert(first.audio[0].starts.every(start => start.muted), 'restoration never emits a sample');
    first.close();
    const next = pageFixture(saved); await settleAudio();
    assert.equal(next.sound.textContent, 'Test sound');
    assert.match(next.soundStatus.textContent, /Sound ready/);
    next.receive(report(14)); await settleAudio();
    assert.equal(next.audio[0].starts.filter(start => !start.muted).length, 1);
    next.close();
});

test('Mute persists across pages and accounts remain independent; re-enabling primes without a stale alarm', async () => {
    const saved = new Map(), first = pageFixture(saved); await settleAudio();
    first.mute.dispatchEvent(new Event('click')); first.close();
    const next = pageFixture(saved); await settleAudio();
    assert.equal(next.sound.textContent, 'Enable sound'); assert.equal(next.audio[0].starts.length, 0);
    assert.match(next.soundStatus.textContent, /muted/);
    next.sound.dispatchEvent(new Event('click')); await settleAudio();
    assert.equal(next.sound.textContent, 'Test sound'); assert(next.audio[0].starts.every(start => start.muted));
    next.close();
    const anotherOfficer = pageFixture(saved, '702'); await settleAudio();
    assert.equal(anotherOfficer.sound.textContent, 'Test sound'); anotherOfficer.close();
});

test('a browser rejection retains Sound on and offers an explicit Test instead of pretending the preference reset', async () => {
    const page = pageFixture(new Map(), '703', true); await settleAudio();
    assert.equal(page.sound.textContent, 'Test sound');
    assert.match(page.soundStatus.textContent, /Sound is on and remembered/);
    assert.match(page.soundStatus.textContent, /browser blocked/);
    assert.equal(JSON.parse(page.saved.get('aksyr-report-alarm-703')).enabled, true);
    page.sound.dispatchEvent(new Event('click')); await settleAudio();
    assert(page.audio[0].starts.some(start => !start.muted), 'explicit test retries audible playback within the gesture');
    page.close();
});
