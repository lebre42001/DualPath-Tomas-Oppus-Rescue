const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

// Execute the shipped script, including its prepare/confirm/send callbacks.
// This small DOM implements only browser operations used by that script.
const source = fs.readFileSync(path.join(__dirname, '../Arduino/shared/report_dashboard.h'), 'utf8');
const script = source.match(/<script>([\s\S]*?)<\/script>/)?.[1];
assert.ok(script, 'Find the actual shared reporting dashboard script');

async function harness({ gateway = false, prepareFailure = false, sendFailure = false, sendStatus = 200 } = {}) {
  const elements = new Map(), requests = [], alerts = [], intervals = [];
  const document = { activeElement: null, listeners: {} };
  class Element {
    constructor(tag = 'div') {
      this.tagName = tag.toUpperCase();
      this.children = []; this.parentElement = null; this.style = {}; this.attributes = {};
      this.listeners = {}; this.value = ''; this.disabled = false; this.innerHTMLWrites = [];
      this._textContent = ''; this._innerHTML = '';
      const classes = new Set();
      this.classList = { contains: value => classes.has(value), add: value => classes.add(value), remove: value => classes.delete(value) };
    }
    set id(value) { this._id = value; elements.set(value, this); }
    get id() { return this._id; }
    set textContent(value) { this._textContent = String(value); this._innerHTML = ''; this.children = []; }
    get textContent() { return this._textContent; }
    set innerHTML(value) {
      this._innerHTML = String(value); this.innerHTMLWrites.push(this._innerHTML); this.children = [];
      // Register controls that a real browser creates from the static templates.
      for (const match of this._innerHTML.matchAll(/<([a-z][a-z0-9]*)\b[^>]*\bid="([^"]+)"[^>]*>([^<]*)/gi)) {
        const child = new Element(match[1]); child.id = match[2]; child.textContent = match[3]; this.append(child);
      }
    }
    get innerHTML() { return this._innerHTML; }
    setAttribute(key, value) { this.attributes[key] = String(value); }
    append(...children) { for (const child of children) { child.parentElement = this; this.children.push(child); } }
    before(child) { this.insertBeside(child, 0); }
    after(child) { this.insertBeside(child, 1); }
    insertBeside(child, offset) {
      assert.ok(this.parentElement, 'Inserted control has a parent');
      const parent = this.parentElement; child.parentElement = parent;
      parent.children.splice(parent.children.indexOf(this) + offset, 0, child);
    }
    addEventListener(type, listener) { (this.listeners[type] ??= []).push(listener); }
    dispatch(type) { for (const listener of this.listeners[type] ?? []) listener({ target: this }); }
    focus() { document.activeElement = this; }
  }
  document.body = new Element('body');
  document.createElement = tag => new Element(tag);
  document.getElementById = id => elements.get(id) ?? null;
  document.addEventListener = (type, listener) => { (document.listeners[type] ??= []).push(listener); };
  const main = new Element('main'); document.body.append(main);
  const add = (id, tag = 'div', parent = main) => { const element = new Element(tag); element.id = id; parent.append(element); return element; };
  const type = add('type', 'select');
  type.options = [{ value: 'ROAD_ACCIDENT' }, { value: 'FIRE' }];
  add('message', 'textarea');
  const counter = new Element('div'); main.append(counter); add('remaining', 'span', counter);
  const button = add(gateway ? 'send' : 'sendButton', 'button');
  add('created'); add('status');
  const response = (status, body) => ({
    ok: status >= 200 && status < 300, status, headers: { get: () => null },
    json: async () => body, text: async () => typeof body === 'string' ? body : JSON.stringify(body),
  });
  const context = vm.createContext({
    document, TextEncoder, URLSearchParams, Date, Math,
    alert: message => alerts.push(String(message)),
    setInterval: callback => { intervals.push(callback); return intervals.length; },
    refresh: async () => {},
    fetch: async (url, options = {}) => {
      requests.push({ url, method: options.method ?? 'GET', body: options.body ? new URLSearchParams(options.body) : null });
      if (url === '/status') return response(200, { radio_ready: true, queued_reports: 0 });
      if (url === '/prepare') {
        if (prepareFailure) throw new Error('Prepare connection lost');
        return response(200, { confirmation: 'single-use-proof' });
      }
      if (url === '/send') {
        if (sendFailure) throw new Error('Send connection lost');
        return response(sendStatus, sendStatus === 200 ? 'Saved on this node.' : 'Could not save this report.');
      }
      throw new Error(`Unexpected request: ${url}`);
    },
  });
  context.window = context;
  vm.runInContext(script, context, { filename: 'report_dashboard.h' });
  // Settle the initial refresh without running periodic polling or any real HTTP.
  await context.refresh();
  const field = id => elements.get(id);
  const fill = (name = 'Juan', description = 'Crash, injuries', landmark = 'Bogo bridge') => {
    field('reporterName').value = name; field('message').value = description; field('landmark').value = landmark;
    field('reporterName').dispatch('input'); field('message').dispatch('input');
  };
  return { context, document, elements, requests, alerts, button, field, fill,
    posts: url => requests.filter(request => request.url === url),
    open: () => field('confirmReport').classList.contains('show'),
    prepare: () => context.sendReport(), confirm: () => field('confirmSend').onclick(),
  };
}

