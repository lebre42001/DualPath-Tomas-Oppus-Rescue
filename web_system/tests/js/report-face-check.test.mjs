import test from 'node:test';
import assert from 'node:assert/strict';
import { captureLiveness, applyLiveness, hasLiveness, clearLiveness } from '../../resources/js/face-liveness.js';

const submissionId = 'a78aed32-2fcf-46ef-bc64-1389eb7f548f';
const challengeId = 'e7f3be3c-7b72-4c57-8c6c-67ea35b9767e';
const proof = 'a'.repeat(64);
const jpeg = Buffer.from('captured camera frame').toString('base64');
const faceChallenge = { challenge_id: challengeId, blink_count: 0, duration_ms: 0, verification_mode: 'face' };
const json = (body, status = 200) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });

function setup(t, { challenge = faceChallenge, result = { proof, expires_in: 86400 }, status = 200, active = true, hidden = false } = {}) {
    const previousDocument = globalThis.document;
    const previousFetch = globalThis.fetch;
    const calls = [], statuses = [], drawings = [];
    const canvas = {
        width: 0, height: 0,
        getContext: () => ({ drawImage: (...args) => drawings.push(args) }),
        toDataURL: () => `data:image/jpeg;base64,${jpeg}`,
    };
    globalThis.document = {
        hidden,
        querySelector: () => ({ content: 'current-csrf-token' }),
        createElement: tag => { assert.equal(tag, 'canvas'); return canvas; },
    };
    globalThis.fetch = async (url, options) => {
        const body = JSON.parse(options.body);
        calls.push({ url, options, body });
        return url === '/face/challenge' ? json(challenge) : json(result, status);
    };
    t.after(() => {
        if (previousDocument === undefined) delete globalThis.document; else globalThis.document = previousDocument;
        globalThis.fetch = previousFetch;
    });
    const form = {
        dataset: { livenessChallengeUrl: '/face/challenge', livenessVerifyUrl: '/face/verify' },
        elements: { namedItem: name => name === 'submission_id' ? { value: submissionId } : null },
    };
    const video = { videoWidth: 640, videoHeight: 480, readyState: 4, srcObject: { active } };
    const capture = (purpose = 'report') => captureLiveness({ video, form, purpose, onStatus: message => statuses.push(message) });
    return { capture, calls, statuses, drawings, video };
}

test('report face check binds to the same draft and sends one camera frame without a blink sequence', async t => {
    const { capture, calls, statuses, drawings, video } = setup(t);
    const result = await capture();
    assert.equal(calls.length, 2);
    assert.deepEqual(calls[0].body, { purpose: 'report', submission_id: submissionId });
    assert.deepEqual(calls[1].body, { challenge_id: challengeId, frames: [{ ms: 0, jpeg }] });
    assert.equal(drawings.length, 1, 'A face-only report check does not record a blink video');
    assert.equal(drawings[0][0], video);
    assert.equal(calls[1].options.credentials, 'same-origin');
    assert.equal(calls[1].options.headers['X-CSRF-TOKEN'], 'current-csrf-token');
    assert(statuses.every(message => !/blink/i.test(message)), 'The report check must not ask for blinking');
    assert.equal(result.proof, proof);
    assert.equal(result.expiresIn, 86400);
    assert(result.file instanceof File);
    assert.equal(result.file.type, 'image/jpeg');
    assert.equal(await result.file.text(), 'captured camera frame');
});

test('server rejection of a frame with no face cannot return a verification proof', async t => {
    const { capture, calls } = setup(t, { status: 422, result: { errors: { liveness_proof: ['No face detected. Show one face clearly.'] } } });
    await assert.rejects(capture(), /No face detected/);
    assert.equal(calls.filter(call => call.url === '/face/verify').length, 1);
});

test('inactive camera cannot submit a report face frame for verification', async t => {
    const { capture, calls, drawings } = setup(t, { active: false });
    await assert.rejects(capture());
    assert.equal(calls.filter(call => call.url === '/face/verify').length, 0);
    assert.equal(drawings.length, 0);
});

test('a background page cannot reuse a stopped or hidden camera frame', async t => {
    const { capture, calls, drawings } = setup(t, { hidden: true });
    await assert.rejects(capture());
    assert.equal(calls.filter(call => call.url === '/face/verify').length, 0);
    assert.equal(drawings.length, 0);
});

for (const [label, challenge] of [
    ['blink challenge', { challenge_id: challengeId, blink_count: 1, duration_ms: 6000, verification_mode: 'blink' }],
    ['missing face mode', { challenge_id: challengeId, blink_count: 0, duration_ms: 0 }],
    ['nonzero blink count', { ...faceChallenge, blink_count: 1 }],
    ['nonzero capture duration', { ...faceChallenge, duration_ms: 6000 }],
]) {
    test(`report check rejects an unexpected ${label} instead of bypassing its challenge`, async t => {
        const { capture, calls } = setup(t, { challenge });
        await assert.rejects(capture());
        assert.equal(calls.filter(call => call.url === '/face/verify').length, 0);
    });
}

test('account verification cannot accept a report-only face challenge', async t => {
    const { capture, calls } = setup(t);
    await assert.rejects(capture('account'));
    assert.deepEqual(calls[0].body, { purpose: 'account' });
    assert.equal(calls.filter(call => call.url === '/face/verify').length, 0);
});

test('a malformed server proof cannot authorize report submission', async t => {
    const { capture } = setup(t, { result: { proof: 'incomplete', expires_in: 86400 } });
    await assert.rejects(capture());
});

test('a retained report face check belongs only to its original submission and expires cleanly', t => {
    const previousTimer = globalThis.setTimeout;
    let expire, delay, currentId = submissionId, expiredEvents = 0;
    globalThis.setTimeout = (callback, ms) => { expire = callback; delay = ms; return 1; };
    t.after(() => { globalThis.setTimeout = previousTimer; });
    const input = { value: '' };
    const form = new EventTarget();
    form.dataset = {};
    form.querySelector = () => input;
    form.elements = { namedItem: () => ({ value: currentId }) };
    form.addEventListener('liveness:expired', () => expiredEvents++);
    applyLiveness(form, { proof, expiresIn: 86400, submissionId });
    assert.equal(delay, 86400000);
    assert.equal(hasLiveness(form), true, 'The same report can retry without a new face capture');
    currentId = 'a1769ca7-b65a-453b-aeab-fbe5ea6ce900';
    assert.equal(hasLiveness(form), false, 'The check must not authorize another draft');
    currentId = submissionId;
    assert.equal(hasLiveness(form), true);
    expire();
    assert.equal(hasLiveness(form), false);
    assert.equal(input.value, '');
    assert.equal(form.dataset.livenessSubmissionId, undefined);
    assert.equal(expiredEvents, 1);
    clearLiveness(form);
    assert.equal(hasLiveness(form), false);
});
