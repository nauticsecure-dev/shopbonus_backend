const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../merchant-packages/shopify/extensions/shopbonus-banner/assets/shopbonus-loader.js'), 'utf8');

function run(origin, key = 'pk_SYNTHETIC', existing = false) {
  const added = [];
  const listeners = {};
  const marker = { getAttribute: name => name === 'data-origin' ? origin : key };
  const document = {
    readyState: 'complete',
    querySelector: selector => selector.includes('data-shopbonus-install') ? marker : (existing || added.length ? {} : null),
    createElement: () => ({ setAttribute(name, value) { this[name] = value; } }),
    body: { appendChild: node => added.push(node) },
    addEventListener: (name, listener) => { listeners[name] = listener; },
  };
  vm.runInNewContext(source, { document, URL });
  return { added, listeners };
}

test('theme app embed loads the public runtime once with its key', () => {
  const { added, listeners } = run('https://api.example.com');
  assert.equal(added.length, 1);
  assert.equal(added[0].src, 'https://api.example.com/embed/v1.js');
  assert.equal(added[0]['data-shopbonus-key'], 'pk_SYNTHETIC');
  listeners['shopify:section:load']();
  assert.equal(added.length, 1);
});

test('invalid origins and private credentials never become a storefront script', () => {
  for (const origin of ['http://api.example.com', 'https://user:secret@api.example.com', 'https://api.example.com/path', 'https://api.example.com?key=secret', 'javascript:alert(1)']) {
    assert.equal(run(origin).added.length, 0);
  }
  assert.equal(run('https://api.example.com', 'secret').added.length, 0);
  assert.equal(run('https://api.example.com', 'pk_SYNTHETIC', true).added.length, 0);
});
