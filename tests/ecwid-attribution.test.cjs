const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../public/embed/ecwid-attribution-v1.js'), 'utf8');
const TOKEN = 'bx_' + 'a'.repeat(64);

function harness({ loaded = true, consent = 'recorded-consent' } = {}) {
  let now = 1000, refreshes = 0;
  const listeners = {}, timers = [], ready = [];
  const sandbox = {
    ec: { order: { extraFields: { merchant_field: { value: 'keep' }, shopbonus_assignment_token: { value: 'stale' } } } },
    Ecwid: { OnAPILoaded: { add(fn) { ready.push(fn); } }, ...(loaded ? { refreshConfig() { refreshes++; } } : {}) },
    ShopBonusConsent: { getConsentId: () => consent },
    Date: { now: () => now },
    setTimeout(fn, delay) { const timer = { fn, delay }; timers.push(timer); return timer; },
    clearTimeout(timer) { timer.cancelled = true; },
    addEventListener(type, fn) { (listeners[type] ||= []).push(fn); },
  };
  sandbox.window = sandbox;
  vm.runInNewContext(source, sandbox);
  return {
    sandbox, timers, ready, fields: sandbox.ec.order.extraFields,
    send(value) { (listeners['shopbonus:attribution'] || []).forEach(fn => fn({ detail: value && { consent_expires_at: now + 60000, ...value } })); },
    emit(type) { (listeners[type] || []).forEach(fn => fn({})); },
    consent(value) { consent = value; }, advance(ms) { now += ms; },
    get refreshes() { return refreshes; },
  };
}

test('writes the exact holdout or variant assignment invisibly, preserving merchant fields', () => {
  for (const holdout of [true, false]) {
    const h = harness();
    assert.equal(h.fields.shopbonus_assignment_token, undefined);
    h.send({ assignment_token: TOKEN, holdout });
    const field = h.fields.shopbonus_assignment_token;
    assert.equal(field.value, TOKEN);
    assert.equal(field.orderDetailsDisplaySection, 'hidden');
    assert.equal(field.checkoutDisplaySection, undefined);
    assert.equal(field.title, undefined);
    assert.equal(h.fields.merchant_field.value, 'keep');
    assert.equal(h.refreshes, 2);
    assert.equal(h.sandbox.ShopBonusCart, undefined); // Does not claim to implement a native cart reader.
  }
});

test('no token is stored without consent; invalid or missing values clear the prior assignment', () => {
  const h = harness({ consent: null });
  h.send({ assignment_token: TOKEN });
  assert.equal(h.fields.shopbonus_assignment_token, undefined);
  h.consent('recorded-consent');
  for (const bad of [null, {}, { assignment_token: TOKEN + '\n' }, { assignment_token: TOKEN.toUpperCase() }, { assignment_token: 1 }]) {
    h.send({ assignment_token: TOKEN });
    assert.equal(h.fields.shopbonus_assignment_token.value, TOKEN);
    h.send(bad);
    assert.equal(h.fields.shopbonus_assignment_token, undefined);
  }
});

test('withdrawal, CMP changes, page exit and consent lease expiry clear hidden fields', () => {
  for (const action of ['withdraw', 'shopbonus:consent-changed', 'pagehide', 'expire']) {
    const h = harness();
    h.send({ assignment_token: TOKEN });
    if (action === 'withdraw') h.send({});
    else if (action === 'expire') { h.advance(60000); h.timers.at(-1).fn(); }
    else h.emit(action);
    assert.equal(h.fields.shopbonus_assignment_token, undefined, action);
    h.ready[0]();
    assert.equal(h.fields.shopbonus_assignment_token, undefined, 'Late native callbacks must not restore revoked values');
  }
});

test('late native API load uses only the latest unexpired assignment', () => {
  const h = harness({ loaded: false });
  h.send({ assignment_token: TOKEN });
  h.send({ assignment_token: 'bx_' + 'b'.repeat(64) });
  h.sandbox.Ecwid.refreshConfig = () => {};
  h.ready[0]();
  assert.equal(h.fields.shopbonus_assignment_token.value, 'bx_' + 'b'.repeat(64));
  assert.equal(h.timers[0].cancelled, true);
  h.advance(60000);
  h.ready[0]();
  assert.equal(h.fields.shopbonus_assignment_token, undefined);
});

test('honors the embed consent deadline and refuses missing or expired leases', () => {
  const h = harness();
  h.send({ assignment_token: TOKEN, consent_expires_at: 2000 });
  assert.equal(h.timers.at(-1).delay, 1000);
  h.advance(1000);
  h.ready[0]();
  assert.equal(h.fields.shopbonus_assignment_token, undefined);
  for (const until of [undefined, null, 1000, '60000']) {
    h.send({ assignment_token: TOKEN, consent_expires_at: until });
    assert.equal(h.fields.shopbonus_assignment_token, undefined);
  }
});

test('duplicate inclusion and native failures do not duplicate handlers or break the store', () => {
  const h = harness();
  vm.runInNewContext(source, h.sandbox);
  h.sandbox.Ecwid.refreshConfig = () => { throw new Error('native unavailable'); };
  assert.doesNotThrow(() => h.send({ assignment_token: TOKEN }));
  assert.equal(h.timers.length, 1);
  assert.equal(h.ready.length, 1);
  assert.doesNotThrow(() => h.send({}));
  assert.equal(h.fields.shopbonus_assignment_token, undefined);
});
