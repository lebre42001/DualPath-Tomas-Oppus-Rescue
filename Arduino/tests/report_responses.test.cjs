const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../Arduino/shared/report_responses.h'), 'utf8');
const script = source.match(/<script>([\s\S]*?)<\/script>/)[1];

function harness(storageUnavailable = false, initialStorage = new Map()) {
  const elements = new Map();
  const document = { activeElement: null };
  class Element {
    constructor(tag = 'div') {
      this.tagName = tag.toUpperCase(); this.children = []; this.textContent = ''; this.style = {}; this.listeners = {};
      this.attributes = {}; this.isConnected = true;const classes = new Set();
      this.classList = { contains: x => classes.has(x), add: x => classes.add(x), remove: x => classes.delete(x) };
    }
    set id(value) { this._id = value;elements.set(value, this); } get id() { return this._id; }
    setAttribute(key, value) { this.attributes[key] = value; }
    addEventListener(type, fn) { this.listeners[type] = fn; }
    append(...items) { this.children.push(...items); }
    replaceChildren(...items) { this.children = items;this.textContent = ''; }
    focus() { document.activeElement = this; }
    click() { this.listeners.click?.({}); }
  }
  const main = new Element('main'), body = new Element('body'), send = new Element('button');send.id = 'sendButton';send.focus();
  Object.assign(document, { body, querySelector: selector => selector === 'main' ? main : null, createElement: tag => new Element(tag) });
  const context = vm.createContext({ document, localStorage: {
    getItem(key) { if (storageUnavailable) throw Error('unavailable');return initialStorage.get(key); },
    setItem(key, value) { if (storageUnavailable) throw Error('unavailable');initialStorage.set(key, value); },
  } });context.window = context;
  vm.runInContext(script, context);
  return { context, elements, storage: initialStorage, main, document, send };
}
const response = (id, at = '2026-11-07T10:00:00+00:00', text = 'Team responding') => ({
  message_id: id, created_at: '1794000000', report_status: 'ACKNOWLEDGED', ack_message: text, ack_at: at,
});
const open = h => h.elements.get('reportResponsesModal').classList.contains('show');
const close = h => h.elements.get('reportResponsesClose').click();
const popupId = h => h.elements.get('reportResponsesMessageId').textContent;

test('shared renderer adds an accessible response history and dialog without field globals', () => {
  const h = harness();assert.equal(h.main.children.length, 1);
  assert.equal(h.elements.get('reportResponsesModal').attributes.role, 'dialog');
  assert.equal(h.elements.get('reportResponsesModal').attributes['aria-modal'], 'true');
  assert.equal(h.context.shownReview, undefined);assert.equal(h.context.responses, undefined);
  h.context.renderReportResponses([response('NODE_2-1')]);
  assert.equal(popupId(h), 'Message ID: NODE_2-1');assert.ok(open(h));
  assert.equal(h.document.activeElement, h.elements.get('reportResponsesClose'));
  close(h);assert.equal(h.document.activeElement, h.send);
});

test('older-report messages stay visible when another report was submitted later on every field node', () => {
  for(let node = 2;node <= 10;++node){
    const h = harness();h.context.renderReportResponses([response(`NODE_${node}-1`)]);
    assert.match(h.elements.get('reportResponsesList').children[0].children[0].textContent, new RegExp(`NODE_${node}-1`));
    close(h);assert.equal(h.elements.get('reportResponsesList').children.length, 1);
  }
});

test('response popups queue and revisions notify even when report and timestamp are unchanged', () => {
  const h = harness(), a = response('NODE_2-1'), b = response('NODE_2-2', '2026-11-07T11:00:00+00:00');
  h.context.renderReportResponses([a]);h.context.renderReportResponses([a,b]);
  assert.equal(popupId(h), 'Message ID: NODE_2-1');close(h);assert.equal(popupId(h), 'Message ID: NODE_2-2');close(h);
  assert.ok(!open(h));h.context.renderReportResponses([a,b]);assert.ok(!open(h));
  h.context.renderReportResponses([a,{...b,ack_message:'Report resolved'}]);assert.ok(open(h));
  assert.equal(h.elements.get('reportResponsesMessage').textContent, 'Report resolved');
});

test('unchanged polls preserve expanded rows and render injected content literally', () => {
  const h = harness(), a = response('NODE_2-1', undefined, '<img src=x onerror=alert(1)>');
  h.context.renderReportResponses([a]);const row = h.elements.get('reportResponsesList').children[0];row.open=true;
  assert.equal(row.children[1].textContent, a.ack_message);
  h.context.renderReportResponses([a]);assert.equal(h.elements.get('reportResponsesList').children[0], row);assert.equal(row.open,true);
  assert.equal(h.elements.get('reportResponsesMessage').textContent, a.ack_message);
});

test('storage failures still allow dismissal and prevent repeats in the current session', () => {
  const h = harness(true), a = response('NODE_2-1');h.context.renderReportResponses([a]);close(h);h.context.renderReportResponses([a]);assert.ok(!open(h));
});

test('dismissal survives browser reload while a newer revision remains unread', () => {
  const a = response('NODE_2-1'), h = harness();h.context.renderReportResponses([a]);close(h);
  const reopened = harness(false,h.storage);reopened.context.renderReportResponses([a]);assert.ok(!open(reopened));
  reopened.context.renderReportResponses([{...a,report_status:'REJECTED'}]);assert.ok(open(reopened));
});

test('Escape dismisses, Tab stays in the dialog, malformed data is ignored, history is bounded', () => {
  const h = harness();h.context.renderReportResponses([null,{},response('NODE_2-1')]);
  let prevented=0;const keydown=h.elements.get('reportResponsesModal').listeners.keydown;
  keydown({key:'Tab',preventDefault(){prevented++},stopPropagation(){}});assert.equal(prevented,1);assert.equal(h.document.activeElement,h.elements.get('reportResponsesClose'));
  keydown({key:'Escape',preventDefault(){prevented++},stopPropagation(){}});assert.equal(prevented,2);assert.ok(!open(h));
  h.context.renderReportResponses(Array.from({length:30},(_,i)=>response(`NODE_2-${i+1}`)));assert.equal(h.elements.get('reportResponsesList').children.length,16);
  close(h);h.context.renderReportResponses(undefined);assert.equal(h.elements.get('reportResponsesList').textContent,'No responses received yet.');
});

test('response-dialog keys do not reach the underlying report-confirmation handler', () => {
  for (const key of ['Escape','Tab']) {
    const h = harness();h.context.renderReportResponses([response('NODE_2-1')]);
    let stopped = false, underlyingConfirmationClosed = false;
    const event = { key, preventDefault(){}, stopPropagation(){stopped = true} };
    h.elements.get('reportResponsesModal').listeners.keydown(event);
    // Emulate the browser bubbling to REPORT_GUARD_SCRIPT's document listener.
    if (!stopped && event.key === 'Escape') underlyingConfirmationClosed = true;
    assert.ok(stopped, `${key} must stop at the topmost response dialog`);
    assert.equal(underlyingConfirmationClosed,false);
  }
});
