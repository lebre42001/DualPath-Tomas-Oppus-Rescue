import test from 'node:test';
import assert from 'node:assert/strict';
import { initializeReportCameraPermission } from '../../resources/js/report-camera-permission.js';

const settle = () => new Promise(resolve => setImmediate(resolve));

function cameraFixture({ state = 'prompt', permissionApi = true, supported = true } = {}) {
    const window = new EventTarget(); window.isSecureContext = true;
    const document = new EventTarget(); document.hidden = false;
    const button = new EventTarget(), status = {};
    const permission = new EventTarget(); permission.state = state;
    const calls = [], stopped = [];
    const navigator = {
        ...(supported ? { mediaDevices: { getUserMedia: options => new Promise((resolve, reject) => calls.push({ options, resolve, reject })) } } : {}),
        ...(permissionApi ? { permissions: { query: async ({ name }) => {
            assert.equal(name, 'camera'); return permission;
        } } } : {}),
    };
    const root = { querySelector: selector => ({ '[data-report-camera-permission]': button, '[data-report-camera-permission-status]': status })[selector] };
    const access = initializeReportCameraPermission(root, { navigator, window, document });
    const stream = () => ({ getTracks: () => [0, 1].map(id => ({ stop: () => stopped.push(id) })) });
    const change = async state => { permission.state = state; permission.dispatchEvent(new Event('change')); await settle(); };
    return { access, window, document, navigator, button, status, permission, calls, stopped, stream, change };
}

test('camera permissions are inspected without opening the camera or requesting microphone access', async () => {
    for (const state of ['prompt', 'granted', 'denied']) {
        const f = cameraFixture({ state }); await settle();
        assert.equal(f.calls.length, 0);
        if (state === 'granted') assert.match(f.status.textContent, /allowed.*only when/i);
        else if (state === 'denied') assert.match(f.status.textContent, /blocked.*browser settings/i);
        else assert.match(f.status.textContent, /Tap Allow camera/i);
        f.access.stop();
    }
});

test('an explicit camera check requests video only and closes every track immediately after success', async () => {
    const f = cameraFixture(); await settle();
    const pending = f.access.request();
    assert.equal(f.button.disabled, true);
    assert.deepEqual(f.calls[0].options, { video: true, audio: false });
    f.calls[0].resolve(f.stream());
    assert.equal(await pending, true);
    assert.deepEqual(f.stopped, [0, 1]);
    assert.equal(f.button.disabled, false);
    assert.match(f.status.textContent, /opens only when you choose Capture Photo/i);
    f.access.stop();
});

test('multiple camera clicks cannot open competing permission checks', async () => {
    const f = cameraFixture(); await settle();
    const pending = f.access.request();
    assert.equal(await f.access.request(), false); assert.equal(f.calls.length, 1);
    f.calls[0].resolve(f.stream()); assert.equal(await pending, true);
    f.access.stop();
});

test('camera denial remains retryable without pretending that access was granted', async () => {
    const f = cameraFixture(); await settle();
    const pending = f.access.request();
    f.calls[0].reject(Object.assign(new Error('Denied'), { name: 'NotAllowedError' }));
    assert.equal(await pending, false); assert.match(f.status.textContent, /not allowed.*browser site settings/i);
    assert.equal(f.button.disabled, false);
    const retry = f.access.request(); f.calls[1].resolve(f.stream());
    assert.equal(await retry, true); assert.deepEqual(f.stopped, [0, 1]);
    f.access.stop();
});

test('a late camera stream is closed and cannot restore permission success after revoke, hide or disposal', async () => {
    for (const invalidate of [
        async f => f.change('denied'),
        async f => { f.document.hidden = true; f.document.dispatchEvent(new Event('visibilitychange')); },
        async f => f.window.dispatchEvent(new Event('pagehide')),
        async f => f.access.stop(),
    ]) {
        const f = cameraFixture({ state: 'granted' }); await settle();
        const pending = f.access.request(); await invalidate(f);
        f.calls[0].resolve(f.stream());
        assert.equal(await pending, false); assert.deepEqual(f.stopped, [0, 1]);
        assert.equal(f.button.disabled, false);
        f.access.stop();
    }
});

test('unsupported camera permission queries preserve the explicit camera request path', async () => {
    const f = cameraFixture({ permissionApi: false }); await settle();
    assert.equal(f.calls.length, 0);
    const pending = f.access.request(); f.calls[0].resolve(f.stream());
    assert.equal(await pending, true); assert.deepEqual(f.stopped, [0, 1]);
    f.access.stop();
});

test('insecure pages and unavailable cameras display actionable errors without opening a device', async () => {
    for (const options of [{ supported: false }, {}]) {
        const f = cameraFixture(options); await settle();
        if (options.supported !== false) f.window.isSecureContext = false;
        assert.equal(await f.access.request(), false);
        assert.equal(f.calls.length, 0); assert.match(f.status.textContent, /HTTPS.*supported browser/i);
        assert.equal(f.button.disabled, false);
        f.access.stop();
    }
});

test('disposal removes click and visibility listeners so navigation cannot reopen the camera', async () => {
    const f = cameraFixture(); await settle(); f.access.stop();
    f.button.dispatchEvent(new Event('click'));
    f.document.dispatchEvent(new Event('visibilitychange'));
    await f.change('granted');
    assert.equal(f.calls.length, 0);
    assert.equal(await f.access.request(), false);
});
