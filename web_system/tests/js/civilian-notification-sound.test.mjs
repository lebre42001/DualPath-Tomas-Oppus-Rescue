import test from 'node:test';
import assert from 'node:assert/strict';
import {
    createCivilianSoundState,
    CivilianNotificationSound,
} from '../../resources/js/civilian-notification-sound.js';

const memory = () => {
    const values = new Map();
    return {
        values,
        getItem: key => values.get(key) ?? null,
        setItem: (key, value) => values.set(key, String(value)),
    };
};
const event = (id, kind = 'report_status') => ({
    id, kind, title: 'Your report was acknowledged', message: 'Private report details must never be stored with audio preferences.',
});
const ids = events => events.map(item => Number(item.id)).sort((a, b) => a - b);
const tick = async () => { await Promise.resolve(); await Promise.resolve(); };

function audioFixture() {
    const callbacks = new Map(), timers = new Map(), changes = [];
    let sequence = 0, time = 0;
    const audio = {
        plays: 0, pauses: 0, loads: 0, currentTime: 9, muted: false, loop: true,
        starts: [], error: null,
        addEventListener(name, callback) { callbacks.set(name, callback); },
        play() {
            this.plays++;
            this.starts.push({ muted: this.muted, loop: this.loop });
            return Promise.resolve();
        },
        pause() { this.pauses++; },
        load() { this.loads++; this.error = null; },
    };
    const clock = {
        setTimeout(callback, duration) {
            timers.set(++sequence, { callback, duration, due: time + duration });
            return sequence;
        },
        clearTimeout(timer) { timers.delete(timer); },
        now: () => time,
        Date: { now: () => time },
    };
    const sound = new CivilianNotificationSound(audio, { clock, onChange: state => changes.push(state) });
    const nextTimer = async () => {
        assert(timers.size > 0, 'a stalled or playing sound must have a bounded timer');
        const [id, timer] = [...timers.entries()].sort((a, b) => a[1].due - b[1].due)[0];
        timers.delete(id);
        time = timer.due;
        timer.callback();
        await tick();
        return timer.duration;
    };
    return { audio, sound, callbacks, timers, changes, nextTimer };
}

test('the first feed is a silent baseline and fresh report/app events are claimed once', () => {
    const state = createCivilianSoundState('9001', { storage: memory() });
    assert.equal(state.enabled(), true);
    assert.deepEqual(state.observe([event(4), event(7, 'app_update_available')]), []);
    assert.deepEqual(state.observe([event(7), event(4)]), []);
    const incoming = [event(9), event(8, 'app_update_available'), event(9)];
    assert.deepEqual(ids(state.observe(incoming)), [8, 9]);
    assert.deepEqual(state.observe(incoming), []);
    assert.deepEqual(incoming.map(item => item.id), [9, 8, 9], 'sound deduplication must not mutate server feed data');
});

test('reloads baseline silently and sound preferences remain scoped to their account', async () => {
    const storage = memory();
    const first = createCivilianSoundState('9002', { storage });
    first.observe([]);
    assert.deepEqual(ids(first.observe([event(10)])), [10]);
    first.setEnabled(false);
    const { createCivilianSoundState: reopen } = await import('../../resources/js/civilian-notification-sound.js?sound-reload');
    const reopened = reopen('9002', { storage });
    assert.equal(reopened.enabled(), false);
    assert.deepEqual(reopened.observe([event(10), event(11)]), [], 'reopening cannot replay pending history');
    reopened.setEnabled(true);
    assert.deepEqual(reopened.observe([event(11)]), []);
    assert.deepEqual(ids(reopened.observe([event(12)])), [12]);
    const anotherAccount = reopen('9003', { storage });
    assert.equal(anotherAccount.enabled(), true);
    assert.deepEqual(anotherAccount.observe([]), []);
    assert.deepEqual(ids(anotherAccount.observe([event(12)])), [12]);
    assert(![...storage.values.values()].some(value => value.includes('Private report details')),
        'only preferences and numeric cursors belong in local storage');
});

test('separate tabs reread persisted event claims before producing another chime', async () => {
    const storage = memory();
    const { createCivilianSoundState: createTabA } = await import('../../resources/js/civilian-notification-sound.js?sound-tab-a');
    const { createCivilianSoundState: createTabB } = await import('../../resources/js/civilian-notification-sound.js?sound-tab-b');
    const tabA = createTabA('9004', { storage }), tabB = createTabB('9004', { storage });
    tabA.observe([]); tabB.observe([]);
    assert.deepEqual(ids(tabA.observe([event(20)])), [20]);
    assert.deepEqual(tabB.observe([event(20)]), []);
    assert.deepEqual(ids(tabB.observe([event(21, 'app_update_available')])), [21]);
    assert.deepEqual(tabA.observe([event(20), event(21)]), []);
    assert.deepEqual(ids(tabA.observe([event(22)])), [22]);
});

