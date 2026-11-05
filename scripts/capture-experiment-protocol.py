import os,tempfile,subprocess,pathlib,base64,json,time,urllib.request,urllib.error,shutil,signal
root=pathlib.Path.cwd();work=pathlib.Path(tempfile.mkdtemp(prefix='shopbonus-b70-'));env=os.environ.copy()
for d in ['framework/cache/data','framework/sessions','framework/views','logs','app/private']:(work/'storage'/d).mkdir(parents=True,exist_ok=True)
db=work/'capture.sqlite';db.touch();env.update({'APP_ENV':'local','APP_DEBUG':'false','APP_KEY':'base64:'+base64.b64encode(os.urandom(32)).decode(),'DB_CONNECTION':'sqlite','DB_DATABASE':str(db),'DB_URL':'','LARAVEL_STORAGE_PATH':str(work/'storage'),'CACHE_STORE':'array','SESSION_DRIVER':'array','QUEUE_CONNECTION':'sync','MAIL_MAILER':'array','BROADCAST_CONNECTION':'null','ADMIN_MFA_REQUIRED':'false','OWNER_MFA_REQUIRED':'false','OWNER_STEP_UP_REQUIRED':'false','CAPTURE_PRIVATE':str(work/'private.json')})
seed=r'''<?php
require getcwd().'/vendor/autoload.php';$app=require getcwd().'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
$owner=App\Models\User::factory()->create(['email'=>'synthetic-study-owner@example.test']);$foreign=App\Models\User::factory()->create();
$owner->platformRoles()->create(['role'=>'super_admin','granted_via'=>'console','granted_at'=>now()]);
class CaptureShops {use Tests\Concerns\CreatesActiveWebshops;public function make($u){return $this->activeWebshop($u,['name'=>'Synthetic experiment merchant','verified_domain'=>'example.nl']);}}
$shop=(new CaptureShops)->make($owner);$banners=app(App\Services\Banners\BannerService::class);
$legacy=$banners->create($shop,'Synthetic exploratory campaign',null,Tests\Support\BannerFixture::config(),$owner->id);$banners->publish($legacy,$owner->id);
$target=$banners->create($shop,'Synthetic fixed-horizon study',null,Tests\Support\BannerFixture::config(),$owner->id);$banners->publish($target,$owner->id);$c=$target->draft_config;$c['placement']['anchor']='bottom';$banners->saveDraft($target,$c,1,$owner->id);$banners->publish($target,$owner->id);
$start=Carbon\CarbonImmutable::now('UTC')->startOfSecond()->subDays(32);Carbon\CarbonImmutable::setTestNow($start);Carbon\Carbon::setTestNow($start);
config(['qa.release_sha'=>str_repeat('a',40)]);
$connection=Tests\Support\ExperimentStudyFixture::connection($shop,$owner);$proof=Tests\Support\ExperimentStudyFixture::proof($shop,$owner,$connection);
$input=['control_version'=>1,'variant_version'=>2,'variant_bps'=>5000,'enrollment_days'=>1,'min_visitors_per_arm'=>1000,'minimum_effect_bps'=>50,'ingestion_grace_hours'=>24,'review_reference'=>'SYNTHETIC-HTTP-ONLY','measurement_evidence_ids'=>[$proof]];
app(App\Services\Banners\ExperimentAnalysis::class)->register($target,$input,$owner);app(App\Services\Banners\BannerExperiments::class)->start($target,1,2,5000,$owner->id);
$e=DB::table('banner_experiments')->where('campaign_id',$target->id)->sole();
foreach(['control','variant'] as $arm){$rows=[];for($i=0;$i<1000;$i++)$rows[]=['experiment_id'=>$e->id,'arm'=>$arm,'visitor_hash'=>hash('sha256',"$arm:$i"),'token_hash'=>hash('sha256',"token:$arm:$i"),'created_at'=>now(),'expires_at'=>now()->addDays(30)];foreach(array_chunk($rows,100) as $chunk)DB::table('banner_assignments')->insert($chunk);}
$end=$start->addDays(32);Carbon\CarbonImmutable::setTestNow($end);Carbon\Carbon::setTestNow($end);
$closing=Tests\Support\ExperimentStudyFixture::proof($shop,$owner,$connection);
$run=App\Models\IntegrationSyncRun::create(['connection_id'=>$connection->id,'credential_generation'=>1,'verified_domain'=>$shop->verified_domain,'provider_store_ref'=>$connection->external_shop_ref,'request_key'=>(string)Illuminate\Support\Str::uuid(),'since_at'=>$start->format('Y-m-d\TH:i:s\Z'),'until_at'=>$end->format('Y-m-d\TH:i:s\Z'),'status'=>'completed','discovery_complete'=>true]);
$i=0;foreach(['control'=>100,'variant'=>200] as $arm=>$count){foreach(DB::table('banner_assignments')->where('experiment_id',$e->id)->where('arm',$arm)->limit($count)->get() as $a)Tests\Support\ExperimentStudyFixture::order($shop,$connection,$a->id,$a->token_hash,(string)(100000+$i++),$start->addHour(),$run->id);}
app(App\Services\Banners\ExperimentAnalysis::class)->finalize($e->id,$owner,[$closing]);app(App\Services\Banners\BannerExperiments::class)->stop($target,$owner->id);
Carbon\CarbonImmutable::setTestNow();Carbon\Carbon::setTestNow();
file_put_contents(getenv('CAPTURE_PRIVATE'),json_encode(['token'=>$owner->createToken('capture')->plainTextToken,'foreign_token'=>$foreign->createToken('capture')->plainTextToken,'shop'=>$shop->public_id,'campaign'=>$legacy->public_id,'study'=>$target->public_id]));chmod(getenv('CAPTURE_PRIVATE'),0600);
'''
server=None
try:
 subprocess.run(['php','artisan','migrate','--force'],env=env,stdout=subprocess.DEVNULL,stderr=subprocess.PIPE,check=True)
 sf=work/'seed.php';sf.write_text(seed);subprocess.run(['php',str(sf)],env=env,check=True)
 data=json.loads((work/'private.json').read_text());log=open(work/'server.log','w');server=subprocess.Popen(['php','artisan','serve','--host=127.0.0.1','--port=8794','--no-reload'],env=env,stdout=log,stderr=log,start_new_session=True)
 for i in range(60):
  try:urllib.request.urlopen('http://127.0.0.1:8794/api/v1/health',timeout=1);break
  except Exception:time.sleep(.2)
 captures=[]

 path='/api/v1/workspaces/'+data['shop']+'/banners/'+data['campaign']+'/experiment'
 study='/api/v1/workspaces/'+data['shop']+'/banners/'+data['study']+'/experiment'
 cases=[('launch','POST',path,data['token'],201,{'control_version':None,'variant_version':1,'variant_bps':5000}),('exploratory','GET',path,data['token'],200,None),('fixed_readout','GET',study,data['token'],200,None),('stop','POST',path+'/stop',data['token'],200,None),('foreign_workspace','GET',path,data['foreign_token'],404,None),('unauthenticated','GET',path,None,401,None)]
 for label,method,path,token,expected,payload in cases:
  headers={'Accept':'application/json','Content-Type':'application/json'}
  if token:headers['Authorization']='Bearer '+token
  try:response=urllib.request.urlopen(urllib.request.Request('http://127.0.0.1:8794'+path,headers=headers,method=method,data=json.dumps(payload).encode() if payload else None))
  except urllib.error.HTTPError as e:response=e
  body=json.loads(response.read());assert response.status==expected,(label,response.status,body)
  captures.append({'label':label,'request':{'method':method,'path':path,'body':payload,'headers':{'Accept':'application/json',**({'Authorization':'Bearer <token>'} if token else {})}},'response':{'status':response.status,'body':body}})

 out={'captured_at':time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime()),'baseline':'cfef279 plus B70 working tree','environment':'Actual local Laravel HTTP server; private temporary SQLite/key/storage. Synthetic QA/native order records exercised through real protocol, proof and readout services; NO genuine provider acceptance or observed customer outcomes. Fixed-readout example is synthetic code/serialization evidence only.','exchanges':captures}
 (root/'docs/qa/B70_EXPERIMENT_PROTOCOL_HTTP_CAPTURE.json').write_text(json.dumps(out,indent=2)+'\n');print('Captured6 HTTP responses; isolated fixtures cleaned.')
finally:
 if server:os.killpg(server.pid,signal.SIGTERM);server.wait(timeout=10)
 shutil.rmtree(work)
