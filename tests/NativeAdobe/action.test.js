'use strict'
const test = require('node:test')
const assert = require('node:assert/strict')
const crypto = require('node:crypto')
const action = require('../../merchant-packages/adobe-appbuilder/actions/orders/index.js')
function event() {
  return { SHOPBONUS_ENABLED:'true', SHOPBONUS_ENDPOINT:'https://api.example.com/api/v1/webhooks/magento/'+'a'.repeat(40), SHOPBONUS_SECRET:'s'.repeat(48),
    ADOBE_EVENT_SOURCE:'urn:uuid:SYNTHETIC',COMMERCE_EVENT_SOURCE:'synthetic.Stage',COMMERCE_STORE_ID:'2',COMMERCE_STORE_CODE:'default',
    specversion:'1.0',id:'SYNTHETIC-1',source:'urn:uuid:SYNTHETIC',type:'com.adobe.commerce.observer.sales_order_save_commit_after',
    data:{source:'synthetic.Stage',value:{entity_id:4711,store_id:2,payment:{amount_paid:99999},customer_email:'never-forward@example.test'}} }
}
test('ID-only output, exact signature, stable retry identity and no financial acknowledgement',async()=>{
  const e=event(), record=action.normalize(e)
  assert.deepEqual(JSON.parse(record.body),{shopbonus_magento_lookup_v1:true,store_id:'2',store_code:'default',order_id:'4711'})
  assert.equal(action.normalize(e).eventId,record.eventId)
  assert.equal(action.headers(record,123)['X-Shopbonus-Signature'],'t=123,v1='+crypto.createHmac('sha256',record.secret).update('123.'+record.body).digest('hex'))
  let calls=0;const result=await action.handler(async r=>{calls++;assert.equal(r.body,record.body)})(e)
  assert.equal(calls,1);assert.equal(result.financial_success,false)
})
test('wrong source, environment, store, type, unsafe numeric ID or public deployment input never deliver',async()=>{
  for(const change of [{source:'foreign'},{SHOPBONUS_ENABLED:'false'},{type:'other'},{COMMERCE_STORE_CODE:'all'},{data:{source:'foreign',value:{entity_id:1,store_id:2}}},
    {data:{source:'synthetic.Stage',value:{entity_id:Number.MAX_SAFE_INTEGER+1,store_id:2}}},{SHOPBONUS_ENDPOINT:'http://localhost/private'}]) {
    let calls=0;await assert.rejects(action.handler(async()=>{calls++})({...event(),...change}));assert.equal(calls,0)
  }
})
test('invoice/credit memo changes map order IDs and failed sends throw for provider recovery',async()=>{
  const e=event();e.type='com.adobe.commerce.observer.sales_order_creditmemo_save_commit_after';e.data.value={entity_id:9,order_id:4711,store_id:2}
  assert.equal(JSON.parse(action.normalize(e).body).order_id,'4711')
  let calls=0;await assert.rejects(action.handler(async()=>{calls++;throw Error('native error with secret must not escape')})(e),/delivery unconfirmed/);assert.equal(calls,1)
})
test('public address policy rejects loopback, link-local, private and documentation destinations',()=>{
  for(const ip of ['127.0.0.1','10.1.1.1','169.254.169.254','100.64.0.1','192.168.0.1','192.0.2.1','198.51.100.1','203.0.113.1','::1','::ffff:127.0.0.1','2001:db8::1','2001:0000::1','2002:7f00:1::','3fff::1']) assert.equal(action.publicAddress(ip),false,ip)
  assert.equal(action.publicAddress('93.184.216.34'),true);assert.equal(action.publicAddress('2606:4700::1111'),true)
})
