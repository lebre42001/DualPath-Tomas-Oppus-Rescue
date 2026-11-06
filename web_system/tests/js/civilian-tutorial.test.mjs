import test from 'node:test';
import assert from 'node:assert/strict';
import { createTutorialProgress, tutorialPlacement } from '../../resources/js/civilian-tutorial-state.js';

test('tutorial Skip and Done stay account-scoped, active steps resume, corrupt/denied storage is recoverable', () => {
    const items = new Map(), storage = { getItem: key => items.get(key), setItem: (key, value) => items.set(key, value) };
    const first = createTutorialProgress(1, { storage }); assert.equal(first.shouldStart(), true);
    first.save('active', 7); assert.equal(createTutorialProgress(1, { storage }).value().step, 7);
    first.save('skipped', 7); assert.equal(createTutorialProgress(1, { storage }).shouldStart(), false);
    assert.equal(createTutorialProgress(2, { storage }).shouldStart(), true);
    first.save('done', 15); assert.equal(createTutorialProgress(1, { storage }).shouldStart(), false);
    for (const value of ['bad json', '[]', '{"status":"active","step":-1}', '{"status":"forged","step":0}']) assert.equal(createTutorialProgress(3, { storage: { getItem: () => value } }).shouldStart(), true);
    const denied = createTutorialProgress(1, { storage: { getItem() { throw new Error('Denied'); }, setItem() { throw new Error('Denied'); } } });
    denied.save('skipped', 0); assert.equal(denied.shouldStart(), false);
});

test('tutorial callouts and arrows stay inside phone/desktop and visual-viewport bounds', () => {
    for (const viewport of [{ width: 320, height: 568 }, { width: 390, height: 844 }, { width: 1920, height: 1080 }, { left: 30, top: 80, width: 320, height: 480 }]) {
        for (const target of [null, { left: 10, right: 70, top: 20, bottom: 60 }, { left: 230, right: 310, top: viewport.height - 110, bottom: viewport.height - 40 }]) {
            const card = { width: Math.min(370, viewport.width - 24), height: 240 };
            const p = tutorialPlacement(target, card, viewport);
            assert(p.left >= (viewport.left ?? 0) + 12); assert(p.top >= (viewport.top ?? 0) + 12);
            assert(p.left + card.width <= (viewport.left ?? 0) + viewport.width - 12);
            assert(p.top + card.height <= (viewport.top ?? 0) + viewport.height - 12);
        }
    }
    assert.equal(tutorialPlacement({ left: 12, right: 80, top: 450, bottom: 510 }, { width: 296, height: 200 }, { width: 320, height: 568 }).placement, 'above');
});