test('muting consumes arrivals and profile-disabled report alerts still allow fresh app notices', () => {
    const state = createCivilianSoundState('9005', { storage: memory() });
    state.observe([]);
    state.setEnabled(false);
    assert.equal(state.enabled(), false);
    assert.deepEqual(state.observe([event(30), event(31, 'app_update_available')]), []);
    state.setEnabled(true);
    assert.deepEqual(state.observe([event(30), event(31)]), [], 'unmuting must not replay old arrivals');
    assert.deepEqual(ids(state.observe([event(32), event(33, 'app_update_available')], { reportAlertsEnabled: false })), [33]);
    assert.deepEqual(state.observe([event(32), event(33)], { reportAlertsEnabled: true }), []);
    assert.deepEqual(ids(state.observe([event(34)], { reportAlertsEnabled: true })), [34]);
});

test('non-notifying activities advance the cursor without masquerading as incoming report updates', () => {
    const state = createCivilianSoundState('9006', { storage: memory() });
    state.observe([]);
    assert.deepEqual(state.observe([
        event(40, 'report_submitted'), event(41, 'report_submission'), event(42, 'app_update_applied'),
        event(43, 'login'), event(44, 'logout'), event(45, 'connection_restored'), event(46, null),
    ]), []);
    assert.deepEqual(state.observe([event(45)]), [], 'older IDs cannot become audible after their kind changes');
    assert.deepEqual(ids(state.observe([event(47, 'report_severity_reviewed'), event(48, 'app_update_available')])), [47, 48]);
});

test('malformed feeds and unsafe event IDs cannot poison the numeric notification cursor', () => {
    const state = createCivilianSoundState('9007', { storage: memory() });
    state.observe([]);
    assert.deepEqual(state.observe(null), []);
    assert.deepEqual(state.observe({ notifications: [] }), []);
    assert.deepEqual(state.observe([null, {}, ...[
        0, -1, 1.5, Infinity, NaN, Number.MAX_SAFE_INTEGER + 1, 'invalid', '1e3', true, {},
    ].map(id => event(id))]), []);
    assert.deepEqual(ids(state.observe([event('50')])), [50]);
    assert.deepEqual(state.observe([event(50)]), []);
    assert.deepEqual(ids(state.observe([event(51)])), [51]);
});

test('blocked/full storage retains same-page deduplication and preferences without throwing', () => {
    for (const [index, storage] of [null,
        { getItem() { throw new Error('Denied'); }, setItem() { throw new Error('Denied'); } },
        { getItem: () => null, setItem() { throw new Error('Quota exceeded'); } },
        { getItem: () => 'corrupt value', setItem() {} },
    ].entries()) {
        const state = createCivilianSoundState(String(9010 + index), { storage });
        assert.deepEqual(state.observe([event(60)]), []);
        assert.deepEqual(ids(state.observe([event(61)])), [61]);
        assert.deepEqual(state.observe([event(61)]), []);
        state.setEnabled(false);
        assert.equal(state.enabled(), false);
        assert.deepEqual(state.observe([event(62)]), []);
        state.setEnabled(true);
        assert.deepEqual(state.observe([event(62)]), []);
        assert.deepEqual(ids(state.observe([event(63)])), [63]);
    }
});

test('activation silently unlocks the source; an explicit test plays the MP3 once', async () => {
    const { audio, sound, callbacks, timers, changes } = audioFixture();
    assert.equal(sound.snapshot().enabled, true);
    assert.equal(sound.snapshot().ready, false);
    assert.equal(await sound.enable(), true);
    assert.deepEqual(audio.starts, [{ muted: true, loop: false }]);
    assert.equal(audio.muted, false);
    assert.equal(sound.snapshot().ready, true);
    assert.equal(sound.snapshot().playing, false);
    assert.equal(timers.size, 0);
    await sound.test();
    assert.deepEqual(audio.starts[1], { muted: false, loop: false });
    assert.equal(audio.currentTime, 0);
    assert.equal(sound.snapshot().playing, true);
    callbacks.get('ended')();
    assert.equal(sound.snapshot().playing, false);
    assert.equal(audio.plays, 2, 'the short notification file must never loop after ending');
    assert.equal(timers.size, 0);
    assert(changes.length > 0, 'the controls must receive playback state changes');
});

