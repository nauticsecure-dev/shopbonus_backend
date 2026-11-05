'use strict'
const crypto = require('node:crypto')
const https = require('node:https')
const dns = require('node:dns').promises
const net = require('node:net')

const types = {
  'com.adobe.commerce.observer.sales_order_save_commit_after': 'entity_id',
  'com.adobe.commerce.observer.sales_order_invoice_save_commit_after': 'order_id',
  'com.adobe.commerce.observer.sales_order_creditmemo_save_commit_after': 'order_id'
}
function id(value) {
  if (typeof value === 'number' && !Number.isSafeInteger(value)) throw Error('invalid_native_id')
  const out = String(value)
  if (!/^[1-9][0-9]{0,18}$/.test(out)) throw Error('invalid_native_id')
  return out
}
function normalize(p) {
  // Non-web Runtime Action registration is mandatory: Adobe I/O's validator verifies
  // the original event signature before invocation. Never deploy this as a web action.
  if (p.SHOPBONUS_ENABLED !== 'true' || p.specversion !== '1.0' || typeof p.id !== 'string' || !p.id || p.id.length > 300 ||
      typeof p.ADOBE_EVENT_SOURCE !== 'string' || !p.ADOBE_EVENT_SOURCE || p.ADOBE_EVENT_SOURCE.length > 512 || p.source !== p.ADOBE_EVENT_SOURCE ||
      typeof p.COMMERCE_EVENT_SOURCE !== 'string' || !p.COMMERCE_EVENT_SOURCE || p.COMMERCE_EVENT_SOURCE.length > 300 || p.data?.source !== p.COMMERCE_EVENT_SOURCE || !Object.hasOwn(types, p.type)) throw Error('event_scope_invalid')
  const value = p.data?.value
  const store = id(p.COMMERCE_STORE_ID)
  if (!value || id(value.store_id) !== store || typeof p.COMMERCE_STORE_CODE !== 'string' || !/^[A-Za-z0-9_]{1,64}$/.test(p.COMMERCE_STORE_CODE) || p.COMMERCE_STORE_CODE === 'all') throw Error('store_scope_invalid')
  const endpoint = new URL(p.SHOPBONUS_ENDPOINT)
  if (endpoint.protocol !== 'https:' || endpoint.username || endpoint.password || endpoint.port || endpoint.search || endpoint.hash || !/^[a-z0-9.-]+\.[a-z]{2,}$/.test(endpoint.hostname) || !/^\/api\/v1\/webhooks\/magento\/[A-Za-z0-9]{40}$/.test(endpoint.pathname) ||
      typeof p.SHOPBONUS_SECRET !== 'string' || !/^[A-Za-z0-9]{32,128}$/.test(p.SHOPBONUS_SECRET)) throw Error('delivery_config_invalid')
  const body = JSON.stringify({ shopbonus_magento_lookup_v1: true, store_id: store, store_code: p.COMMERCE_STORE_CODE, order_id: id(value[types[p.type]]) })
  return { endpoint, secret: p.SHOPBONUS_SECRET, eventId: crypto.createHash('sha256').update(p.source + '|' + p.id).digest('hex').slice(0, 32), body }
}
function headers(record, time = Math.floor(Date.now() / 1000)) {
  return { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(record.body), 'X-Shopbonus-Topic': 'order.updated',
    'X-Shopbonus-Event-Id': record.eventId, 'X-Shopbonus-Signature': 't=' + time + ',v1=' + crypto.createHmac('sha256', record.secret).update(time + '.' + record.body).digest('hex') }
}
function publicAddress(address) {
  const family = net.isIP(address)
  if (family === 6) {
    const parts = address.toLowerCase().split('::')
    const left = parts[0] ? parts[0].split(':') : []
    const right = parts.length === 2 && parts[1] ? parts[1].split(':') : []
    const words = parts.length === 2 ? [...left, ...Array(8-left.length-right.length).fill('0'), ...right] : left
    const first = parseInt(words[0],16), second = parseInt(words[1],16)
    return first >= 0x2000 && first <= 0x3fff && !(first === 0x2001 && (second < 0x200 || second === 0xdb8)) && first !== 0x2002 && !(first === 0x3fff && second < 0x1000)
  }
  if (family !== 4) return false
  const [a,b,c] = address.split('.').map(Number)
  return !(a === 0 || a === 10 || a === 127 || a >= 224 || (a === 100 && b >= 64 && b <= 127) || (a === 169 && b === 254) ||
    (a === 172 && b >= 16 && b <= 31) || (a === 192 && (b === 168 || (b === 88 && c === 99) || (b === 0 && (c === 0 || c === 2)))) ||
    (a === 198 && (b === 18 || b === 19 || (b === 51 && c === 100))) || (a === 203 && b === 0 && c === 113))
}
async function send(record) {
  let timer
  const controller = new AbortController()
  try {
    await Promise.race([
      (async () => {
        const addresses = await dns.lookup(record.endpoint.hostname, { all: true })
        if (!addresses.length || addresses.some(a => !publicAddress(a.address))) throw Error('destination_refused')
        if (controller.signal.aborted) throw Error('delivery_unconfirmed')
        const pin = addresses[0]
        await new Promise((resolve, reject) => {
          const req = https.request(record.endpoint, { method: 'POST', agent: false, rejectUnauthorized: true, headers: headers(record), signal: controller.signal,
            lookup: (_host, opts, callback) => opts.all ? callback(null, [pin]) : callback(null, pin.address, pin.family) }, response => {
            let bytes = 0
            response.on('data', chunk => { bytes += chunk.length; if (bytes > 65536) { response.destroy(); reject(Error('response_limit')) } })
            response.on('error', () => reject(Error('delivery_unconfirmed')))
            response.on('end', () => [200,202].includes(response.statusCode) ? resolve() : reject(Error('delivery_unconfirmed')))
          })
          req.on('error', () => reject(Error('delivery_unconfirmed')))
          req.end(record.body)
        })
      })(),
      new Promise((_resolve, reject) => { timer = setTimeout(() => { controller.abort(); reject(Error('delivery_unconfirmed')) }, 8000) })
    ])
  } finally { clearTimeout(timer); controller.abort() }
}
function handler(deliver) {
  return async p => {
    try { const record = normalize(p); await deliver(record); return { acknowledged: true, event_id: record.eventId, financial_success: false } }
    catch { throw Error('ShopBonus asynchronous delivery unconfirmed; inspect configuration and retry evidence.') }
  }
}
exports.main = handler(send)
exports.normalize = normalize
exports.headers = headers
exports.publicAddress = publicAddress
exports.handler = handler
