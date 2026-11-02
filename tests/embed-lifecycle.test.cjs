const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const source = fs.readFileSync(require('node:path').join(__dirname, '../public/embed/v1.js'), 'utf8');

function harness({ allowed = false, observer = false, holdout = false, failEvents = 0, cart = null } = {}) {
  const requests = [], reads = [], writes = [], bridge = [], timers = [], listeners = {}, observers = [], intervals = [], messages = [];
  const elements = [];
  let now = Date.parse('2026-10-31T10:00:00Z'), sequence = 0;
  function element(tag) {
    const el = { tag, children: [], style: {}, classList: { add() {}, contains() { return false; } }, isConnected: true,
      attributes: {}, setAttribute(k, v) { this.attributes[k] = v; }, getAttribute(k) { return this.attributes[k]; }, appendChild(child) { this.children.push(child); this.firstChild = this.children[0]; }, attachShadow() { return element('shadow'); },
      querySelectorAll(selector) { const names = selector.split(',').map((v) => v.slice(1)); const found = []; const visit = (node) => { for (const child of node.children) { if (child.isConnected && names.some((n) => (child.className || '').split(' ').includes(n))) found.push(child); visit(child); } }; visit(this); return found; }, querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }, remove() { this.isConnected = false; },
      addEventListener(name, callback) { this[name] = callback; } };
    elements.push(el); return el;
  }
  const store = new Map();
  const storage = { getItem(k) { reads.push(k); return store.get(k) ?? null; }, setItem(k, v) { writes.push(k); store.set(k, v); }, removeItem(k) { store.delete(k); } };
  const document = { currentScript: { src: 'https://api.test/embed/v1.js', getAttribute(k) { return k === 'data-shopbonus-key' ? 'pk_test' : null; } },
    readyState: 'complete', visibilityState: 'visible', documentElement: Object.assign(element('html'), { lang: 'nl' }),
    createElement: element, addEventListener(name, callback) { listeners[name] = callback; } };
  let published = true;
  const config = { reward: { threshold_minor: 10000, currency: 'EUR' }, campaign: 'campaign', version: 1, config: { template: cart ? 'cart_progress' : 'floating', placement: {}, devices: ['desktop'], content: { cta_label: 'Go', cta_url: 'https://shop.test/reward' } } };
  const sandbox = { document, URL, Promise, JSON, Object, Math, navigator: { language: 'nl' }, crypto: { randomUUID: () => `00000000-0000-4000-8000-${String(++sequence).padStart(12, '0')}` },
    Date: class extends Date { constructor(...args) { super(...(args.length ? args : [now])); } static now() { return now; } },
    location: { origin: 'https://shop.test', pathname: '/collections/all' }, innerWidth: 1200,
    localStorage: storage, sessionStorage: storage, CustomEvent: class { constructor(type, options) { this.type = type; this.detail = options.detail; } },
    ShopBonusConsent: { getConsentId: () => allowed ? 'd9181cd8-5aa7-4fcb-9f9e-5b2f6c04a834' : null }, ShopBonusCart: { setAttribution: (value) => bridge.push(value), getSnapshot: () => cart, subscribe: (callback) => { listeners.cart = callback; } },
    setTimeout(callback, delay) { const t = { callback, delay }; timers.push(t); return t; }, clearTimeout(t) { t.cancelled = true; }, setInterval(callback, delay) { intervals.push({ callback, delay }); },
    addEventListener(name, callback) { listeners[name] = callback; }, dispatchEvent() {},
    fetch: async (url, options = {}) => {
      const body = options.body && JSON.parse(options.body); requests.push({ url, body });
      if (url.includes('/editor/pair')) return { ok: true, json: async () => ({ data: { editor_token: 'editor-secret', expires_in: 1800 } }) };
      if (url.includes('/editor/campaigns/')) return { ok: true, json: async () => ({ data: { id: 'campaign', draft_config: config.config, draft_revision: 1 } }) };
      if (url.includes('/events')) {
        if (failEvents-- > 0) throw new Error('offline');
        return { ok: true, json: async () => ({ data: { click_ids: Object.fromEntries(body.events.filter((e) => e.type === 'cta_click').map((e) => [e.id, 'bc_click'])) } }) };
      }
      if (url.includes('/assignments')) return { ok: true, json: async () => ({ data: body.consent ? [{ campaign: 'campaign', assignment_token: 'token', version: holdout ? 0 : 1, holdout }] : [] }) };
      return { ok: true, json: async () => JSON.parse(JSON.stringify({ data: { editor_origin: 'https://studio.test', tracking_allowed: allowed, campaigns: holdout || !published ? [] : [config] } })) };
    } };
  sandbox.window = sandbox;
  sandbox.parent = sandbox;
  sandbox.opener = { postMessage(data, origin) { messages.push({ data, origin }); } };
  if (observer) sandbox.IntersectionObserver = class { constructor(callback) { this.callback = callback; observers.push(this); } observe(el) { this.el = el; } disconnect() {} };
  vm.runInNewContext(source, sandbox);
  return { requests, reads, writes, bridge, timers, elements, observers, sandbox, config, intervals, messages, listeners,
    message(data, options = {}) { listeners.message({ data: { schema: "shopbonus.banner.v1", key: "pk_test", nonce: "test-nonce", ...data }, origin: "https://studio.test", source: sandbox.opener, ...options }); },
    pause() { published = false; },
    setCart(value) { cart = value; listeners.cart(); },
    async settle() { for (let i = 0; i < 10; i++) await new Promise(setImmediate); },
    async timer(delay) { const t = timers.find((t) => !t.cancelled && !t.ran && t.delay === delay); assert.ok(t, `timer ${delay}`); t.ran = true; now += delay; t.callback(); await this.settle(); },
    async withdraw() { allowed = false; listeners['shopbonus:consent-changed'](); await this.settle(); } };
}