test('bursts cannot overlap or extend a currently playing one-shot notification', async () => {
    const { audio, sound, timers, nextTimer } = audioFixture();
    await sound.enable();
    await sound.play();
    const originalTimers = [...timers.keys()];
    await sound.play(); await sound.play();
    assert.equal(audio.plays, 2, 'one silent unlock plus one audible chime');
    assert.deepEqual([...timers.keys()], originalTimers, 'a burst must not reset the playback deadline');
    const duration = await nextTimer();
    assert(duration > 0 && duration <= 8000);
    assert.equal(sound.snapshot().playing, false);
    assert.equal(timers.size, 0);
    await sound.play();
    assert.equal(audio.plays, 3, 'later fresh batches remain audible');
    sound.suspend();
});

test('browser-blocked playback stays visibly blocked and enable never replays a stale arrival', async () => {
    const { audio, sound, timers } = audioFixture();
    const normalPlay = audio.play.bind(audio);
    audio.play = () => Promise.reject(Object.assign(new Error('User gesture required'), { name: 'NotAllowedError' }));
    assert.equal(await sound.play(), false);
    assert.equal(sound.snapshot().blocked, true);
    assert.equal(sound.snapshot().enabled, true, 'autoplay blocking must not be misrepresented as the user muting');
    assert.equal(sound.snapshot().playing, false);
    assert.equal(timers.size, 0);
    audio.play = normalPlay;
    await sound.enable();
    assert.deepEqual(audio.starts, [{ muted: true, loop: false }], 'only a silent unlock is allowed after a blocked arrival');
    assert.equal(sound.snapshot().blocked, false);
    assert.equal(sound.snapshot().playing, false);
    await sound.play();
    assert.deepEqual(audio.starts[1], { muted: false, loop: false });
    sound.suspend();
});

test('a stalled media request times out, and its late resolution cannot start old audio', async () => {
    const { audio, sound, timers, nextTimer } = audioFixture();
    let resolvePlay;
    audio.play = () => new Promise(resolve => { resolvePlay = resolve; });
    const pending = sound.play();
    assert.equal(sound.snapshot().playing, false);
    const duration = await nextTimer();
    assert(duration > 0 && duration <= 15000, 'media loading must never wait indefinitely');
    assert.equal(sound.snapshot().blocked, true);
    assert.equal(sound.snapshot().playing, false);
    assert.equal(timers.size, 0);
    resolvePlay(); await pending; await tick();
    assert.equal(sound.snapshot().playing, false);
    assert.equal(timers.size, 0);
    assert(audio.pauses > 0);
});

test('a stalled silent unlock is bounded and never leaves the element muted', async () => {
    const { audio, sound, timers, nextTimer } = audioFixture();
    let resolvePlay;
    audio.play = () => new Promise(resolve => { resolvePlay = resolve; });
    const pending = sound.enable();
    assert.equal(audio.muted, true);
    const duration = await nextTimer();
    assert(duration > 0 && duration <= 15000);
    assert.equal(audio.muted, false);
    assert.equal(sound.snapshot().ready, false);
    assert.equal(sound.snapshot().blocked, true);
    resolvePlay(); await pending; await tick();
    assert.equal(sound.snapshot().playing, false);
    assert.equal(timers.size, 0);
});

test('mute cancels active audio and a pending play cannot unmute itself later', async () => {
    const { audio, sound, timers } = audioFixture();
    await sound.enable(); await sound.play();
    sound.disable();
    assert.equal(sound.snapshot().enabled, false);
    assert.equal(sound.snapshot().playing, false);
    assert.equal(timers.size, 0);
    const before = audio.plays;
    await sound.play(); await sound.test();
    assert.equal(audio.plays, before);
    await sound.enable();
    let resolvePlay;
    audio.play = () => new Promise(resolve => { resolvePlay = resolve; });
    const pending = sound.play();
    sound.disable(); resolvePlay(); await pending; await tick();
    assert.equal(sound.snapshot().enabled, false);
    assert.equal(sound.snapshot().playing, false);
    assert.equal(sound.snapshot().blocked, false);
    assert.equal(audio.muted, false);
    assert.equal(timers.size, 0);
});

