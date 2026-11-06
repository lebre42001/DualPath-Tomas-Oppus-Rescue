import test from 'node:test';
import assert from 'node:assert/strict';
import { initializeUpdatesPosition } from '../../resources/js/civilian-updates-position.js';

const memory = () => { const values = new Map(); return { getItem: key => values.get(key) ?? null, setItem: (key, value) => values.set(key, value) }; };
const event = (type, props = {}) => Object.assign(new Event(type, { cancelable: true }), props);
function fixture({ width = 390, height = 844, storage = memory(), owner = '1', navTop = 730 } = {}) {
    const win = new EventTarget(), button = new EventTarget(), viewport = new EventTarget();
    Object.assign(win, { innerWidth: width, innerHeight: height, visualViewport: viewport });
    Object.assign(viewport, { width, height, offsetTop: 0, offsetLeft: 0 });
    const classes = new Set();
    Object.assign(button, {
        style: {}, classList: { add: value => classes.add(value), remove: value => classes.delete(value) },
        getBoundingClientRect: () => ({ left: parseFloat(button.style.left) || 318, top: parseFloat(button.style.top) || 580, width: 60, height: 60 }),
        setPointerCapture: id => { button.captured = id; }, releasePointerCapture: () => { button.captured = null; },
    });
    win.getComputedStyle = () => ({ getPropertyValue: () => '0px' });
    const doc = { querySelector: () => navTop ? { getBoundingClientRect: () => ({ top: navTop, height: 90 }) } : null };
    initializeUpdatesPosition(button, owner, { window: win, document: doc, storage });
    let opened = 0;
    button.addEventListener('click', () => opened++);
    const pointer = (target, type, x, y, props = {}) => target.dispatchEvent(event(type, { pointerId: 1, clientX: x, clientY: y, button: 0, isPrimary: true, ...props }));
    const drag = (x, y) => {
        const r = button.getBoundingClientRect();
        pointer(button, 'pointerdown', r.left + 20, r.top + 20);
        pointer(win, 'pointermove', x + 20, y + 20);
        pointer(win, 'pointerup', x + 20, y + 20);
        button.dispatchEvent(event('click'));
    };
    return { win, viewport, button, storage, drag, pointer, opened: () => opened, classes };
}

test('touch/mouse drag moves without opening, saves across pages, and the next tap still opens updates', () => {
    for (const pointerType of ['touch', 'mouse', 'pen']) {
        const f = fixture();
        f.pointer(f.button, 'pointerdown', 338, 600, { pointerType });
        f.pointer(f.win, 'pointermove', 100, 200, { pointerType });
        assert(f.classes.has('is-dragging')); assert.equal(f.button.captured, 1);
        f.pointer(f.win, 'pointerup', 100, 200, { pointerType });
        f.button.dispatchEvent(event('click'));
        assert.equal(f.opened(), 0); assert.equal(f.button.style.left, '80px'); assert.equal(f.button.style.top, '180px');
        assert.equal(f.button.captured, null); assert.equal(f.classes.size, 0);
        const reopened = fixture({ storage: f.storage });
        assert.equal(reopened.button.style.left, '80px'); assert.equal(reopened.button.style.top, '180px');
        f.pointer(f.button, 'pointerdown', 100, 200); f.pointer(f.win, 'pointerup', 101, 202);
        f.button.dispatchEvent(event('click')); assert.equal(f.opened(), 1);
    }
});

test('drag stays inside the visible viewport and above mobile navigation; saved position adapts on desktop and resize', () => {
    const f = fixture(); f.drag(-1000, 5000);
    assert.equal(f.button.style.left, '12px'); assert.equal(f.button.style.top, '658px');
    const desktop = fixture({ width: 1920, height: 1080, navTop: null, storage: f.storage });
    assert.equal(desktop.button.style.left, '12px'); assert.equal(desktop.button.style.top, '996px');
    desktop.drag(5000, -1000);
    assert.equal(desktop.button.style.left, '1848px'); assert.equal(desktop.button.style.top, '12px');
    Object.assign(desktop.viewport, { width: 320, height: 480, offsetTop: 40 });
    desktop.viewport.dispatchEvent(new Event('resize'));
    assert.equal(desktop.button.style.left, '248px'); assert.equal(desktop.button.style.top, '52px');
    assert.deepEqual(fixture({ storage: f.storage, owner: '2' }).button.style, {});
});

test('cancelled drags restore position and ignore secondary pointers and tiny tap movements', () => {
    const f = fixture();
    f.pointer(f.button, 'pointerdown', 338, 600, { isPrimary: false }); f.pointer(f.win, 'pointermove', 100, 200);
    assert.deepEqual(f.button.style, {});
    f.pointer(f.button, 'pointerdown', 338, 600); f.pointer(f.win, 'pointermove', 340, 601); f.pointer(f.win, 'pointerup', 340, 601);
    f.button.dispatchEvent(event('click')); assert.equal(f.opened(), 1); assert.deepEqual(f.button.style, {});
    f.pointer(f.button, 'pointerdown', 338, 600); f.pointer(f.win, 'pointermove', 100, 200);
    f.pointer(f.win, 'pointercancel', 100, 200);
    assert.equal(f.button.style.left, '318px'); assert.equal(f.button.style.top, '580px');
    assert.equal(f.storage.getItem('aksyres-updates-position:v1:1'), null);
    assert.equal(f.classes.size, 0);
});

test('keyboard movement, reset, corrupt storage and denied storage keep the badge usable', () => {
    const f = fixture();
    f.button.dispatchEvent(event('keydown', { key: 'ArrowLeft' }));
    assert.equal(f.button.style.left, '302px');
    f.button.dispatchEvent(event('keydown', { key: 'Enter' })); f.button.dispatchEvent(event('click')); assert.equal(f.opened(), 1);
    f.button.dispatchEvent(event('keydown', { key: 'Home' }));
    assert.equal(f.button.style.left, ''); assert.equal(f.storage.getItem('aksyres-updates-position:v1:1'), 'null');
    for (const invalid of ['bad json', '{"x":"oops","y":1}', 'null']) {
        const local = { getItem: () => invalid, setItem() {} };
        assert.deepEqual(fixture({ storage: local }).button.style, {});
    }
    const blocked = fixture({ storage: { getItem() { throw new Error('Denied'); }, setItem() { throw new Error('Denied'); } } });
    blocked.drag(100, 200); assert.equal(blocked.button.style.left, '100px');
});
