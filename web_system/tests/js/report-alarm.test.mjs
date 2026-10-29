import test from 'node:test';
import assert from 'node:assert/strict';
import { ReportAlarm } from '../../resources/js/report-alarm.js';
import { IncomingReportState } from '../../resources/js/incoming-reports.js';

function fixture() {
    const callbacks = new Map(), timers = new Map();
    let id = 0;
    const audio = { plays: 0, pauses: 0, currentTime: 12,
        addEventListener(name, callback) { callbacks.set(name, callback); },
        play() { this.plays++; return Promise.resolve(); },
        pause() { this.pauses++; },
    };
    const clock = { setTimeout(callback, duration) { timers.set(++id, { callback, duration }); return id; },
        clearTimeout(timer) { timers.delete(timer); } };
    const alarm = new ReportAlarm(audio, () => {}, clock);
    const finish = () => { [...timers.values()].forEach(timer => timer.callback()); };
    return { audio, alarm, timers, callbacks, finish };
}

test('alarm defaults on; activation sample and actual arrival have separate bounded durations', async () => {
    const { audio, alarm, timers, finish } = fixture();
    assert.equal(alarm.enabled, true); assert.equal(alarm.ready, false);
    await alarm.enable(); assert.equal(audio.plays, 1);
    assert.equal([...timers.values()][0].duration, 1200);
    finish(); assert.equal(alarm.enabled, true); assert.equal(alarm.playing, false);
    await alarm.play(); assert.equal([...timers.values()][0].duration, 10000);
    assert.equal(audio.currentTime, 0); assert.equal(audio.loop, false);
    finish(); assert.equal(alarm.playing, false); assert.equal(timers.size, 0);
});

test('bursts cannot overlap or extend an active alarm; stop preserves future alerts', async () => {
    const { audio, alarm, timers, finish } = fixture();
    await alarm.enable(); finish(); await alarm.play();
    const originalTimer = [...timers.keys()][0];
    await alarm.play(); await alarm.play();
    assert.equal(audio.plays, 2); assert.deepEqual([...timers.keys()], [originalTimer]);
    alarm.stop(); assert.equal(timers.size, 0); assert.equal(alarm.enabled, true);
    await alarm.play(); assert.equal(audio.plays, 3);
    alarm.disable(); await alarm.play(); assert.equal(audio.plays, 3);
    assert.equal(alarm.playing, false); assert.equal(timers.size, 0);
});

test('blocked playback retains the desired setting and retries a pending arrival at full duration', async () => {
    const { audio, alarm, callbacks, timers } = fixture();
    audio.play = () => Promise.reject(new Error('NotAllowedError'));
    assert.equal(await alarm.play(), false);
    assert.equal(alarm.blocked, true); assert.equal(alarm.enabled, true); assert.equal(timers.size, 0);
    assert.equal(alarm.pending, true); assert.equal(alarm.ready, false);
    audio.play = () => Promise.resolve();
    assert.equal(await alarm.enable(), true); assert.equal(alarm.blocked, false);
    assert.equal([...timers.values()][0].duration, 10000, 'retry must not become a short test sample');
    assert.equal(alarm.pending, false); assert.equal(alarm.ready, true);
    callbacks.get('error')(); assert.equal(alarm.blocked, true); assert.equal(alarm.playing, false);
    assert.equal(timers.size, 0);
});

test('a report arriving during the activation sample gets a full alarm', async () => {
    const { alarm, timers } = fixture();
    await alarm.enable(); assert.equal([...timers.values()][0].duration, 1200);
    await alarm.play(); assert.equal(timers.size, 1);
    assert.equal([...timers.values()][0].duration, 10000);
    assert.equal(alarm.isSample, false);
});

test('navigation suspension preserves an unplayed arrival and does not mute the preference', async () => {
    const { audio, alarm } = fixture();
    audio.play = () => Promise.reject(new Error('NotAllowedError'));
    await alarm.play(); alarm.suspend();
    assert.equal(alarm.pending, true); assert.equal(alarm.enabled, true); assert.equal(alarm.ready, false);
    alarm.disable(); assert.equal(alarm.pending, false); assert.equal(alarm.enabled, false);
});

test('a stalled audio load preserves pending arrival without claiming that sound played', async () => {
    const { audio, alarm, finish, timers } = fixture();
    audio.play = () => new Promise(() => {});
    void alarm.play(); assert.equal(alarm.playing, false); assert.equal(alarm.starting, true);
    finish(); assert.equal(alarm.playing, false); assert.equal(alarm.starting, false);
    assert.equal(alarm.pending, true); assert.equal(alarm.blocked, true); assert.equal(timers.size, 0);
});

test('temporary media failure reloads the file before a later activation attempt', async () => {
    const { audio, alarm } = fixture();
    audio.error = { code: 2 };
    let reloads = 0;
    audio.load = () => { reloads++; audio.error = null; };
    await alarm.enable(); assert.equal(reloads, 1); assert.equal(alarm.ready, true);
});

test('an interrupted pending play cannot re-enable sound or leave timers behind', async () => {
    const { audio, alarm, timers } = fixture();
    let reject;
    audio.play = () => new Promise((_, rejectPlay) => { reject = rejectPlay; });
    const pending = alarm.enable();
    alarm.disable(); reject(new Error('AbortError'));
    assert.equal(await pending, false); assert.equal(alarm.enabled, false);
    assert.equal(alarm.blocked, false); assert.equal(timers.size, 0);
});

test('initial history, updates and Echo/poll duplicates cannot produce extra alarms', async () => {
    const { audio, alarm, finish } = fixture();
    await alarm.enable(); finish();
    const state = new IncomingReportState({ cursor: 5 });
    const receive = async (id, initial = false, action = 'created', source = 'online') => {
        if (state.accept({ action, report: { id, transmission_type: source } }, initial) && !initial) await alarm.play();
    };
    await receive(4, true); await receive(4); await receive(6, false, 'updated');
    assert.equal(audio.plays, 1);
    await receive(6); finish(); await receive(6);
    assert.equal(audio.plays, 2);
    await receive(7, false, 'created', 'lora');
    assert.equal(audio.plays, 3);
});