test('no CMP consent: no identifiers, storage reads/writes, assignment or measurement', async () => {
  const h = harness(); await h.settle();
  assert.equal(h.reads.length, 0); assert.equal(h.writes.length, 0);
  assert.equal(h.requests.filter((r) => r.body).length, 0);
});

test('consented assignment reaches cart bridge and versioned events; unsupported visibility stays unknown', async () => {
  const h = harness({ allowed: true }); await h.settle(); await h.timer(1200);
  assert.ok(h.bridge.some((a) => a.assignment_token === 'token'));
  const events = h.requests.flatMap((r) => r.body?.events ?? []);
  assert.ok(events.length > 0); assert.ok(events.every((e) => e.consent_id && e.assignment_token === 'token'));
  assert.ok(!events.some((e) => e.type === 'viewable_impression'));
});

test('failed event delivery retries the same UUID and returns click metadata', async () => {
  const h = harness({ allowed: true, failEvents: 1 }); await h.settle();
  h.elements.find((e) => e.tag === 'a').click(); await h.settle();
  await h.timer(1200);
  const batches = h.requests.filter((r) => r.body?.events).map((r) => r.body.events);
  assert.equal(batches.length, 2); assert.equal(JSON.stringify(batches[0]), JSON.stringify(batches[1]));
  assert.ok(h.bridge.some((a) => a.click_id === 'bc_click'));
});

test('holdout receives assignment metadata without banner impressions', async () => {
  const h = harness({ allowed: true, holdout: true }); await h.settle();
  assert.ok(h.bridge.some((a) => a.assignment_token === 'token'));
  assert.equal(h.elements.filter((e) => e.tag === 'a').length, 0);
  assert.equal(h.requests.filter((r) => r.body?.events).length, 0);
});

test('withdrawal clears pending events and cart metadata and revokes assignment', async () => {
  const h = harness({ allowed: true }); await h.settle(); await h.withdraw();
  assert.ok(h.timers.some((t) => t.delay === 1200 && t.cancelled));
  h.timers.find((t) => t.delay === 1200).callback(); await h.settle();
  assert.equal(h.requests.filter((r) => r.body?.events).length, 0);
  assert.ok(h.requests.some((r) => r.body?.consent === false));
  assert.equal(Object.keys(h.bridge.at(-1)).length, 0);
});

test('an impression requires continuous visible time and a connected element', async () => {
  const h = harness({ allowed: true, observer: true }); await h.settle();
  const observer = h.observers[0]; observer.callback([{ intersectionRatio: 0.6 }]);
  await h.timer(1000); await h.timer(1200);
  assert.equal(h.requests.flatMap((r) => r.body?.events ?? []).filter((e) => e.type === 'viewable_impression').length, 1);
});

test('delivery retries stop after five attempts', async () => {
  const h = harness({ allowed: true, failEvents: 20 }); await h.settle();
  for (const delay of [1200, 2000, 4000, 8000, 16000, 16000]) await h.timer(delay);
  assert.equal(h.requests.filter((r) => r.body?.events).length, 5);
  const batches = h.requests.filter((r) => r.body?.events).map((r) => JSON.stringify(r.body.events));
  assert.equal(new Set(batches).size, 1);
});

test('detached widgets and hidden documents do not generate impressions', async () => {
  for (const hidden of [true, false]) {
    const h = harness({ allowed: true, observer: true }); await h.settle();
    const observer = h.observers[0]; observer.callback([{ intersectionRatio: 0.6 }]);
    if (hidden) h.sandbox.document.visibilityState = 'hidden';
    else observer.el.isConnected = false;
    await h.timer(1000); await h.timer(1200);
    assert.equal(h.requests.flatMap((r) => r.body?.events ?? []).filter((e) => e.type === 'viewable_impression').length, 0);
  }
});


