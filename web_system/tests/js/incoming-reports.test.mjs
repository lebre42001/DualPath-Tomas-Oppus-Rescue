import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import { IncomingReportState } from '../../resources/js/incoming-reports.js';
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
