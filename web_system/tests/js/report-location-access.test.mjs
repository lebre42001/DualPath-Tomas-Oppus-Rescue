import test from 'node:test';
import assert from 'node:assert/strict';
import { createReportLocationAccess, isFreshReportPosition } from '../../resources/js/report-location-access.js';

const settle = () => new Promise(resolve => setImmediate(resolve));

function locationFixture({ permission = 'granted', automatic = true, permissionApi = true, supported = true } = {}) {
    const window = new EventTarget();
    window.isSecureContext = true;
    const document = new EventTarget();
    document.hidden = false;
    document.visibilityState = 'visible';
    const status = new EventTarget();
    status.state = permission;
    const calls = [], positions = [], errors = [], busy = [], permissions = [], clears = [];
    let time = 1794000000000;
    const navigator = {
        ...(supported ? { geolocation: {
            getCurrentPosition(success, failure, options) { calls.push({ success, failure, options }); },
            watchPosition() { throw new Error('Reporting must not start background location tracking'); },
        } } : {}),
        ...(permissionApi ? { permissions: { query: async ({ name }) => {
            assert.equal(name, 'geolocation');
            return status;
        } } } : {}),
    };
    const access = createReportLocationAccess({ navigator, window, document, automatic,
        now: () => time,
        onClear: (...args) => clears.push(args),
        onBusy: (...args) => busy.push(args),
        onPosition: (...args) => positions.push(args),
        onError: (...args) => errors.push(args),
        onPermission: (...args) => permissions.push(args),
    });
    const fix = (overrides = {}) => ({
        coords: { latitude: 10.2783, longitude: 125.0188, accuracy: 8, ...overrides.coords },
        timestamp: time,
        ...overrides,
    });
    const show = async (hidden) => {
        document.hidden = hidden;
        document.visibilityState = hidden ? 'hidden' : 'visible';
        document.dispatchEvent(new Event('visibilitychange'));
        await settle();
    };
    const change = async (state) => {
        status.state = state;
        status.dispatchEvent(new Event('change'));
        await settle();
    };
    return { access, navigator, window, document, status, calls, positions, errors, busy, permissions, clears,
        fix, show, change, advance: (ms) => { time += ms; } };
}

test('previously granted location starts one fresh high-accuracy check and never tracks in the background', async () => {
    const f = locationFixture();
    f.access.start(); await settle();
    assert.equal(f.calls.length, 1);
    assert.deepEqual(f.calls[0].options, { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 });
    f.calls[0].success(f.fix()); await settle();
    assert.equal(f.positions.length, 1);
    assert.equal(f.errors.length, 0);
    f.access.stop();
});

test('prompt, denied, unknown and unsupported browsers never trigger ambient location prompts', async () => {
    for (const options of [{ permission: 'prompt' }, { permission: 'denied' }, { permission: 'unknown' },
        { permissionApi: false }, { supported: false }, { automatic: false }]) {
        const f = locationFixture(options);
        f.access.start(); await settle();
        assert.equal(f.calls.length, 0, JSON.stringify(options));
        f.access.stop();
    }
});

test('manual location still works without Permissions API and repeated clicks share the in-flight check', async () => {
    const f = locationFixture({ permissionApi: false });
    f.access.start(); await settle();
    const first = f.access.request(), second = f.access.request();
    assert.equal(f.calls.length, 1);
    f.calls[0].success(f.fix());
    assert.deepEqual(await Promise.all([first, second]), [true, true]);
    assert.equal(f.positions.length, 1);
    f.access.stop();
});

test('each later request obtains a fresh fix, including a second report submission check', async () => {
    const f = locationFixture({ automatic: false });
    f.access.start(); await settle();
    for (let index = 0; index < 3; index++) {
        const pending = f.access.request();
        assert.equal(f.calls.length, index + 1, 'A previous successful fix is not reused as a new device check');
        assert.equal(f.calls[index].options.maximumAge, 0);
        f.advance(10000);
        f.calls[index].success(f.fix());
        assert.equal(await pending, true);
    }
    assert.equal(f.positions.length, 3);
    f.access.stop();
});

test('reset for a restored draft clears GPS and invalidates a delayed previous fix', async () => {
    const f = locationFixture({ automatic: false });
    f.access.start(); await settle();
    const old = f.access.request();
    f.access.reset();
    assert.equal(await old, false);
    f.calls[0].success(f.fix()); await settle();
    assert.equal(f.positions.length, 0);
    assert(f.clears.length > 0);
    const refreshed = f.access.request();
    f.calls[1].success(f.fix({ coords: { latitude: 10.3, longitude: 125.1, accuracy: 6 } }));
    assert.equal(await refreshed, true);
    assert.equal(f.positions.length, 1);
    f.access.stop();
});

test('permission revocation immediately invalidates GPS and a late success cannot restore it', async () => {
    const f = locationFixture({ automatic: false });
    f.access.start(); await settle();
    const pending = f.access.request();
    await f.change('denied');
    assert.equal(await pending, false);
    f.calls[0].success(f.fix()); await settle();
    assert.equal(f.positions.length, 0);
    assert(f.clears.length > 0);
    assert.equal(f.calls.length, 1, 'Revocation does not request another fix');
    f.access.stop();
});

test('granting location while the report is open starts a fresh check after explicit browser approval', async () => {
    const f = locationFixture({ permission: 'prompt' });
    f.access.start(); await settle(); assert.equal(f.calls.length, 0);
    await f.change('granted'); assert.equal(f.calls.length, 1);
    f.calls[0].success(f.fix()); await settle(); assert.equal(f.positions.length, 1);
    f.access.stop();
});

