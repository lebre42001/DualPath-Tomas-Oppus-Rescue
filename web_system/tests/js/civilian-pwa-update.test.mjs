import test from 'node:test';
import assert from 'node:assert/strict';

test('civilian service worker/version changes notify without reloading until Update app is requested', async () => {
    const win = new EventTarget(), doc = new EventTarget();
    doc.readyState = 'loading'; doc.querySelector = selector => selector === '[data-civilian-updates]' ? {} : null;
    let scheduled = 0, notifications = 0;
    win.setTimeout = () => { scheduled++; return 1; }; win.clearTimeout = () => {};
    win.addEventListener('stitch:application-update', () => notifications++);
    globalThis.document = doc; globalThis.window = win;
    Object.defineProperty(globalThis, 'navigator', { value: {}, configurable: true });
    const { beginAutomaticUpdate } = await import('../../resources/js/pwa.js?civilian');
    beginAutomaticUpdate(); assert.equal(notifications, 1); assert.equal(scheduled, 0);
    win.dispatchEvent(new CustomEvent('stitch:apply-application-update')); assert.equal(scheduled, 1);
});

test('admin automatic application updates retain the existing reload behavior', async () => {
    const win = new EventTarget(), doc = new EventTarget();
    doc.readyState = 'loading'; doc.querySelector = () => null;
    let scheduled = 0;
    win.setTimeout = () => { scheduled++; return 1; }; win.clearTimeout = () => {};
    globalThis.document = doc; globalThis.window = win;
    Object.defineProperty(globalThis, 'navigator', { value: {}, configurable: true });
    const { beginAutomaticUpdate } = await import('../../resources/js/pwa.js?admin');
    beginAutomaticUpdate(); assert.equal(scheduled, 1);
});
