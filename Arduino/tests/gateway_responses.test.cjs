const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

// Run the actual NODE_1 dashboard script with a minimal DOM. The firmware's
// shared confirmation/diagnostics script is unchanged by these fixes.
const firmware = fs.readFileSync(path.join(__dirname, '../Arduino/GatewayNode1/GatewayNode1.ino'), 'utf8');
const dashboard = firmware.match(/const char LOCAL_DASHBOARD\[\][\s\S]*?R"rawliteral\(([\s\S]*?)\)rawliteral";/)[1];
const script = dashboard.match(/<script>([\s\S]*?)<\/script>/)[1];

function harness(storageUnavailable = false) {
  class Element {
    constructor() {
      this.children = []; this.textContent = ''; this.style = {}; this.value = '';
      const classes = new Set();
      this.classList = { contains: x => classes.has(x), add: x => classes.add(x), remove: x => classes.delete(x) };
    }
    addEventListener() {}
    append(...items) { this.children.push(...items); }
    replaceChildren(...items) { this.children = items; this.textContent = ''; }
  }
  const elements = new Map();
  for (const [, id] of dashboard.matchAll(/id="([^"]+)"/g)) elements.set(id, new Element());
  const saved = new Map();
  const context = vm.createContext({
    document: { getElementById: id => elements.get(id), createElement: () => new Element() },
    localStorage: {
      getItem(key) { if (storageUnavailable) throw Error('storage unavailable'); return saved.get(key); },
      setItem(key, value) { if (storageUnavailable) throw Error('storage unavailable'); saved.set(key, value); },
    },
  });
  vm.runInContext(script, context);
  return { context, elements, saved };
}
const response = (id, at = '2026-11-07T10:00:00Z', message = 'Team responding') => ({
  message_id: id, created_at: '1794000000', report_status: 'ACKNOWLEDGED', ack_message: message, ack_at: at,
});

test('an older local report response remains visible after a newer submission', async () => {
  const h = harness();
  h.context.fetch = async () => ({ ok: true, json: async () => ({
    message_id: 'NODE_1-2', report_status: 'QUEUED', pending: true, server_online: true, responses: [response('NODE_1-1')],
  }) });
  await h.context.refresh();
  assert.equal(h.elements.get('msgid').textContent, 'NODE_1-2');
  assert.equal(h.elements.get('responses').children[0].children[0].textContent, 'NODE_1-1 - ACKNOWLEDGED');
  assert.equal(h.elements.get('modalId').textContent, 'NODE_1-1');
  assert.ok(h.elements.get('ackModal').classList.contains('show'));
});

test('concurrent responses queue popups without replacing the open response', () => {
  const h = harness(), a = response('NODE_1-1'), b = response('NODE_1-2', '2026-11-07T11:00:00Z');
  h.context.renderResponses([a]);
  h.context.renderResponses([a, b]);
  assert.equal(h.elements.get('modalId').textContent, a.message_id);
  h.context.closeAck();
  assert.equal(h.elements.get('modalId').textContent, b.message_id);
  h.context.closeAck();
  assert.ok(!h.elements.get('ackModal').classList.contains('show'));
  h.context.renderResponses([a, b]);
  assert.ok(!h.elements.get('ackModal').classList.contains('show'));
  assert.equal(h.elements.get('responses').children.length, 2);
});

test('a new response revision for the same report gets its own notification', () => {
  const h = harness(), a = response('NODE_1-1');
  h.context.renderResponses([a]); h.context.closeAck();
  h.context.renderResponses([response(a.message_id, a.ack_at, 'Report resolved')]);
  assert.ok(h.elements.get('ackModal').classList.contains('show'));
  assert.equal(h.elements.get('modalMessage').textContent, 'Report resolved');
});

test('response text is rendered literally and unchanged polls preserve expanded rows', () => {
  const h = harness(), a = response('NODE_1-1', undefined, '<img src=x onerror=alert(1)>');
  h.context.renderResponses([a]);
  const row = h.elements.get('responses').children[0]; row.open = true;
  assert.equal(row.children[1].textContent, a.ack_message);
  h.context.renderResponses([a]);
  assert.equal(h.elements.get('responses').children[0], row);
  assert.equal(row.open, true);
});

test('unavailable browser storage does not break dismissal or repeat popups in the same session', () => {
  const h = harness(true), a = response('NODE_1-1');
  h.context.renderResponses([a]); h.context.closeAck(); h.context.renderResponses([a]);
  assert.ok(!h.elements.get('ackModal').classList.contains('show'));
});

test('empty response cache and a failed status request leave usable dashboard state', async () => {
  const h = harness(); h.context.renderResponses([]);
  assert.equal(h.elements.get('responses').textContent, 'No responses received yet.');
  h.context.fetch = async () => ({ ok: false });
  await h.context.refresh();
  assert.equal(h.elements.get('responses').textContent, 'No responses received yet.');
});
