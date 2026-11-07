"""Isolated backend protocol journey: actual HTTP, Reverb private events and queue:work.
A synthetic Node WebSocket client is not a deployed frontend/browser acceptance test.
"""
import base64,json,os,pathlib,signal,socket,subprocess,tempfile,time,urllib.request,urllib.error,shutil
root=pathlib.Path.cwd();work=pathlib.Path(tempfile.mkdtemp(prefix='shopbonus-receipts-'));processes=[]
def port():
 with socket.socket() as s:s.bind(('127.0.0.1',0));return s.getsockname()[1]
http_port,reverb_port=port(),port()
for d in ['framework/cache/data','framework/sessions','framework/views','logs','app/private']:(work/'storage'/d).mkdir(parents=True,exist_ok=True)
db=work/'probe.sqlite';db.touch();env=os.environ.copy()
env.update({'APP_ENV':'local','APP_DEBUG':'false','APP_URL':f'http://127.0.0.1:{http_port}','APP_KEY':'base64:'+base64.b64encode(os.urandom(32)).decode(),'DB_CONNECTION':'sqlite','DB_DATABASE':str(db),'DB_URL':'','CACHE_STORE':'database','CACHE_PREFIX':'synthetic-support-receipts','SESSION_DRIVER':'array','QUEUE_CONNECTION':'database','DB_QUEUE':'receipt-proof','MAIL_MAILER':'array','BROADCAST_CONNECTION':'reverb','ADMIN_MFA_REQUIRED':'false','OWNER_MFA_REQUIRED':'false','OWNER_STEP_UP_REQUIRED':'false','LARAVEL_STORAGE_PATH':str(work/'storage'),'REVERB_APP_ID':'synthetic-receipts','REVERB_APP_KEY':os.urandom(12).hex(),'REVERB_APP_SECRET':os.urandom(24).hex(),'REVERB_HOST':'127.0.0.1','REVERB_PORT':str(reverb_port),'REVERB_SCHEME':'http','REVERB_ALLOWED_ORIGINS':'*','SUPPORT_AI_REVERB_PROBE_ORIGIN':f'http://127.0.0.1:{http_port}','RECEIPTS_PRIVATE':str(work/'private.json')})
bootstrap="require getcwd().'/vendor/autoload.php';$app=require getcwd().'/bootstrap/app.php';$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();"
def php(body):
 f=work/'command.php';f.write_text('<?php '+bootstrap+body);return subprocess.run(['php',str(f)],cwd=root,env=env,check=True,capture_output=True,text=True).stdout
captures=[]
def http(label,method,path,body=None,actor='admin',expected=200):
 headers={'Accept':'application/json','Content-Type':'application/json'}
 if actor:headers['Authorization']='Bearer '+accounts[actor]
 try:r=urllib.request.urlopen(urllib.request.Request(f'http://127.0.0.1:{http_port}'+path,headers=headers,method=method,data=json.dumps(body).encode() if body is not None else None),timeout=10)
 except urllib.error.HTTPError as e:r=e
 data=json.loads(r.read());assert r.status==expected,(label,r.status,data)
 captures.append({'label':label,'request':{'method':method,'path':path,'body':body,'authorization':'Bearer <SYNTHETIC_TOKEN>' if actor else None},'response':{'status':r.status,'body':data,'headers':{k:r.headers.get(k) for k in ['X-Request-Id','Cache-Control']}}});return data
