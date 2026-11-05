import os,tempfile,subprocess,pathlib,base64,json,time,urllib.request,urllib.error,shutil,signal
root=pathlib.Path.cwd();work=pathlib.Path(tempfile.mkdtemp(prefix='shopbonus-b75-'));env=os.environ.copy()
for d in ['framework/cache/data','framework/sessions','framework/views','logs','app/private']:(work/'storage'/d).mkdir(parents=True,exist_ok=True)
db=work/'capture.sqlite';db.touch();env.update({'APP_ENV':'local','APP_DEBUG':'false','APP_KEY':'base64:'+base64.b64encode(os.urandom(32)).decode(),'DB_CONNECTION':'sqlite','DB_DATABASE':str(db),'DB_URL':'','LARAVEL_STORAGE_PATH':str(work/'storage'),'CACHE_STORE':'array','SESSION_DRIVER':'array','QUEUE_CONNECTION':'sync','MAIL_MAILER':'array','BROADCAST_CONNECTION':'null','ADMIN_MFA_REQUIRED':'false','OWNER_MFA_REQUIRED':'false','OWNER_STEP_UP_REQUIRED':'false','CAPTURE_PRIVATE':str(work/'private.json')})
seed=r'''<?php
require getcwd().'/vendor/autoload.php';$app=require getcwd().'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$owner=App\Models\User::factory()->create();$foreign=App\Models\User::factory()->create();$employee=App\Models\User::factory()->create();
class CaptureShops {use Tests\Concerns\CreatesActiveWebshops;public function make($u){return $this->activeWebshop($u,['name'=>'Synthetic remaining-platform merchant','verified_domain'=>'shop.example.com']);}}
$shop=(new CaptureShops)->make($owner);$shop->memberships()->create(['user_id'=>$employee->id,'role'=>'employee','status'=>'active']);
$owner->platformRoles()->create(['role'=>App\Enums\PlatformRole::SuperAdmin,'granted_via'=>'console','granted_at'=>now()]);
$app->bind(App\Services\Onboarding\Network\HostResolver::class,fn()=>new class implements App\Services\Onboarding\Network\HostResolver { public function addresses(string $host):array{return ['93.184.216.34'];}public function txtRecords(string $host):array{return [];} });
$im=imagecreatetruecolor(32,32);ob_start();imagepng($im);$png=ob_get_clean();imagedestroy($im);Illuminate\Support\Facades\Http::fake(fn()=>Illuminate\Support\Facades\Http::response($png,200,['Content-Type'=>'image/png']));
$research=['source_url'=>'https://shop.example.com/','evidence'=>['E4'=>['fact'=>'image_candidate','value'=>'https://shop.example.com/photo.png'],'E5'=>['fact'=>'image_candidate','value'=>'https://shop.example.com/pending.png']]];
$run=App\Models\MerchantEnrichmentRun::create(['webshop_id'=>$shop->id,'status'=>'awaiting_review','trigger'=>'admin','source_url'=>'https://shop.example.com/','research'=>$research,'research_hash'=>app(App\Services\Enrichment\WebsiteResearcher::class)->hash($research),'text_model'=>'synthetic','review_required'=>true]);
$s=app(App\Services\Enrichment\WebsiteAssetImports::class);$r=$s->import($run,'E4',$owner);$r=$s->review($r,$owner,true,'Synthetic review approved','Synthetic merchant licence reference');$asset=App\Models\BannerAsset::findOrFail($r->banner_asset_id);$pending=$s->import($run,'E5',$owner);
file_put_contents(getenv('CAPTURE_PRIVATE'),json_encode(['token'=>$owner->createToken('capture')->plainTextToken,'foreign_token'=>$foreign->createToken('capture')->plainTextToken,'employee_token'=>$employee->createToken('capture')->plainTextToken,'shop'=>$shop->public_id,'asset'=>$asset->public_id,'pending'=>$pending->public_id]));chmod(getenv('CAPTURE_PRIVATE'),0600);
'''
server=None
try:
 subprocess.run(['php','artisan','migrate','--force'],env=env,stdout=subprocess.DEVNULL,stderr=subprocess.PIPE,check=True)
 sf=work/'seed.php';sf.write_text(seed);subprocess.run(['php',str(sf)],env=env,check=True)
 data=json.loads((work/'private.json').read_text());log=open(work/'server.log','w');router=work/'router.php';router.write_text("<?php $_ENV['LARAVEL_STORAGE_PATH']=getenv('LARAVEL_STORAGE_PATH');require "+repr(str(root/'public/index.php'))+";");server=subprocess.Popen(['php','-S','127.0.0.1:8799','-t',str(root/'public'),str(router)],env=env,stdout=log,stderr=log,start_new_session=True)
 for i in range(60):
  try:urllib.request.urlopen('http://127.0.0.1:8799/api/v1/health',timeout=1);break
  except Exception:time.sleep(.2)
 captures=[]



 base='/api/v1/workspaces/'+data['shop']+'/connections'
 cases=[('approved_asset','GET','/api/v1/banners/assets/'+data['asset'],None,None,200),('pending_not_public','GET','/api/v1/banners/assets/'+data['pending'],None,None,404)]
 ids={}
 def redact(v):
  if isinstance(v,dict):return {k:('<ONE_TIME_SIGNING_SECRET>' if k=='signing_secret' and isinstance(x,str) else redact(x)) for k,x in v.items()}
  if isinstance(v,list):return [redact(x) for x in v]
  return v
 for label,method,path,payload,token,expected in cases:
  path=path.replace('{bigcommerce}',ids.get('bigcommerce',''))
  headers={'Accept':'application/json','Content-Type':'application/json'}
  if token:headers['Authorization']='Bearer '+token
  try:response=urllib.request.urlopen(urllib.request.Request('http://127.0.0.1:8799'+path,headers=headers,method=method,data=json.dumps(payload).encode() if payload is not None else None))
  except urllib.error.HTTPError as e:response=e
  raw=response.read();body={'content_type':response.headers.get('Content-Type'),'byte_length':len(raw),'sha256':__import__('hashlib').sha256(raw).hexdigest(),'base64':base64.b64encode(raw).decode()} if response.headers.get('Content-Type','').startswith('image/') else json.loads(raw);assert response.status==expected,(label,response.status,body)
  if label=='bigcommerce_create':ids['bigcommerce']=body['data']['id']
  captures.append({'label':label,'request':{'method':method,'path':path,'body':payload,'headers':{'Accept':'application/json',**({'Authorization':'Bearer <token>'} if token else {})}},'response':{'status':response.status,'body':redact(body)}})

 out={'captured_at':time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime()),'baseline':'8cc2c72 plus B75 working tree','environment':'Actual Laravel loopback HTTP; isolated temporary SQLite/storage/key. Synthetic merchant records, actual API authentication/authorization/validation/connector creation. No native provider requests or genuine provider acceptance. One-time generated signing secret is redacted. Candidate images and download transport are explicitly synthetic; real import/re-encoding/rights review and existing public asset serving run. Approved binary response is recorded as actual base64/length/hash, not JSON served by the API. Pending candidate has no public asset.','exchanges':captures}
 (root/'docs/qa/B75_ASSET_HTTP_CAPTURE.json').write_text(json.dumps(out,indent=2)+'\n');print('Captured2 HTTP responses; isolated fixtures cleaned.')
finally:
 if server:os.killpg(server.pid,signal.SIGTERM);server.wait(timeout=10)
 shutil.rmtree(work)