test('name input works in field and gateway dashboards and remains required', async () => {
  for (const gateway of [false, true]) {
    const h = await harness({ gateway });
    const name = h.field('reporterName');
    assert.equal(name.required, true); assert.equal(name.autocomplete, 'name');
    assert.equal(name.name, 'reporter_name');
    h.fill('   '); await h.prepare();
    assert.equal(h.posts('/prepare').length, 0);
    assert.equal(h.document.activeElement, name);
    assert.match(h.alerts.at(-1), /enter your name/i);
    assert.equal(h.open(), false);
  }
});

test('overlong UTF-8 names and control characters never prepare a report', async () => {
  const invalidNames = ['x'.repeat(25), 'é'.repeat(13), '😀'.repeat(7), 'Juan|Cruz', 'Juan\nCruz', 'Juan\tCruz', 'Juan\x00Cruz', 'Juan\x1fCruz', 'Juan\x7fCruz'];
  for (const name of invalidNames) {
    const h = await harness(); h.fill(name); await h.prepare();
    assert.equal(h.posts('/prepare').length, 0, JSON.stringify(name));
    assert.ok(h.alerts.length, JSON.stringify(name)); assert.equal(h.open(), false);
  }
});

test('radio budget counts UTF-8 bytes and accepts exactly 70 encoded bytes', async () => {
  const h = await harness(); h.fill('José', 'é');
  assert.equal(Number(h.field('remaining').textContent), 57);
  // A 24-byte name plus the version/length prefix leaves 39 description bytes.
  h.fill('é'.repeat(12), 'x'.repeat(39));
  assert.equal(Number(h.field('remaining').textContent), 0);
  await h.prepare(); assert.equal(h.posts('/prepare').length, 1); assert.ok(h.open());
  const over = await harness(); over.fill('é'.repeat(12), 'x'.repeat(40)); await over.prepare();
  assert.equal(over.posts('/prepare').length, 0);
  assert.match(over.alerts.at(-1), /share the radio message limit/i);
});

test('confirmation displays the exact name literally and posts its reviewed value', async () => {
  const h = await harness(); const name = '<img src=x>';
  h.fill(`  ${name}  `, 'One injured', 'Near school'); await h.prepare();
  assert.ok(h.open()); assert.equal(h.field('confirmName').textContent, name);
  assert.equal(h.field('confirmName').innerHTMLWrites.length, 0);
  assert.equal(h.field('confirmLocation').textContent, 'Near school');
  assert.equal(h.field('confirmDescription').textContent, 'One injured');
  assert.equal(h.posts('/prepare')[0].body.get('reporter_name'), name);
  assert.equal(h.posts('/prepare')[0].body.get('message'), 'One injured');
  await h.confirm();
  assert.equal(h.posts('/send')[0].body.get('reporter_name'), name);
  assert.equal(h.posts('/send')[0].body.get('confirmation'), 'single-use-proof');
});

test('changing a name after prepare closes confirmation and prevents send', async () => {
  const h = await harness(); h.fill('Juan'); await h.prepare();
  h.field('reporterName').value = 'Pedro'; await h.confirm();
  assert.equal(h.posts('/send').length, 0); assert.equal(h.open(), false);
  assert.equal(h.field('reporterName').value, 'Pedro');
  assert.equal(h.document.activeElement, h.button);
});

test('successful local save clears all submitted fields and resets the budget', async () => {
  const h = await harness(); h.fill(); await h.prepare(); await h.confirm();
  assert.equal(h.posts('/send').length, 1); assert.equal(h.open(), false);
  for (const id of ['reporterName', 'message', 'landmark']) assert.equal(h.field(id).value, '');
  assert.equal(Number(h.field('remaining').textContent), 64);
  assert.equal(h.field('confirmSend').disabled, false); assert.equal(h.button.disabled, false);
});

test('failed prepare, failed send and rejected local save retain the reporter name', async () => {
  for (const options of [{ prepareFailure: true }, { sendFailure: true }, { sendStatus: 503 }]) {
    const h = await harness(options); h.fill('José', 'Crash, injuries', 'Bogo bridge'); await h.prepare();
    if (!options.prepareFailure) await h.confirm();
    assert.equal(h.field('reporterName').value, 'José');
    assert.equal(h.field('message').value, 'Crash, injuries');
    assert.equal(h.field('landmark').value, 'Bogo bridge');
    assert.equal(h.button.disabled, false); assert.equal(h.field('confirmSend').disabled, false);
    assert.ok(h.alerts.length);
  }
});