test('backgrounding clears pending GPS and returning to the report checks the granted device again', async () => {
    const f = locationFixture();
    f.access.start(); await settle(); assert.equal(f.calls.length, 1);
    await f.show(true);
    f.calls[0].success(f.fix()); await settle(); assert.equal(f.positions.length, 0);
    await f.show(false); assert.equal(f.calls.length, 2);
    f.calls[1].success(f.fix()); await settle(); assert.equal(f.positions.length, 1);
    f.access.stop();
});

test('back-forward cache restoration refreshes GPS instead of trusting the previous session coordinates', async () => {
    const f = locationFixture();
    f.access.start(); await settle();
    f.calls[0].success(f.fix()); await settle();
    const restored = new Event('pageshow');
    Object.defineProperty(restored, 'persisted', { value: true });
    f.window.dispatchEvent(restored); await settle();
    assert.equal(f.calls.length, 2);
    f.calls[1].success(f.fix()); await settle(); assert.equal(f.positions.length, 2);
    f.access.stop();
});

test('invalid, stale and out-of-range device fixes never mark location as ready', async () => {
    const f = locationFixture({ automatic: false });
    f.access.start(); await settle();
    const invalid = [
        null,
        f.fix({ coords: { latitude: NaN, longitude: 125, accuracy: 8 } }),
        f.fix({ coords: { latitude: 91, longitude: 125, accuracy: 8 } }),
        f.fix({ coords: { latitude: 10, longitude: -181, accuracy: 8 } }),
        f.fix({ timestamp: 1794000000000 - 31000 }),
        f.fix({ timestamp: undefined }),
    ];
    for (const position of invalid) {
        const pending = f.access.request();
        f.calls.at(-1).success(position);
        assert.equal(await pending, false);
    }
    assert.equal(f.positions.length, 0);
    assert.equal(f.errors.length, invalid.length);
    f.access.stop();
});

test('device location errors leave reporting unready but allow a later successful retry', async () => {
    const f = locationFixture({ automatic: false });
    f.access.start(); await settle();
    for (const code of [1, 2, 3]) {
        const pending = f.access.request();
        f.calls.at(-1).failure({ code, message: 'Synthetic location failure' });
        assert.equal(await pending, false);
    }
    assert.equal(f.positions.length, 0);
    assert.equal(f.errors.length, 3);
    const retry = f.access.request(); f.calls.at(-1).success(f.fix());
    assert.equal(await retry, true); assert.equal(f.positions.length, 1);
    f.access.stop();
});

test('disposing cancels pending work and removes automatic foreground/permission listeners', async () => {
    const f = locationFixture();
    f.access.start(); await settle();
    const pending = f.access.request();
    f.access.stop(); assert.equal(await pending, false);
    f.calls[0].success(f.fix()); await settle(); assert.equal(f.positions.length, 0);
    await f.change('prompt'); await f.change('granted');
    await f.show(true); await f.show(false);
    const restored = new Event('pageshow'); Object.defineProperty(restored, 'persisted', { value: true });
    f.window.dispatchEvent(restored); await settle();
    assert.equal(f.calls.length, 1);
});

test('a pending permission inspection does not cancel the user-initiated browser Allow prompt', async () => {
    const f = locationFixture({ permission: 'prompt' });
    let finishInspection;
    f.navigator.permissions.query = () => new Promise(resolve => { finishInspection = resolve; });
    const started = f.access.start();
    const manual = f.access.request();
    finishInspection(f.status); await started;
    assert.equal(f.calls.length, 1);
    f.calls[0].success(f.fix());
    assert.equal(await manual, true);
    assert.equal(f.positions.length, 1);
    f.access.stop();
});

test('permission inspection failure preserves explicit manual access without an automatic prompt', async () => {
    const f = locationFixture();
    f.navigator.permissions.query = async () => { throw new TypeError('Permission name not supported'); };
    assert.equal(await f.access.start(), false); assert.equal(f.calls.length, 0);
    const manual = f.access.request(); f.calls[0].success(f.fix());
    assert.equal(await manual, true); assert.equal(f.positions.length, 1);
    f.access.stop();
});

test('an obsolete permission response cannot restart GPS after draft reset or page hide', async () => {
    for (const invalidate of [f => f.access.reset(), f => f.window.dispatchEvent(new Event('pagehide'))]) {
        const f = locationFixture();
        let finishInspection;
        f.navigator.permissions.query = () => new Promise(resolve => { finishInspection = resolve; });
        const started = f.access.start();
        invalidate(f); finishInspection(f.status);
        assert.equal(await started, false); assert.equal(f.calls.length, 0);
        f.access.stop();
    }
});

test('freshness accepts real zero coordinates but rejects strings, stale fixes and distant future timestamps', () => {
    const now = 1794000000000;
    assert.equal(isFreshReportPosition({ coords: { latitude: 0, longitude: 0 }, timestamp: now }, now), true);
    assert.equal(isFreshReportPosition({ coords: { latitude: -90, longitude: 180 }, timestamp: now - 30000 }, now), true);
    for (const position of [
        { coords: { latitude: '10', longitude: 125 }, timestamp: now },
        { coords: { latitude: 10, longitude: Infinity }, timestamp: now },
        { coords: { latitude: 10, longitude: 125 }, timestamp: now + 30001 },
        { coords: { latitude: 10, longitude: 125 }, timestamp: now - 30001 },
        { coords: { latitude: 10, longitude: 125 }, timestamp: `${now}` },
    ]) assert.equal(isFreshReportPosition(position, now), false);
});
