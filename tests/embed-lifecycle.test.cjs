const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const source = fs.readFileSync(require('node:path').join(__dirname, '../public/embed/v1.js'), 'utf8');
const shopifySource = fs.readFileSync(require('node:path').join(__dirname, '../public/embed/shopify-cart-v1.js'), 'utf8');
const nativeSource = fs.readFileSync(require('node:path').join(__dirname, '../public/embed/native-cart-v1.js'), 'utf8');

function harness({ allowed = false, observer = false, holdout = false, failEvents = 0, cart = null, shopify = false, platform = null, wooUrl = '/store/wp-json/wc/store/v1/cart' } = {}) {
  const requests = [], reads = [], writes = [], bridge = [], timers = [], listeners = {}, observers = [], intervals = [], messages = [];
  const elements = [];
  let now = Date.parse('2026-10-31T10:00:00Z'), sequence = 0;
  let nativeCart = { total_price: 9400, currency: 'EUR' }, nativeFailure = false, nativeHold = null, draftHold = null, pairHold = null;
  if (platform) nativeCart = providerCart(platform, 9400);
  if (['prestashop', 'opencart', 'magento'].includes(platform)) wooUrl = ({ prestashop: '/store/module/shopbonus/cart', opencart: '/store/index.php?route=common%2Fcart.info&language=en-gb', magento: '/store/customer/section/load/?sections=shopbonus_cart' })[platform];
  function listen(name, callback) { const previous = listeners[name]; listeners[name] = previous ? (...args) => { previous(...args); callback(...args); } : callback; }
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
    createElement: element, addEventListener: listen };
  let published = true;
  const config = { reward: { threshold_minor: 10000, currency: 'EUR' }, campaign: 'campaign', version: 1, config: { template: cart || shopify || platform ? 'cart_progress' : 'floating', placement: {}, devices: ['desktop'], content: { cta_label: 'Go', cta_url: 'https://shop.test/reward' } } };
  const sandbox = { document, URL, Promise, JSON, Object, Math, navigator: { language: 'nl' }, crypto: { randomUUID: () => `00000000-0000-4000-8000-${String(++sequence).padStart(12, '0')}` },
    Date: class extends Date { constructor(...args) { super(...(args.length ? args : [now])); } static now() { return now; } },
    location: { origin: 'https://shop.test', pathname: '/collections/all' }, innerWidth: 1200,
    localStorage: storage, sessionStorage: storage, CustomEvent: class { constructor(type, options = {}) { this.type = type; this.detail = options.detail; } },
    ShopBonusConsent: { getConsentId: () => allowed ? 'd9181cd8-5aa7-4fcb-9f9e-5b2f6c04a834' : null }, ShopBonusCart: { setAttribution: (value) => bridge.push(value), getSnapshot: () => cart, subscribe: (callback) => { listeners.cart = callback; } },
    setTimeout(callback, delay) { const t = { callback, delay }; timers.push(t); return t; }, clearTimeout(t) { t.cancelled = true; }, setInterval(callback, delay) { intervals.push({ callback, delay }); },
    addEventListener: listen, dispatchEvent(event) { listeners[event.type]?.(event); },
    fetch: async (url, options = {}) => {
      const body = options.body && JSON.parse(options.body); requests.push({ url, body, options });
      if (url === 'https://shop.test/en/cart.js' || url === new URL(wooUrl, 'https://shop.test').href || url === 'https://shop.test/api/storefront/carts') {
        const value = JSON.parse(JSON.stringify(nativeCart));
        if (nativeHold) await nativeHold;
        if (nativeFailure) throw new Error('native_cart_offline');
        return { ok: true, headers: { get: name => name === 'X-ShopBonus-Cart' ? JSON.stringify(value) : null }, json: async () => value };
      }
      if (url.includes('/editor/pair')) { if (pairHold) await pairHold; return { ok: true, json: async () => ({ data: { editor_token: 'editor-secret', expires_in: 1800 } }) }; }
      if (url.includes('/editor/campaigns/')) { if (draftHold) await draftHold; return { ok: true, json: async () => ({ data: { id: 'campaign', draft_config: config.config, draft_revision: 1 } }) }; }
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
  if (shopify) {
    delete sandbox.ShopBonusCart;
    sandbox.Shopify = { routes: { root: '/en/' } };
    sandbox.AbortController = AbortController;
    vm.runInNewContext(shopifySource, sandbox);
  }
  if (platform) {
    delete sandbox.ShopBonusCart;
    sandbox.AbortController = AbortController;
    const embedScript = document.currentScript;
    document.body = element('body');
    sandbox.prestashop = { on: listen };
    sandbox.jQuery = () => ({ on(names, callback) { names.split(' ').forEach((name) => listen(name, callback)); } });
    document.currentScript = { getAttribute: (key) => ({ 'data-platform': platform, 'data-cart-url': wooUrl })[key] || null };
    vm.runInNewContext(nativeSource, sandbox);
    document.currentScript = embedScript;
  }
  if (observer) sandbox.IntersectionObserver = class { constructor(callback) { this.callback = callback; observers.push(this); } observe(el) { this.el = el; } disconnect() {} };
  vm.runInNewContext(source, sandbox);
  return { requests, reads, writes, bridge, timers, elements, observers, sandbox, config, intervals, messages, listeners,
    message(data, options = {}) { listeners.message({ data: { schema: "shopbonus.banner.v1", key: "pk_test", nonce: "test-nonce", ...data }, origin: "https://studio.test", source: sandbox.opener, ...options }); },
    pause() { published = false; },
    setCart(value) { cart = value; listeners.cart(); },
    nativeCart(value) { nativeCart = value; sandbox.dispatchEvent({ type: 'shopbonus:cart-invalidated' }); },
    nativeFail(value) { nativeFailure = value; },
    holdNative(value) { nativeHold = value; },
    holdDraft(value) { draftHold = value; },
    holdPair(value) { pairHold = value; },
    advance(ms) { now += ms; },
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

test('Shopify dummy native carts update the actual embed after add, quantity, remove and currency changes', async () => {
  const h = harness({ shopify: true }); await h.settle();
  const widget = h.elements.find((e) => e.sbItem), bar = widget.querySelector('.sb-bar');
  assert.equal(bar.firstChild.style.width, '94%');
  for (const [total, width] of [[10000, '100%'], [4700, '47%'], [0, '0%']]) {
    h.nativeCart({ total_price: total, currency: 'EUR' });
    assert.equal(bar.style.display, 'none');
    await h.settle(); assert.equal(bar.firstChild.style.width, width); assert.equal(bar.style.display, 'block');
  }
  h.nativeCart({ total_price: 9400, currency: 'USD' }); await h.settle();
  assert.equal(bar.style.display, 'none');
  assert.equal(h.sandbox.ShopBonusCart.getSnapshot().currency, 'USD');
  h.config.reward.currency = 'USD';
  h.intervals.find((i) => i.delay === 15000).callback(); await h.settle();
  assert.equal(h.elements.find((e) => e.isConnected && e.sbItem).querySelector('.sb-bar').style.display, 'block');
  const nativeRequests = h.requests.filter((r) => r.url.endsWith('/cart.js'));
  assert.equal(nativeRequests.length, 5);
});

test('Shopify native errors, unsupported precision and stale observations fail closed and recover', async () => {
  const h = harness({ shopify: true }); await h.settle();
  const bar = h.elements.find((e) => e.sbItem).querySelector('.sb-bar');
  for (const value of [{ total_price: -1, currency: 'EUR' }, { total_price: 94.1, currency: 'EUR' }, { total_price: 9400, currency: 'JPY' }, { total_price: 9400, currency: 'AAA' }]) {
    h.nativeCart(value); await h.settle(); assert.equal(bar.style.display, 'none'); assert.equal(h.sandbox.ShopBonusCart.getSnapshot(), null);
  }
  h.nativeFail(true); h.nativeCart({ total_price: 9400, currency: 'EUR' }); await h.settle(); assert.equal(bar.style.display, 'none');
  h.nativeFail(false); h.intervals.find((i) => i.delay === 10000).callback(); await h.settle(); assert.equal(bar.style.display, 'block');
  const copy = h.sandbox.ShopBonusCart.getSnapshot(); copy.total_minor = 1;
  assert.equal(h.sandbox.ShopBonusCart.getSnapshot().total_minor, 9400);
  h.advance(31000); h.intervals.find((i) => i.delay === 4000).callback(); assert.equal(bar.style.display, 'none');
  h.intervals.find((i) => i.delay === 10000).callback(); await h.settle(); assert.equal(bar.style.display, 'block');
});

test('Shopify invalidation during a native read discards the old cart and queues one fresh read', async () => {
  const h = harness({ shopify: true }); await h.settle();
  let release; h.holdNative(new Promise((resolve) => { release = resolve; }));
  h.intervals.find((i) => i.delay === 10000).callback();
  h.nativeCart({ total_price: 1000, currency: 'EUR' });
  h.nativeCart({ total_price: 2000, currency: 'EUR' });
  assert.equal(h.sandbox.ShopBonusCart.getSnapshot(), null);
  assert.equal(h.requests.filter((r) => r.url.endsWith('/cart.js')).length, 2);
  h.holdNative(null); release(); await h.settle();
  assert.equal(h.requests.filter((r) => r.url.endsWith('/cart.js')).length, 3);
  assert.equal(h.sandbox.ShopBonusCart.getSnapshot().total_minor, 2000);
  assert.equal(h.elements.find((e) => e.sbItem).querySelector('.sb-bar').firstChild.style.width, '20%');
});

test('Shopify hidden tabs stop native polling and bfcache restore reads a fresh cart', async () => {
  const h = harness({ shopify: true }); await h.settle();
  h.sandbox.document.visibilityState = 'hidden'; h.listeners.visibilitychange();
  h.intervals.find((i) => i.delay === 10000).callback(); await h.settle();
  assert.equal(h.sandbox.ShopBonusCart.getSnapshot(), null);
  assert.equal(h.requests.filter((r) => r.url.endsWith('/cart.js')).length, 1);
  h.listeners.pagehide(); h.sandbox.document.visibilityState = 'visible';
  h.listeners.pageshow(); await h.settle();
  assert.equal(h.requests.filter((r) => r.url.endsWith('/cart.js')).length, 2);
  assert.equal(h.sandbox.ShopBonusCart.getSnapshot().total_minor, 9400);
});

test('a draft read completed after editor disconnect cannot restore private editor content', async () => {
  const h = harness(); await h.settle();
  let release; h.holdDraft(new Promise((resolve) => { release = resolve; }));
  h.message({ type: 'editor.handshake' }); h.message({ type: 'editor.pair', pairing_token: 'single-use', campaign: 'campaign' }); await h.settle();
  h.message({ type: 'editor.disconnect' }); await h.settle();
  release(); await h.settle();
  assert.equal(h.elements.filter((e) => e.isConnected && e.sbItem?.version === 0).length, 0);
  assert.ok(!h.messages.some((m) => m.data.type === 'editor.paired'));
});

test('disconnect while pairing discards the late permission exchange', async () => {
  const h = harness(); await h.settle();
  let release; h.holdPair(new Promise((resolve) => { release = resolve; }));
  h.message({ type: 'editor.handshake' }); h.message({ type: 'editor.pair', pairing_token: 'single-use', campaign: 'campaign' });
  h.message({ type: 'editor.disconnect' }); release(); await h.settle();
  assert.equal(h.requests.filter((r) => r.url.includes('/editor/campaigns/')).length, 0);
  assert.ok(!h.messages.some((m) => m.data.type === 'editor.paired'));
});

test('a draft response arriving after expiry never renders a private widget', async () => {
  const h = harness(); await h.settle();
  let release; h.holdDraft(new Promise((resolve) => { release = resolve; }));
  h.message({ type: 'editor.handshake' }); h.message({ type: 'editor.pair', pairing_token: 'single-use', campaign: 'campaign' }); await h.settle();
  h.advance(1800001); release(); await h.settle();
  assert.equal(h.elements.filter((e) => e.isConnected && e.sbItem?.version === 0).length, 0);
  assert.ok(!h.messages.some((m) => m.data.type === 'editor.paired'));
});

test('Shopify timeout ignores a late response and subscriptions can unsubscribe', async () => {
  const h = harness({ shopify: true }); await h.settle();
  let calls = 0; const unsubscribe = h.sandbox.ShopBonusCart.subscribe(() => { calls++; });
  let release; h.holdNative(new Promise((resolve) => { release = resolve; }));
  h.intervals.find((i) => i.delay === 10000).callback();
  await h.timer(5000); assert.equal(h.sandbox.ShopBonusCart.getSnapshot(), null);
  assert.equal(calls, 1); unsubscribe();
  release(); await h.settle(); assert.equal(h.sandbox.ShopBonusCart.getSnapshot(), null);
  h.holdNative(null); h.intervals.find((i) => i.delay === 10000).callback(); await h.settle();
  assert.equal(h.sandbox.ShopBonusCart.getSnapshot().total_minor, 9400); assert.equal(calls, 1);
});

test('Shopify adapter preserves an existing bridge and rejects absent or foreign route configuration', () => {
  for (const routes of [null, { root: 'https://evil.test/' }, { root: '/en/?bad=1' }, { root: '/en/#bad' }, { root: '/en' }]) {
    const sandbox = { window: null, Shopify: routes ? { routes } : undefined, location: { origin: 'https://shop.test' }, URL, AbortController };
    sandbox.window = sandbox; vm.runInNewContext(shopifySource, sandbox); assert.equal(sandbox.ShopBonusCart, undefined);
  }
  const existing = { getSnapshot() {} }; const sandbox = { window: { ShopBonusCart: existing } };
  vm.runInNewContext(shopifySource, sandbox); assert.equal(sandbox.window.ShopBonusCart, existing);
  for (const pathname of ['/en/checkout', '/en/checkouts/private', '/en/account', '/checkout']) {
    const sandbox = { window: null, Shopify: { routes: { root: '/en/' } }, location: { origin: 'https://shop.test', pathname }, URL, AbortController };
    sandbox.window = sandbox; vm.runInNewContext(shopifySource, sandbox); assert.equal(sandbox.ShopBonusCart, undefined);
  }
});

function providerCart(platform, minor, currency = 'EUR') {
  if (['prestashop', 'opencart', 'magento'].includes(platform)) {
    const value = { schema: 'shopbonus.cart-source.v1', platform, total_minor: minor, currency, minor_unit: 2 };
    return platform === 'magento' ? { shopbonus_cart: value } : value;
  }
  return platform === 'woocommerce'
    ? { totals: { total_price: String(minor), currency_code: currency, currency_minor_unit: 2 } }
    : [{ cartAmount: minor / 100, currency: { code: currency, decimalPlaces: 2 } }];
}
for (const platform of ['woocommerce', 'bigcommerce', 'prestashop', 'opencart', 'magento']) {
  test(`${platform}: native cart drives actual banner without consent or tracking`, async () => {
    const h = harness({ platform }); await h.settle();
    const bar = h.elements.find((e) => e.sbItem).querySelector('.sb-bar');
    assert.equal(bar.firstChild.style.width, '94%');
    for (const [minor, width] of [[12000, '100%'], [3000, '30%'], [0, '0%']]) {
      h.nativeCart(providerCart(platform, minor));
      assert.equal(bar.style.display, 'none');
      await h.settle(); assert.equal(bar.firstChild.style.width, width); assert.equal(bar.style.display, 'block');
    }
    h.nativeCart(providerCart(platform, 9400, 'USD')); await h.settle(); assert.equal(bar.style.display, 'none');
    assert.equal(h.reads.length, 0); assert.equal(h.writes.length, 0);
    for (const request of h.requests.filter((r) => r.url.startsWith('https://shop.test/'))) {
      assert.equal(request.options.credentials, 'same-origin'); assert.equal(request.options.cache, 'no-store');
      assert.equal(request.options.redirect, 'error'); assert.equal(request.body, undefined);
    }
  });
  test(`${platform}: malformed precision, empty responses and outages hide progress and recover`, async () => {
    const h = harness({ platform }); await h.settle();
    const bar = h.elements.find((e) => e.sbItem).querySelector('.sb-bar');
    const invalid = ['prestashop', 'opencart', 'magento'].includes(platform)
      ? [null, {}, platform === 'magento' ? { shopbonus_cart: { ...providerCart('prestashop', 9400), platform: 'magento', minor_unit: 3 } } : { ...providerCart(platform, 9400), total_minor: '9400' }, providerCart(platform === 'magento' ? 'prestashop' : 'magento', 9400)]
      : platform === 'woocommerce'
      ? [null, {}, { totals: { total_price: '9400.0', currency_minor_unit: 2, currency_code: 'EUR' } }, { totals: { total_price: 9400, currency_minor_unit: 2, currency_code: 'EUR' } }, { totals: { total_price: '9400', currency_minor_unit: 0, currency_code: 'EUR' } }]
      : [null, [], [{ cartAmount: 94.001, currency: { code: 'EUR', decimalPlaces: 2 } }], [{ cartAmount: '94', currency: { code: 'EUR', decimalPlaces: 2 } }], [{ cartAmount: 94, currency: { code: 'EUR', decimalPlaces: 3 } }], [...providerCart(platform, 9400), ...providerCart(platform, 100)]];
    for (const value of [...invalid, providerCart(platform, -1), providerCart(platform, 9400, 'JPY'), providerCart(platform, 9400, 'AAA'), providerCart(platform, Number.MAX_SAFE_INTEGER * 2)]) {
      h.nativeCart(value); await h.settle(); assert.equal(h.sandbox.ShopBonusCart.getSnapshot(), null); assert.equal(bar.style.display, 'none');
    }
    h.nativeFail(true); h.nativeCart(providerCart(platform, 9400)); await h.settle(); assert.equal(bar.style.display, 'none');
    h.nativeFail(false); h.sandbox.ShopBonusCart.refresh(); await h.settle(); assert.equal(bar.style.display, 'block');
    const copy = h.sandbox.ShopBonusCart.getSnapshot(); copy.total_minor = 1;
    assert.equal(h.sandbox.ShopBonusCart.getSnapshot().total_minor, 9400);
    h.advance(31000); h.intervals.find((i) => i.delay === 4000).callback(); assert.equal(bar.style.display, 'none');
    h.sandbox.ShopBonusCart.refresh(); await h.settle(); assert.equal(bar.style.display, 'block');
  });
  test(`${platform}: coalesces in-flight invalidations and ignores timeout responses`, async () => {
    const h = harness({ platform }); await h.settle();
    let release; h.holdNative(new Promise((resolve) => { release = resolve; }));
    h.intervals.find((i) => i.delay === 10000).callback(); await h.settle();
    h.nativeCart(providerCart(platform, 1000)); h.nativeCart(providerCart(platform, 2000));
    assert.equal(h.sandbox.ShopBonusCart.getSnapshot(), null);
    h.holdNative(null); release(); await h.settle();
    assert.equal(h.requests.filter((r) => r.url.startsWith('https://shop.test/')).length, 3);
    assert.equal(h.sandbox.ShopBonusCart.getSnapshot().total_minor, 2000);
    let calls = 0; const unsubscribe = h.sandbox.ShopBonusCart.subscribe(() => { calls++; });
    h.holdNative(new Promise((resolve) => { release = resolve; }));
    h.intervals.find((i) => i.delay === 10000).callback(); await h.settle(); await h.timer(5000);
    assert.equal(h.sandbox.ShopBonusCart.getSnapshot(), null); assert.equal(calls, 1); unsubscribe();
    release(); await h.settle(); assert.equal(h.sandbox.ShopBonusCart.getSnapshot(), null);
    h.holdNative(null); h.sandbox.ShopBonusCart.refresh(); await h.settle(); assert.equal(calls, 1);
    assert.equal(h.sandbox.ShopBonusCart.getSnapshot().total_minor, 2000);
  });
  test(`${platform}: hidden/pagehide discard reads; pageshow restores; sensitive SPA route stops reads`, async () => {
    const h = harness({ platform }); await h.settle();
    h.sandbox.document.visibilityState = 'hidden'; h.listeners.visibilitychange();
    h.intervals.find((i) => i.delay === 10000).callback(); await h.settle();
    assert.equal(h.requests.filter((r) => r.url.startsWith('https://shop.test/')).length, 1);
    assert.equal(h.sandbox.ShopBonusCart.getSnapshot(), null);
    h.sandbox.document.visibilityState = 'visible'; h.listeners.pageshow(); await h.settle();
    let release; h.holdNative(new Promise((resolve) => { release = resolve; }));
    h.sandbox.ShopBonusCart.refresh(); await h.settle(); h.listeners.pagehide();
    release(); await h.settle(); assert.equal(h.sandbox.ShopBonusCart.getSnapshot(), null);
    h.holdNative(null); h.listeners.pageshow(); await h.settle(); assert.equal(h.sandbox.ShopBonusCart.getSnapshot().total_minor, 9400);
    const count = h.requests.length; h.sandbox.location.pathname = '/nl/checkout';
    h.intervals.find((i) => i.delay === 10000).callback(); await h.settle();
    assert.equal(h.sandbox.ShopBonusCart.getSnapshot(), null); assert.equal(h.requests.length, count);
  });
}
test('native reader requires explicit platform, preserves bridges and rejects unsafe endpoints and routes', () => {
  function load(platform, endpoint, pathname = '/products') {
    const s = { document: { currentScript: { getAttribute: (k) => k === 'data-platform' ? platform : endpoint } }, URL, AbortController,
      location: { origin: 'https://shop.test', pathname }, fetch() {}, window: null };
    s.window = s; vm.runInNewContext(nativeSource, s); return s;
  }
  for (const url of ['https://evil.test/wp-json/wc/store/v1/cart', 'https://user:pass@shop.test/wp-json/wc/store/v1/cart', '/wp-json/wc/store/v1/cart#x', '/wp-json/wc/store/v1/cart?x=1', '/?rest_route=/wc/store/v1/cart&x=1', '/?rest_route=/wc/v3/orders', null]) {
    assert.equal(load('woocommerce', url).ShopBonusCart, undefined);
  }
  assert.equal(load('wix', '/wp-json/wc/store/v1/cart').ShopBonusCart, undefined);
  for (const pathname of ['/checkout', '/nl/my-account', '/checkout.php', '/account.php', '/store/order-received/1']) {
    assert.equal(load('woocommerce', '/wp-json/wc/store/v1/cart', pathname).ShopBonusCart, undefined);
  }
  const existing = {}; vm.runInNewContext(nativeSource, { window: { ShopBonusCart: existing } });
});

test('WooCommerce classic and Blocks notifications trigger native rereads', async () => {
  const h = harness({ platform: 'woocommerce' }); await h.settle();
  const before = h.requests.length;
  h.listeners['wc-blocks_added_to_cart'](); await h.settle();
  h.listeners['wc-blocks_removed_from_cart'](); await h.settle();
  for (const name of ['added_to_cart', 'removed_from_cart', 'updated_wc_div', 'wc_fragments_refreshed']) { h.listeners[name](); await h.settle(); }
  assert.equal(h.requests.length, before + 6);
});

test('WooCommerce plain WordPress REST routing reads the native cart', async () => {
  const h = harness({ platform: 'woocommerce', wooUrl: '/store/index.php?rest_route=%2Fwc%2Fstore%2Fv1%2Fcart' }); await h.settle();
  assert.equal(h.sandbox.ShopBonusCart.getSnapshot().total_minor, 9400);
});

function adapterSandbox(source, attributes, extra = {}) {
  const calls = [];
  const s = Object.assign({
    document: { currentScript: { getAttribute: (k) => attributes[k] ?? null }, visibilityState: 'visible', addEventListener() {} },
    URL, URLSearchParams, AbortController, JSON, Promise, Date, location: { origin: 'https://shop.test', pathname: '/products' },
    CustomEvent: class { constructor(type) { this.type = type; } }, addEventListener() {}, dispatchEvent() {},
    setTimeout() { return {}; }, clearTimeout() {}, setInterval() {},
    fetch: async (url, options = {}) => { calls.push({ url, options }); return { ok: true, json: async () => ({ total_price: 0, currency: 'EUR', totals: { total_price: '0', currency_code: 'EUR', currency_minor_unit: 2 } }) }; },
  }, extra);
  s.window = s;
  vm.runInNewContext(source, s);
  return { s, calls };
}
const TOKEN = 'bx_' + 'a'.repeat(64), CLICK = 'bc_' + 'A1'.repeat(12);

test('WooCommerce reader persists only exact assignment values through the plugin bridge and clears on withdrawal', async () => {
  const attrs = { 'data-platform': 'woocommerce', 'data-cart-url': '/wp-json/wc/store/v1/cart', 'data-attribution-url': '/wp-admin/admin-ajax.php', 'data-attribution-nonce': 'abc123' };
  const { s, calls } = adapterSandbox(nativeSource, attrs);
  await s.ShopBonusCart.setAttribution({ assignment_token: TOKEN, click_id: CLICK });
  const sent = calls.find((c) => c.options.method === 'POST');
  assert.equal(sent.url, 'https://shop.test/wp-admin/admin-ajax.php');
  assert.equal(sent.options.credentials, 'same-origin');
  assert.deepEqual(Object.fromEntries(sent.options.body), { action: 'shopbonus_attribution', nonce: 'abc123', assignment_token: TOKEN, click_id: CLICK });

  calls.length = 0;
  await s.ShopBonusCart.setAttribution({ assignment_token: 'token', click_id: 'bc_short' });
  assert.deepEqual(Object.fromEntries(calls[0].options.body), { action: 'shopbonus_attribution', nonce: 'abc123' }); // invalid values never sent; clears
  await s.ShopBonusCart.setAttribution({});
  assert.deepEqual(Object.fromEntries(calls[1].options.body), { action: 'shopbonus_attribution', nonce: 'abc123' });

  for (const url of ['https://evil.test/wp-admin/admin-ajax.php', '/wp-admin/admin-ajax.php?x=1', '/wp-json/shopbonus']) {
    assert.equal(adapterSandbox(nativeSource, { ...attrs, 'data-attribution-url': url }).s.ShopBonusCart.setAttribution, undefined);
  }
  assert.equal(adapterSandbox(nativeSource, { ...attrs, 'data-attribution-nonce': '<script>' }).s.ShopBonusCart.setAttribution, undefined);
  assert.equal(adapterSandbox(nativeSource, { 'data-platform': 'woocommerce', 'data-cart-url': '/wp-json/wc/store/v1/cart' }).s.ShopBonusCart.setAttribution, undefined);
});

test('Shopify reader writes cart attributes only when the merchant opts in', async () => {
  const shopify = { Shopify: { routes: { root: '/en/' } } };
  assert.equal(adapterSandbox(shopifySource, {}, shopify).s.ShopBonusCart.setAttribution, undefined);
  const { s, calls } = adapterSandbox(shopifySource, { 'data-persist-attribution': 'true' }, shopify);
  await s.ShopBonusCart.setAttribution({ assignment_token: TOKEN, click_id: CLICK });
  const sent = calls.find((c) => c.options.method === 'POST');
  assert.equal(sent.url, 'https://shop.test/en/cart/update.js');
  assert.deepEqual(JSON.parse(sent.options.body), { attributes: { shopbonus_assignment_token: TOKEN, shopbonus_click_id: CLICK } });
  calls.length = 0;
  await s.ShopBonusCart.setAttribution({});
  assert.deepEqual(JSON.parse(calls.find((c) => c.options.method === 'POST').options.body), { attributes: { shopbonus_assignment_token: '', shopbonus_click_id: '' } });
});


test('native package routes reject cross-origin, duplicate routing keys and private cart selectors', () => {
  function load(platform, url, search = '') {
    const s = { window: null, URL, AbortController, fetch() { throw new Error('Unsafe route must not fetch'); },
      location: { origin: 'https://shop.test', pathname: '/index.php', search },
      document: { currentScript: { getAttribute: k => ({ 'data-platform': platform, 'data-cart-url': url })[k] } } };
    s.window = s; vm.runInNewContext(nativeSource, s); return s;
  }
  for (const [platform, url] of [
    ['prestashop', 'https://foreign.test/module/shopbonus/cart'],
    ['prestashop', '/module/shopbonus/cart?id_cart=77'],
    ['prestashop', '/index.php?fc=module&module=shopbonus&controller=cart&controller=order'],
    ['prestashop', '/index.php?fc=module&module=other&controller=cart'],
    ['opencart', '/index.php?route=common/cart.info&cart_id=77'],
    ['opencart', '/index.php?route=common/cart.info&route=checkout/checkout'],
    ['magento', '/customer/section/load/?sections=cart'],
    ['magento', '/customer/section/load/?sections=shopbonus_cart&SID=secret'],
  ]) assert.equal(load(platform, url).ShopBonusCart, undefined);
  for (const search of ['?route=checkout/checkout', '?route=account/login', '?controller=order', '?controller=authentication']) {
    assert.equal(load('opencart', '/index.php?route=common/cart.info', search).ShopBonusCart, undefined);
  }
});

test('PrestaShop and Magento native notifications refetch their session source', async () => {
  for (const [platform, events] of [['prestashop', ['updateCart', 'updatedCart']], ['magento', ['ajax:addToCart', 'ajax:removeFromCart']]]) {
    const h = harness({ platform }); await h.settle();
    for (const name of events) { h.listeners[name](); await h.settle(); }
    assert.equal(h.requests.filter(r => r.url.startsWith('https://shop.test/')).length, 3);
  }
});
