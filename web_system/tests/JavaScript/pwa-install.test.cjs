const { test } = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { join } = require('node:path');
const vm = require('node:vm');

const source = readFileSync(join(__dirname, '../../public/pwa-install.js'), 'utf8');

function setup({ secure = true, register } = {}) {
    const registrations = [];
    const window = Object.assign(new EventTarget(), {
        isSecureContext: secure,
        location: { href: 'https://rescue.test/' },
    });
    const navigator = { serviceWorker: { register: register || ((...args) => {
        registrations.push(args);
        return Promise.resolve({});
    }) } };
    const context = vm.createContext({ window, navigator, URL, CustomEvent,
        document: { currentScript: { src: 'https://rescue.test/pwa-install.js?v=release123' } },
    });
    vm.runInContext(source, context);
    return { window, registrations, api: window.RescueInstall };
}

function offer(window, { outcome = 'accepted', prompt = () => Promise.resolve(), userChoice } = {}) {
    const event = new Event('beforeinstallprompt', { cancelable: true });
    Object.assign(event, { prompt, userChoice: userChoice || Promise.resolve({ outcome }) });
    window.dispatchEvent(event);
    assert.equal(event.defaultPrevented, true);
}

test('captures a prompt before the page UI loads and calls it synchronously from a request', async () => {
    const { window, api } = setup();
    let calls = 0;
    offer(window, { prompt: () => { calls++; return Promise.resolve(); } });
    assert.equal(api.available, true);
    const request = api.request();
    assert.equal(calls, 1, 'do not await preparation before opening the browser prompt');
    assert.equal(await request, 'accepted');
    assert.equal(api.available, false);
    assert.equal(await api.request(), 'unavailable');
    assert.equal(calls, 1, 'a browser prompt can only be used once');
});

test('starts worker registration early and reuses the registration request', async () => {
    const { registrations, api } = setup();
    await api.prepare();
    await api.prepare();
    assert.equal(registrations.length, 1);
    assert.equal(registrations[0][0], '/sw.js?v=release123');
    assert.equal(registrations[0][1].scope, '/');
});

test('does not register a service worker on an insecure origin', async () => {
    const { registrations, api } = setup({ secure: false });
    assert.equal(await api.prepare(), null);
    assert.equal(registrations.length, 0);
    assert.equal(await api.request(), 'unavailable');
});

test('reports cancellation and accepts a fresh browser prompt on retry', async () => {
    const { window, api } = setup();
    offer(window, { outcome: 'dismissed' });
    assert.equal(await api.request(), 'dismissed');
    offer(window);
    assert.equal(await api.request(), 'accepted');
});

test('recovers when the browser rejects installation without reusing the failed prompt', async () => {
    const { window, api } = setup();
    offer(window, { prompt: () => Promise.reject(new Error('NotAllowedError')) });
    assert.equal(await api.request(), 'error');
    assert.equal(api.available, false);
    offer(window);
    assert.equal(await api.request(), 'accepted');
});

test('two rapid clicks open only one prompt', async () => {
    const { window, api } = setup();
    let finish;
    let calls = 0;
    offer(window, { prompt: () => { calls++; }, userChoice: new Promise(resolve => { finish = resolve; }) });
    const first = api.request();
    assert.equal(await api.request(), 'busy');
    finish({ outcome: 'accepted' });
    assert.equal(await first, 'accepted');
    assert.equal(calls, 1);
});

test('clears an obsolete prompt when installation finishes from the browser menu', () => {
    const { window, api } = setup();
    offer(window);
    window.dispatchEvent(new Event('appinstalled'));
    assert.equal(api.available, false);
});

test('does not report installation as pending if appinstalled arrives before userChoice', async () => {
    const { window, api } = setup();
    let finish;
    offer(window, { userChoice: new Promise(resolve => { finish = resolve; }) });
    const request = api.request();
    window.dispatchEvent(new Event('appinstalled'));
    finish({ outcome: 'accepted' });
    assert.equal(await request, 'installed');
});

test('a temporary worker-registration failure can be retried', async () => {
    let attempts = 0;
    const { api } = setup({ register: () => ++attempts === 1 ? Promise.reject(new Error('offline')) : Promise.resolve({}) });
    await api.prepare();
    await api.prepare();
    assert.equal(attempts, 2);
});
