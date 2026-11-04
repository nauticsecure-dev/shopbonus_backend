import urllib.parse
import os,tempfile,subprocess,pathlib,base64,json,time,urllib.request,urllib.error,shutil,signal
root=pathlib.Path.cwd();work=pathlib.Path(tempfile.mkdtemp(prefix='shopbonus-b77-'));env=os.environ.copy()
for d in ['framework/cache/data','framework/sessions','framework/views','logs','app/private']:(work/'storage'/d).mkdir(parents=True,exist_ok=True)
db=work/'capture.sqlite';db.touch();env.update({'APP_ENV':'local','APP_DEBUG':'false','APP_KEY':'base64:'+base64.b64encode(os.urandom(32)).decode(),'DB_CONNECTION':'sqlite','DB_DATABASE':str(db),'DB_URL':'','LARAVEL_STORAGE_PATH':str(work/'storage'),'CACHE_STORE':'array','SESSION_DRIVER':'array','QUEUE_CONNECTION':'database','MAIL_MAILER':'array','BROADCAST_CONNECTION':'null','ADMIN_MFA_REQUIRED':'false','OWNER_MFA_REQUIRED':'false','OWNER_STEP_UP_REQUIRED':'false','CAPTURE_PRIVATE':str(work/'private.json')})
seed=r'''<?php
require getcwd().'/vendor/autoload.php';$app=require getcwd().'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$owner=App\Models\User::factory()->create();$foreign=App\Models\User::factory()->create();$employee=App\Models\User::factory()->create();
class CaptureShops {use Tests\Concerns\CreatesActiveWebshops;public function make($u){return $this->activeWebshop($u,['name'=>'Synthetic remaining-platform merchant','verified_domain'=>'shop.example.com']);}}
$shop=(new CaptureShops)->make($owner);$saas=(new CaptureShops)->make($owner);$shop->memberships()->create(['user_id'=>$employee->id,'role'=>'employee','status'=>'active']);
file_put_contents(getenv('CAPTURE_PRIVATE'),json_encode(['token'=>$owner->createToken('capture')->plainTextToken,'foreign_token'=>$foreign->createToken('capture')->plainTextToken,'employee_token'=>$employee->createToken('capture')->plainTextToken,'shop'=>$shop->public_id,'saas'=>$saas->public_id]));chmod(getenv('CAPTURE_PRIVATE'),0600);
'''
server=None
try:
 subprocess.run(['php','artisan','migrate','--force'],env=env,stdout=subprocess.DEVNULL,stderr=subprocess.PIPE,check=True)
 sf=work/'seed.php';sf.write_text(seed);subprocess.run(['php',str(sf)],env=env,check=True)
 data=json.loads((work/'private.json').read_text());log=open(work/'server.log','w');router=work/'router.php';router.write_text("<?php $_ENV['LARAVEL_STORAGE_PATH']=getenv('LARAVEL_STORAGE_PATH');require "+repr(str(root/'public/index.php'))+";");server=subprocess.Popen(['php','-S','127.0.0.1:8800','-t',str(root/'public'),str(router)],env=env,stdout=log,stderr=log,start_new_session=True)
 for i in range(60):
  try:urllib.request.urlopen('http://127.0.0.1:8800/api/v1/health',timeout=1);break
  except Exception:time.sleep(.2)
 captures=[]



 base='/api/v1/workspaces/'+data['shop']+'/connections'
 cases=[('catalogue','GET','/api/v1/integrations/platforms',None,data['token'],200),('magento_module_create','POST',base,{'platform':'magento','store_url':'https://shop.example.com','store_id':'2','store_code':'default','access_token':'SYNTHETIC_native_token'},data['token'],201),('adobe_saas_create','POST','/api/v1/workspaces/'+data['saas']+'/connections',{'platform':'magento','deployment':'saas','saas_api_url':'https://na1.api.commerce.adobe.com/SYNTHETIC_TENANT','store_url':'https://shop.example.com','store_id':'2','store_code':'default','access_token':'SYNTHETIC_IMS_token'},data['token'],201),('magento_detail','GET',base+'/{bigcommerce}',None,data['token'],200),('foreign_workspace','POST',base,{'platform':'magento'},data['foreign_token'],404),('no_manage_permission','POST',base,{'platform':'magento'},data['employee_token'],403),('unauthenticated','POST',base,{'platform':'magento'},None,401),('invalid_saas_origin','POST',base,{'platform':'magento','deployment':'saas','saas_api_url':'https://evil.example.com/TENANT','store_url':'https://shop.example.com','store_id':'2','store_code':'default','access_token':'SYNTHETIC_IMS_token'},data['token'],422)]
 ids={}
 def redact(v):
  if isinstance(v,dict):return {k:('<ONE_TIME_SIGNING_SECRET>' if k=='signing_secret' and isinstance(x,str) else redact(x)) for k,x in v.items()}
  if isinstance(v,list):return [redact(x) for x in v]
  return v
 for label,method,path,payload,token,expected in cases:
  path=path.replace('{bigcommerce}',ids.get('bigcommerce',''))
  headers={'Accept':'application/json','Content-Type':'application/json'}
  if token:headers['Authorization']='Bearer '+token
  try:response=urllib.request.urlopen(urllib.request.Request('http://127.0.0.1:8800'+path,headers=headers,method=method,data=json.dumps(payload).encode() if payload is not None else None))
  except urllib.error.HTTPError as e:response=e
  body=json.loads(response.read());assert response.status==expected,(label,response.status,body)
  if label=='magento_module_create':
   ids['bigcommerce']=body['data']['id'];native_setup=body
  if label=='adobe_saas_create':saas_setup=body
  captures.append({'label':label,'request':{'method':method,'path':path,'body':payload,'headers':{'Accept':'application/json',**({'Authorization':'Bearer <token>'} if token else {})}},'response':{'status':response.status,'body':redact(body)}})


 setup=native_setup['data']
 def find_key(obj,key):
  if isinstance(obj,dict):
   if key in obj:return obj[key]
   for v in obj.values():
    found=find_key(v,key)
    if found is not None:return found
  return None
 secret=find_key(native_setup,'signing_secret');webhook=find_key(native_setup,'webhook_url')
 assert secret and webhook, 'Native setup lacks one-time signer configuration'
 params={'SHOPBONUS_ENABLED':'true','SHOPBONUS_ENDPOINT':'https://api.example.com'+urllib.parse.urlparse(webhook).path,'SHOPBONUS_SECRET':secret,'ADOBE_EVENT_SOURCE':'urn:uuid:SYNTHETIC','COMMERCE_EVENT_SOURCE':'synthetic.Stage','COMMERCE_STORE_ID':'2','COMMERCE_STORE_CODE':'default','specversion':'1.0','id':'SYNTHETIC-CAPTURE-1','source':'urn:uuid:SYNTHETIC','type':'com.adobe.commerce.observer.sales_order_save_commit_after','data':{'source':'synthetic.Stage','value':{'entity_id':4711,'store_id':2,'payment':{'amount_paid':99999}}}}
 node="let s='';process.stdin.on('data',c=>s+=c);process.stdin.on('end',()=>{const a=require('./merchant-packages/adobe-appbuilder/actions/orders/index.js'),r=a.normalize(JSON.parse(s));process.stdout.write(JSON.stringify({body:r.body,headers:a.headers(r)}));});"
 record=json.loads(subprocess.run(['node','-e',node],input=json.dumps(params),text=True,capture_output=True,check=True).stdout)
 path=urllib.parse.urlparse(webhook).path
 for label,expected,foreign in [('node_action_lookup',202,False),('node_action_duplicate',200,False),('foreign_store_lookup',401,True)]:
  payload=record['body'];headers=record['headers'].copy();headers.pop('Content-Length',None)
  if foreign:
   payload=payload.replace('"store_id":"2"','"store_id":"3"');ts=str(int(time.time()));headers['X-Shopbonus-Event-Id']='synthetic-foreign';headers['X-Shopbonus-Signature']='t='+ts+',v1='+__import__('hmac').new(secret.encode(),(ts+'.'+payload).encode(),__import__('hashlib').sha256).hexdigest()
  try:response=urllib.request.urlopen(urllib.request.Request('http://127.0.0.1:8800'+path,headers=headers,method='POST',data=payload.encode()))
  except urllib.error.HTTPError as e:response=e
  body=json.loads(response.read());assert response.status==expected,(label,response.status,body)
  captures.append({'label':label,'request':{'method':'POST','path':path,'body':json.loads(payload),'headers':{'Content-Type':'application/json','X-Shopbonus-Signature':'t=<time>,v1=<actual generated signature>','X-Shopbonus-Event-Id':headers['X-Shopbonus-Event-Id'],'X-Shopbonus-Topic':'order.updated'}},'response':{'status':response.status,'body':body}})
 out={'captured_at':time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime()),'baseline':'f217e33 backend source; explicit HTTP storage-isolation capture follow-up','environment':'Actual Laravel loopback HTTP; isolated temporary SQLite/storage/key. Synthetic merchant records, actual API authentication/authorization/validation/connector creation. No native provider requests or genuine provider acceptance. One-time generated signing secret is redacted. Candidate module and private App Builder action; SaaS native transport is separately fixture-tested. Actual native Adobe registration/installation is NOT RUN. ID notifications are authenticated, persisted and queued; provider order recovery workers are not executed in this HTTP capture.','exchanges':captures}
 (root/'docs/qa/B77_ADOBE_HTTP_CAPTURE.json').write_text(json.dumps(out,indent=2)+'\n');print('Captured11 HTTP responses; isolated fixtures cleaned.')
finally:
 if server:os.killpg(server.pid,signal.SIGTERM);server.wait(timeout=10)
 shutil.rmtree(work)