try:
 subprocess.run(['php','artisan','migrate','--force'],cwd=root,env=env,check=True,stdout=subprocess.DEVNULL)
 php("$a=App\\Models\\User::factory()->create();$a->platformRoles()->create(['role'=>'super_admin','granted_via'=>'console','granted_at'=>now()]);$b=App\\Models\\User::factory()->create();$b->platformRoles()->create(['role'=>'super_admin','granted_via'=>'console','granted_at'=>now()]);$c=App\\Models\\User::factory()->create();file_put_contents(getenv('RECEIPTS_PRIVATE'),json_encode(['admin'=>$a->createToken('synthetic')->plainTextToken,'other'=>$b->createToken('synthetic')->plainTextToken,'consumer'=>$c->createToken('synthetic')->plainTextToken]));chmod(getenv('RECEIPTS_PRIVATE'),0600);")
 accounts=json.loads((work/'private.json').read_text())
 router=work/'router.php';router.write_text("<?php $_ENV['LARAVEL_STORAGE_PATH']=getenv('LARAVEL_STORAGE_PATH');require getcwd().'/vendor/autoload.php';$app=require getcwd().'/bootstrap/app.php';$app->handleRequest(Illuminate\\Http\\Request::capture());")
 for args in [['php','artisan','reverb:start','--host=127.0.0.1',f'--port={reverb_port}'],['php','-S',f'127.0.0.1:{http_port}','-t',str(root/'public'),str(router)]]:
  log=open(work/(str(len(processes))+'.log'),'w');processes.append(subprocess.Popen(args,cwd=root,env=env,stdout=log,stderr=log,start_new_session=True))
 for p in [http_port,reverb_port]:
  for _ in range(100):
   try:
    with socket.create_connection(('127.0.0.1',p),timeout=.1):break
   except OSError:time.sleep(.1)
  else:raise RuntimeError('Local service did not become ready.')
 # Actual queued synthetic job; observe pending before a real worker process starts.
 php('app(App\\Services\\Support\\SupportConnectivity::class)->run();')
 pending=http('worker_pending','GET','/api/v1/admin/ai/operations');assert pending['data']['connectivity']['worker_probe']=='pending'
 subprocess.run(['php','artisan','queue:work','database','--queue=receipt-proof','--stop-when-empty','--tries=1','--timeout=10'],cwd=root,env=env,check=True,stdout=subprocess.DEVNULL,timeout=20)
 observed=http('worker_processed','GET','/api/v1/admin/ai/operations');assert observed['data']['connectivity']['worker_probe']=='processed'
 # Actual authorized private subscription, event-only challenge, and HTTP acknowledgement.
 client=work/'client.mjs';client.write_text(r'''
import fs from 'node:fs';import assert from 'node:assert/strict';
const accounts=JSON.parse(fs.readFileSync(process.env.RECEIPTS_PRIVATE,'utf8'));const base=process.env.RECEIPTS_HTTP;const captures=[];
async function call(label,method,path,body,actor='admin',expected=200){
 const headers={'Accept':'application/json','Content-Type':'application/json'};if(actor)headers.Authorization='Bearer '+accounts[actor];
 const r=await fetch(base+path,{method,headers,body:body===undefined?undefined:JSON.stringify(body)});const data=await r.json();assert.equal(r.status,expected,label);
 captures.push({label,request:{method,path,body:body??null,authorization:actor?'Bearer <SYNTHETIC_TOKEN>':null},response:{status:r.status,body:data,headers:{'X-Request-Id':r.headers.get('x-request-id'),'Cache-Control':r.headers.get('cache-control')}}});return data;
}
const ws=new WebSocket(process.env.RECEIPTS_WS);let backlog=[];let waits=[];
ws.addEventListener('message',e=>{const m=JSON.parse(e.data);const waiter=waits.find(w=>w.event===m.event);if(waiter){waits=waits.filter(w=>w!==waiter);waiter.resolve(m);}else backlog.push(m);});
async function next(event){const index=backlog.findIndex(m=>m.event===event);if(index>=0)return backlog.splice(index,1)[0];return new Promise((resolve,reject)=>{const timer=setTimeout(()=>reject(new Error('Expected WebSocket event missing: '+event)),8000);waits.push({event,resolve:m=>{clearTimeout(timer);resolve(m);}});});}
try{
 const hello=JSON.parse((await next('pusher:connection_established')).data);
 const operation=await call('browser_before','GET','/api/v1/admin/ai/operations');assert.equal(operation.data.connectivity.browser_probe,'not_measured');
 // The channel returned by start is stable per user; obtain it without exposing a receipt challenge.
 const first=await call('browser_start_initial','POST','/api/v1/admin/ai/connectivity/browser-probe',{} , 'admin',201);assert.equal(first.data.nonce,undefined);
 const channel=first.data.channel;
 const auth=await call('private_channel_authorization','POST','/api/broadcasting/auth',{socket_id:hello.socket_id,channel_name:channel});
 ws.send(JSON.stringify({event:'pusher:subscribe',data:{channel,auth:auth.auth}}));const subscribed=await next('pusher_internal:subscription_succeeded');assert.equal(subscribed.channel,channel);
 const started=await call('browser_start','POST','/api/v1/admin/ai/connectivity/browser-probe',{},'admin',201);assert.equal(started.data.nonce,undefined);
 const event=await next('support.connectivity.browser_probe');assert.equal(event.channel,channel);const payload=typeof event.data==='string'?JSON.parse(event.data):event.data;
 assert.equal(payload.probe_id,started.data.probe_id);assert.notEqual(payload.nonce,started.data.probe_id);
 const path='/api/v1/admin/ai/connectivity/browser-probe/'+started.data.probe_id+'/ack';
 await call('http_only_rejected','POST',path,{nonce:started.data.probe_id},'admin',404);
 await call('missing_challenge','POST',path,{},'admin',422);
 await call('other_admin_rejected','POST',path,{nonce:payload.nonce},'other',404);
 const ack=await call('event_receipt_ack','POST',path,{nonce:payload.nonce});assert.equal(ack.data.browser_probe,'acknowledged');
 await call('replay_rejected','POST',path,{nonce:payload.nonce},'admin',404);
 const own=await call('owner_monitoring','GET','/api/v1/admin/ai/operations');assert.equal(own.data.connectivity.browser_probe,'acknowledged');
 const other=await call('other_admin_monitoring','GET','/api/v1/admin/ai/operations',undefined,'other');assert.equal(other.data.connectivity.browser_probe,'not_measured');
 await call('anonymous_start','POST','/api/v1/admin/ai/connectivity/browser-probe',{},null,401);
 await call('consumer_start','POST','/api/v1/admin/ai/connectivity/browser-probe',{},'consumer',403);
 fs.writeFileSync(process.env.RECEIPTS_CLIENT_RESULT,JSON.stringify({exchanges:captures,websocket:{channel_authorized_over_http:true,subscribed:true,event_correlated:true,http_start_contains_challenge:false,scope:'Synthetic authenticated Node WebSocket client, not a browser page'}}));
}finally{ws.close();}
''')
 node_env=env|{'RECEIPTS_HTTP':f'http://127.0.0.1:{http_port}','RECEIPTS_WS':f'ws://127.0.0.1:{reverb_port}/app/{env["REVERB_APP_KEY"]}?protocol=7&client=synthetic-support-proof&version=1','RECEIPTS_CLIENT_RESULT':str(work/'client-result.json')}
 subprocess.run(['node',str(client)],cwd=root,env=node_env,check=True,timeout=25)
 client_result=json.loads((work/'client-result.json').read_text());captures+=client_result['exchanges']
 ack_path=next(e['request']['path'] for e in client_result['exchanges'] if e['label']=='event_receipt_ack')
 http('anonymous_ack','POST',ack_path,{'nonce':'a'*32},actor=None,expected=401)
 http('consumer_ack','POST',ack_path,{'nonce':'a'*32},actor='consumer',expected=403)
 locker=work/'locker.php';ready=work/'lock-ready';locker.write_text('<?php '+bootstrap+"$lock=Illuminate\\Support\\Facades\\Cache::lock('support-ai:browser-probe-lock:'.App\\Models\\User::orderBy('id')->firstOrFail()->id,5);if(!$lock->get())exit(1);file_put_contents(getenv('RECEIPTS_LOCK_READY'),'ready');try{sleep(3);}finally{$lock->release();}")
 log=open(work/'locker.log','w');processes.append(subprocess.Popen(['php',str(locker)],cwd=root,env=env|{'RECEIPTS_LOCK_READY':str(ready)},stdout=log,stderr=log,start_new_session=True))
 for _ in range(30):
  if ready.exists():break
  time.sleep(.1)
 else:raise RuntimeError('Receipt lock fixture was unavailable.')
 http('concurrent_ack_busy','POST',ack_path,{'nonce':'a'*32},expected=409)
 processes[-1].wait(timeout=5)

 # Stop the actual broker: the third start must fail, and a fourth must hit the limiter.
 os.killpg(processes[0].pid,signal.SIGTERM);processes[0].wait(timeout=10)
 http('broadcast_unavailable','POST','/api/v1/admin/ai/connectivity/browser-probe',{},expected=503)
 http('start_rate_limit','POST','/api/v1/admin/ai/connectivity/browser-probe',{},expected=429)
 http('broadcast_failed_monitoring','GET','/api/v1/admin/ai/operations')

 out={'date':'2026-11-07','baseline':subprocess.check_output(['git','rev-parse','--short','HEAD'],cwd=root,text=True).strip()+' plus B89 working tree','scope':'Actual isolated Laravel HTTP, Reverb private WebSocket subscription/event and database queue:work process. Synthetic Node client and accounts; not frontend/browser-engine/cookie-CSRF/staging acceptance. Shared private SQLite cache/queue, ephemeral keys, no live providers/customer records.','worker':{'real_process':'queue:work database --queue=receipt-proof --stop-when-empty','before':'pending','after':'processed'},'websocket':client_result['websocket'],'exchanges':captures}
 (root/'docs/qa/B89_SUPPORT_RECEIPTS_HTTP_CAPTURE.json').write_text(json.dumps(out,indent=2)+'\n');print('PASS actual HTTP/Reverb event receipt and real database worker; captured',len(captures),'exchanges.')
finally:
 for p in reversed(processes):
  if p.poll() is None:os.killpg(p.pid,signal.SIGTERM);p.wait(timeout=10)
 shutil.rmtree(work)