test('popup pairing responds to verified opener and rejects forged envelopes and unauthenticated previews', async () => {
  const h = harness(); await h.settle();
  const originalWidgets = h.elements.filter((e) => e.sbItem).length;
  h.message({ type: 'editor.handshake' }, { origin: 'https://evil.test' });
  h.message({ type: 'editor.handshake' }, { source: { postMessage() {} } });
  assert.equal(h.messages.length, 0);
  h.message({ type: 'editor.handshake' });
  assert.equal(h.messages.at(-1).data.type, 'editor.ready');
  assert.equal(h.messages.at(-1).origin, 'https://studio.test');
  h.message({ type: 'editor.apply_config', campaign: 'campaign', config: h.config.config });
  assert.equal(h.elements.filter((e) => e.sbItem).length, originalWidgets);
  h.message({ type: 'editor.pair', pairing_token: 'single-use', campaign: 'campaign' }); await h.settle();
  assert.ok(h.messages.some((m) => m.data.type === 'editor.paired'));
  const count = h.elements.filter((e) => e.sbItem).length;
  for (const invalid of [{ nonce: 'wrong' }, { schema: 'wrong' }, { key: 'pk_wrong' }]) h.message({ type: 'editor.apply_config', campaign: 'campaign', config: h.config.config, ...invalid });
  h.message({ type: 'editor.apply_config', campaign: 'other', config: h.config.config });
  assert.equal(h.elements.filter((e) => e.sbItem).length, count);
  h.message({ type: 'editor.apply_config', campaign: 'campaign', config: h.config.config });
  assert.equal(h.elements.filter((e) => e.sbItem).length, count + 1);
});

test('visible cart progress updates through subscriptions and rejects stale or mismatched evidence', async () => {
  const snapshot = { schema: 'shopbonus.cart.v1', total_minor: 9400, currency: 'EUR', observed_at: Date.parse('2026-10-31T10:00:00Z') };
  const h = harness({ cart: snapshot }); await h.settle();
  const widget = h.elements.find((e) => e.sbItem), progress = widget.querySelector('.sb-progress');
  assert.match(progress.textContent, /94/); assert.match(progress.textContent, /6/);
  h.setCart({ ...snapshot, total_minor: 9900 }); assert.match(progress.textContent, /99/);
  for (const invalid of [{ currency: 'USD' }, { observed_at: snapshot.observed_at - 31000 }, { total_minor: 1.5 }, { observed_at: snapshot.observed_at + 1000 }]) {
    h.setCart({ ...snapshot, ...invalid }); assert.equal(widget.querySelector('.sb-bar').style.display, 'none');
  }
  h.setCart(snapshot); assert.equal(widget.querySelector('.sb-bar').style.display, 'block');
});

test('bounded refresh replaces a visible version and removes paused publications without reload', async () => {
  const h = harness(); await h.settle();
  const initial = h.elements.find((e) => e.sbItem);
  h.config.version = 2; h.config.config.content.heading = 'Published update';
  h.intervals.find((i) => i.delay === 15000).callback(); await h.settle();
  assert.equal(initial.isConnected, false);
  assert.ok(h.elements.some((e) => e.isConnected && e.sbItem && e.sbItem.version === 2));
  h.config.config.content.heading = 'Rollback'; h.config.version = 1;
  h.intervals.find((i) => i.delay === 15000).callback(); await h.settle();
  assert.ok(h.elements.some((e) => e.isConnected && e.sbItem && e.sbItem.version === 1));
  h.pause(); h.intervals.find((i) => i.delay === 15000).callback(); await h.settle();
  assert.equal(h.elements.filter((e) => e.isConnected && e.sbItem).length, 0);
});


test('authenticated session expiration rejects further preview messages', async () => {
  const h = harness(); await h.settle();
  h.message({ type: 'editor.handshake' });
  h.message({ type: 'editor.pair', pairing_token: 'single-use', campaign: 'campaign' }); await h.settle();
  // Use the harness clock without real waiting.
  h.sandbox.Date.now = () => Date.parse('2026-10-31T11:00:00Z');
  const count = h.elements.filter((e) => e.sbItem).length;
  h.message({ type: 'editor.apply_config', campaign: 'campaign', config: h.config.config }); await h.settle();
  assert.ok(h.messages.some((m) => m.data.type === 'editor.expired'));
  assert.equal(h.elements.filter((e) => e.sbItem && e.sbItem.version === 0).length, count - 1);
});