test('page suspension invalidates unfinished play promises without muting future updates', async () => {
    const { audio, sound, timers } = audioFixture();
    let resolvePlay;
    const normalPlay = audio.play.bind(audio);
    audio.play = () => new Promise(resolve => { resolvePlay = resolve; });
    const pending = sound.play();
    sound.suspend(); resolvePlay(); await pending; await tick();
    assert.equal(sound.snapshot().enabled, true);
    assert.equal(sound.snapshot().playing, false);
    assert.equal(timers.size, 0);
    audio.play = normalPlay;
    await sound.play();
    assert.equal(sound.snapshot().playing, true);
    sound.suspend();
});

test('a media error stops the one-shot and surfaces a recoverable blocked state', async () => {
    const { audio, sound, callbacks, timers } = audioFixture();
    await sound.enable(); await sound.play();
    audio.error = { code: 2 };
    callbacks.get('error')();
    assert.equal(sound.snapshot().playing, false);
    assert.equal(sound.snapshot().blocked, true);
    assert.equal(sound.snapshot().enabled, true);
    assert.equal(timers.size, 0);
    await sound.enable();
    assert.equal(audio.loads, 1, 'an explicit retry reloads a temporarily failed media file');
    assert.equal(sound.snapshot().ready, true);
    assert.equal(sound.snapshot().blocked, false);
    sound.suspend();
});

test('concurrent tab feeds share an account lock and emit only one audible notification', async () => {
    const storage = memory(), requests = [];
    let lockQueue = Promise.resolve();
    const navigator = { onLine: true, locks: {
        request(key, claim) {
            requests.push(key);
            const result = lockQueue.then(claim);
            lockQueue = result.catch(() => {});
            return result;
        },
    } };
    const tab = initialize => {
        const audioCallbacks = new Map(), windowCallbacks = new Map(), timers = new Map();
        let timerId = 0;
        const audio = {
            plays: 0, pauses: 0, muted: false, currentTime: 0, starts: [],
            addEventListener(name, callback) { audioCallbacks.set(name, callback); },
            play() { this.plays++; this.starts.push({ muted: this.muted, loop: this.loop }); return Promise.resolve(); },
            pause() { this.pauses++; },
        };
        const status = { textContent: '' };
        const root = {
            dataset: { owner: '9040' },
            querySelector(selector) {
                return selector === '[data-civilian-notification-audio]' ? audio
                    : selector === '[data-civilian-sound-status]' ? status : null;
            },
        };
        const document = { hidden: false, addEventListener() {} };
        const window = {
            addEventListener(name, callback) { windowCallbacks.set(name, callback); },
            setTimeout(callback, duration) { timers.set(++timerId, { callback, duration }); return timerId; },
            clearTimeout(id) { timers.delete(id); },
        };
        return { audio, status, timers, windowCallbacks,
            controller: initialize(root, { document, window, navigator, storage }) };
    };
    // Independent modules model tabs that have no shared JavaScript account map.
    const { initializeCivilianNotificationSound: initializeA } = await import('../../resources/js/civilian-notification-sound.js?sound-locked-tab-a');
    const { initializeCivilianNotificationSound: initializeB } = await import('../../resources/js/civilian-notification-sound.js?sound-locked-tab-b');
    const tabA = tab(initializeA), tabB = tab(initializeB);
    await Promise.all([tabA.controller.observe({ notifications: [] }), tabB.controller.observe({ notifications: [] })]);
    requests.length = 0;
    const feed = { notifications: [event(100)], report_alerts_enabled: true };
    await Promise.all([tabA.controller.observe(feed), tabB.controller.observe(feed)]);
    assert.equal(requests.length, 2);
    assert.equal(new Set(requests).size, 1, 'both tabs must coordinate using the same account-specific lock');
    assert.match(requests[0], /:9040$/);
    assert.equal(tabA.audio.plays + tabB.audio.plays, 1, 'only one tab may chime for the same fresh event');
    assert.deepEqual([...tabA.audio.starts, ...tabB.audio.starts], [{ muted: false, loop: false }]);

    // A saved mute propagated from the other tab stops the active notification.
    storage.setItem(`${requests[0]}:enabled`, '0');
    for (const current of [tabA, tabB]) {
        current.windowCallbacks.get('storage')({ key: `${requests[0]}:enabled` });
        assert.match(current.status.textContent, /muted/);
        assert.equal(current.timers.size, 0);
        current.windowCallbacks.get('pagehide')();
        current.controller.suspend();
    }
    await Promise.all([tabA.controller.observe({ notifications: [event(101)] }), tabB.controller.observe({ notifications: [event(101)] })]);
    assert.equal(tabA.audio.plays + tabB.audio.plays, 1, 'suspended or muted tabs must not chime for another arrival');
});
