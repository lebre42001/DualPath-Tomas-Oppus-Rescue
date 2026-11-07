import test from 'node:test';
import assert from 'node:assert/strict';
import { createTutorialProgress, tutorialPlacement } from '../../resources/js/civilian-tutorial-state.js';

globalThis.document = { readyState: 'loading', addEventListener() {} };
const { CIVILIAN_TUTORIAL_STEPS } = await import('../../resources/js/civilian-tutorial.js');

test('the incident selector is a dedicated safe preview before photo, with six ordered reporting steps', () => {
    assert.equal(CIVILIAN_TUTORIAL_STEPS.length, 17);
    const previews = CIVILIAN_TUTORIAL_STEPS.filter(step => step.demo);
    assert.deepEqual(previews.map(step => step.demo), ['incident', 'photo', 'gps', 'description', 'selfie', 'receipt']);
    assert.deepEqual(previews.map(step => Number(step.title.split('.')[0])), [1, 2, 3, 4, 5, 6]);
    assert.match(previews[0].copy, /Tap the bordered Type of incident selector/);
    assert.match(previews[0].copy, /Road Accident is available and selected/);
    assert.match(previews[0].copy, /locked and coming soon/);
    assert.match(previews[0].copy, /does not change a real report/);
    assert.equal(previews[0].selector, undefined);
    assert.equal(previews[0].nav, undefined);
});

test('an older active tour resumes at the inserted selector while Done and Skip remain dismissed', () => {
    for (const step of [0, 2, 3, 7, 15]) {
        const progress = createTutorialProgress(1, { storage: { getItem: () => JSON.stringify({ status: 'active', step }) } });
        assert.equal(progress.value().step, Math.min(step, 3));
        assert.equal(progress.shouldStart(), true);
    }
    for (const status of ['done', 'skipped']) {
        const progress = createTutorialProgress(1, { storage: { getItem: () => JSON.stringify({ status, step: 15 }) } });
        assert.equal(progress.shouldStart(), false);
        assert.equal(progress.value().status, status);
    }
    const current = createTutorialProgress(1, { storage: { getItem: () => JSON.stringify({ status: 'active', step: 8, version: 2 }) } });
    assert.equal(current.value().step, 8);
});

test('tutorial Skip and Done stay account-scoped, active steps resume, corrupt/denied storage is recoverable', () => {
    const items = new Map(), storage = { getItem: key => items.get(key), setItem: (key, value) => items.set(key, value) };
    const first = createTutorialProgress(1, { storage }); assert.equal(first.shouldStart(), true);
    first.save('active', 7); assert.equal(createTutorialProgress(1, { storage }).value().step, 7);
    first.save('skipped', 7); assert.equal(createTutorialProgress(1, { storage }).shouldStart(), false);
    assert.equal(createTutorialProgress(2, { storage }).shouldStart(), true);
    first.save('done', 16); assert.equal(createTutorialProgress(1, { storage }).shouldStart(), false);
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
