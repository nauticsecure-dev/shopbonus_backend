import os,tempfile,subprocess,pathlib,base64,json,time,urllib.request,urllib.error,shutil,signal
root=pathlib.Path.cwd();work=pathlib.Path(tempfile.mkdtemp(prefix='shopbonus-b72-'));env=os.environ.copy()
for d in ['framework/cache/data','framework/sessions','framework/views','logs','app/private']:(work/'storage'/d).mkdir(parents=True,exist_ok=True)
db=work/'capture.sqlite';db.touch();env.update({'APP_ENV':'local','APP_DEBUG':'false','APP_KEY':'base64:'+base64.b64encode(os.urandom(32)).decode(),'DB_CONNECTION':'sqlite','DB_DATABASE':str(db),'DB_URL':'','LARAVEL_STORAGE_PATH':str(work/'storage'),'CACHE_STORE':'array','SESSION_DRIVER':'array','QUEUE_CONNECTION':'sync','MAIL_MAILER':'array','BROADCAST_CONNECTION':'null','ADMIN_MFA_REQUIRED':'false','OWNER_MFA_REQUIRED':'false','OWNER_STEP_UP_REQUIRED':'false','CAPTURE_PRIVATE':str(work/'private.json')})
seed=r'''<?php
require getcwd().'/vendor/autoload.php';$app=require getcwd().'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$owner=App\Models\User::factory()->create();$foreign=App\Models\User::factory()->create();$employee=App\Models\User::factory()->create();
class CaptureShops {use Tests\Concerns\CreatesActiveWebshops;public function make($u){return $this->activeWebshop($u,['name'=>'Synthetic remaining-platform merchant','verified_domain'=>'shop.example.com']);}}
$shop=(new CaptureShops)->make($owner);$shop->memberships()->create(['user_id'=>$employee->id,'role'=>'employee','status'=>'active']);
file_put_contents(getenv('CAPTURE_PRIVATE'),json_encode(['token'=>$owner->createToken('capture')->plainTextToken,'foreign_token'=>$foreign->createToken('capture')->plainTextToken,'employee_token'=>$employee->createToken('capture')->plainTextToken,'shop'=>$shop->public_id]));chmod(getenv('CAPTURE_PRIVATE'),0600);
'''
server=None
try:
 subprocess.run(['php','artisan','migrate','--force'],env=env,stdout=subprocess.DEVNULL,stderr=subprocess.PIPE,check=True)
 sf=work/'seed.php';sf.write_text(seed);subprocess.run(['php',str(sf)],env=env,check=True)
 data=json.loads((work/'private.json').read_text());log=open(work/'server.log','w');server=subprocess.Popen(['php','artisan','serve','--host=127.0.0.1','--port=8796','--no-reload'],env=env,stdout=log,stderr=log,start_new_session=True)
 for i in range(60):
  try:urllib.request.urlopen('http://127.0.0.1:8796/api/v1/health',timeout=1);break
  except Exception:time.sleep(.2)
 captures=[]



 base='/api/v1/workspaces/'+data['shop']+'/connections'
 cases=[('catalogue','GET','/api/v1/integrations/platforms',None,data['token'],200),('ccv_create','POST',base,{'platform':'ccv','store_url':'https://shop.example.com','webshop_id':'12','api_key':'SYNTHETIC_public_key','api_secret':'SYNTHETIC_secret_key'},data['token'],201),('mijnwebwinkel_bridge_create','POST',base,{'platform':'mijnwebwinkel','store_domain':'shop.example.com','merchant_reference':'MWW-12'},data['token'],201),('ccv_detail','GET',base+'/{ccv}',None,data['token'],200),('foreign_workspace','POST',base,{'platform':'ccv'},data['foreign_token'],404),('no_manage_permission','POST',base,{'platform':'ccv'},data['employee_token'],403),('unauthenticated','POST',base,{'platform':'ccv'},None,401),('missing_credentials','POST',base,{'platform':'ccv'},data['token'],422)]
 ids={}
 def redact(v):
  if isinstance(v,dict):return {k:('<ONE_TIME_SIGNING_SECRET>' if k=='signing_secret' and isinstance(x,str) else redact(x)) for k,x in v.items()}
  if isinstance(v,list):return [redact(x) for x in v]
  return v
 for label,method,path,payload,token,expected in cases:
  path=path.replace('{ccv}',ids.get('ccv',''))
  headers={'Accept':'application/json','Content-Type':'application/json'}
  if token:headers['Authorization']='Bearer '+token
  try:response=urllib.request.urlopen(urllib.request.Request('http://127.0.0.1:8796'+path,headers=headers,method=method,data=json.dumps(payload).encode() if payload is not None else None))
  except urllib.error.HTTPError as e:response=e
  body=json.loads(response.read());assert response.status==expected,(label,response.status,body)
  if label=='ccv_create':ids['ccv']=body['data']['id']
  captures.append({'label':label,'request':{'method':method,'path':path,'body':payload,'headers':{'Accept':'application/json',**({'Authorization':'Bearer <token>'} if token else {})}},'response':{'status':response.status,'body':redact(body)}})

 out={'captured_at':time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime()),'baseline':'be6f2f1 plus B72 working tree','environment':'Actual Laravel loopback HTTP; isolated temporary SQLite/storage/key. Synthetic merchant records, actual API authentication/authorization/validation/connector creation. No native provider requests or genuine provider acceptance. One-time generated signing secret is redacted. Mijnwebwinkel is an explicit partner bridge, not a native API client.','exchanges':captures}
 (root/'docs/qa/B72_REMAINING_CONNECTORS_HTTP_CAPTURE.json').write_text(json.dumps(out,indent=2)+'\n');print('Captured8 HTTP responses; isolated fixtures cleaned.')
finally:
 if server:os.killpg(server.pid,signal.SIGTERM);server.wait(timeout=10)
 shutil.rmtree(work)
