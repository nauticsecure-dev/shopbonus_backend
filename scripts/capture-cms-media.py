"""Isolated real HTTP examples; synthetic recent-password fixture, not browser acceptance."""
import datetime
import os, tempfile, subprocess, pathlib, base64, json, time, urllib.request, urllib.error, shutil, signal, hashlib
bindings_mode=os.environ.get('CAPTURE_CMS_BINDINGS')=='1'
ai_mode=os.environ.get('CAPTURE_CMS_AI')=='1'
root=pathlib.Path.cwd(); work=pathlib.Path(tempfile.mkdtemp(prefix='shopbonus-b79-')); env=os.environ.copy(); server=None
for d in ['framework/cache/data','framework/sessions','framework/views','logs','app/private']:(work/'storage'/d).mkdir(parents=True,exist_ok=True)
db=work/'capture.sqlite';db.touch()
env.update({'APP_ENV':'local','APP_DEBUG':'false','APP_KEY':'base64:'+base64.b64encode(os.urandom(32)).decode(),'DB_CONNECTION':'sqlite','DB_DATABASE':str(db),'DB_URL':'','LARAVEL_STORAGE_PATH':str(work/'storage'),'CACHE_STORE':'array','SESSION_DRIVER':'array','QUEUE_CONNECTION':'sync','CMS_MEDIA_QUEUE_CONNECTION':'sync','MAIL_MAILER':'array','BROADCAST_CONNECTION':'null','ADMIN_MFA_REQUIRED':'false','OWNER_MFA_REQUIRED':'false','OWNER_STEP_UP_REQUIRED':'false','CAPTURE_PRIVATE':str(work/'private.json'),'CAPTURE_IMAGE':str(work/'source.png')})
if ai_mode:
 env.update({'CMS_AI_ENABLED':'true','CMS_AI_API_KEY':'sk-cms-synthetic','CMS_AI_MODEL':'gpt-test','CMS_AI_QUEUE_CONNECTION':'database','CMS_AI_AUTO_TRANSLATE':'false','CMS_AI_DAILY_LIMIT':'20','CMS_AI_DAILY_BUDGET_MICROS':'10000000','CMS_AI_VERIFIED_PRICING':json.dumps({'model':'gpt-test','currency':'EUR','reference':'Synthetic capture pricing, not live rates','valid_until':(datetime.datetime.now(datetime.timezone.utc)+datetime.timedelta(days=7)).date().isoformat(),'context_tokens':131072,'input_micros_per_million':1000000,'output_micros_per_million':1000000})})
seed=r'''<?php
require getcwd().'/vendor/autoload.php';$app=require getcwd().'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$admin=App\Models\User::factory()->create();$consumer=App\Models\User::factory()->create();$admin->platformRoles()->create(['role'=>App\Enums\PlatformRole::SuperAdmin,'granted_via'=>'console','granted_at'=>now()]);
$reviewer=App\Models\User::factory()->create();$reviewer->platformRoles()->create(['role'=>App\Enums\PlatformRole::SuperAdmin,'granted_via'=>'console','granted_at'=>now()]);
$im=imagecreatetruecolor(80,60);imagepng($im,getenv('CAPTURE_IMAGE'));imagedestroy($im);
file_put_contents(getenv('CAPTURE_PRIVATE'),json_encode(['admin'=>$admin->createToken('capture')->plainTextToken,'reviewer'=>$reviewer->createToken('capture')->plainTextToken,'consumer'=>$consumer->createToken('capture')->plainTextToken]));chmod(getenv('CAPTURE_PRIVATE'),0600);
'''
try:
 subprocess.run(['php','artisan','migrate','--force'],env=env,stdout=subprocess.DEVNULL,check=True)
 sf=work/'seed.php';sf.write_text(seed);subprocess.run(['php',str(sf)],env=env,check=True);data=json.loads((work/'private.json').read_text())
 router=work/'router.php';router.write_text(r'''<?php
$_ENV['LARAVEL_STORAGE_PATH']=getenv('LARAVEL_STORAGE_PATH');require getcwd().'/vendor/autoload.php';
class CaptureMediaSession {function handle($request,$next){if(!$request->header('X-Capture-No-Session')){$session=new Illuminate\Session\Store('capture',new Illuminate\Session\ArraySessionHandler(120));$session->start();$session->put('auth.password_confirmed_at',time());$request->setLaravelSession($session);}return $next($request);}}
$app=require getcwd().'/bootstrap/app.php';$app->make(Illuminate\Contracts\Http\Kernel::class)->prependMiddleware(CaptureMediaSession::class);$app->handleRequest(Illuminate\Http\Request::capture());
''')
 log=open(work/'server.log','w');server=subprocess.Popen(['php','-S','127.0.0.1:8801','-t',str(root/'public'),str(router)],env=env,stdout=log,stderr=log,start_new_session=True)
 for _ in range(60):
  try:urllib.request.urlopen('http://127.0.0.1:8801/api/v1/health',timeout=1);break
  except Exception:time.sleep(.2)
 captures=[]
 def call(label,method,path,payload=None,actor='admin',expected=200,upload=False,no_session=False):
  headers={'Accept':'application/json'}
  if actor:headers['Authorization']='Bearer '+data[actor]
  if no_session:headers['X-Capture-No-Session']='1'
  if upload:
   boundary='ShopbonusCaptureBoundary';headers['Content-Type']='multipart/form-data; boundary='+boundary
   body=('--'+boundary+'\r\nContent-Disposition: form-data; name="image"; filename="source.png"\r\nContent-Type: image/png\r\n\r\n').encode()+(work/'source.png').read_bytes()+('\r\n--'+boundary+'--\r\n').encode()
  else:
   headers['Content-Type']='application/json';body=json.dumps(payload).encode() if payload is not None else None
  try:r=urllib.request.urlopen(urllib.request.Request('http://127.0.0.1:8801'+path,headers=headers,method=method,data=body))
  except urllib.error.HTTPError as e:r=e
  raw=r.read();response=({'content_type':r.headers.get('Content-Type'),'byte_length':len(raw),'sha256':hashlib.sha256(raw).hexdigest(),'base64':base64.b64encode(raw).decode()} if r.headers.get('Content-Type','').startswith('image/') else json.loads(raw))
  assert r.status==expected,(label,r.status,response)
  captures.append({'label':label,'request':{'method':method,'path':path,'body':{'image':'@source.png (actual multipart PNG)'} if upload else payload,'headers':{k:('Bearer <token>' if k=='Authorization' else v) for k,v in headers.items()}},'response':{'status':r.status,'body':response}});return response
 a=call('upload','POST','/api/v1/admin/cms/assets',expected=201,upload=True)['data'];asset=a['id'];path='/api/v1/admin/cms/assets/'+asset
 manifest=call('show','GET',path)['data'];old=manifest['active_recipe'];print('Manifest keys:',list(manifest),flush=True)
 call('list','GET','/api/v1/admin/cms/assets');call('original','GET',path+'/original')
 transforms={bp:{'x':0,'y':0,'width':40,'height':40,'rotate':90,'flip_h':True,'flip_v':False,'output_width':32} for bp in ['mobile','tablet','desktop']}
 new=call('recipe','POST',path+'/recipes',{'revision':1,'breakpoints':transforms},expected=201)['data']
 recipe=new['active_recipe'];recipe=recipe['id'] if isinstance(recipe,dict) else recipe
 call('derivative','GET','/api/v1/public/content-assets/'+asset+'/recipes/'+recipe+'/mobile',actor=None)
 old=old['id'] if isinstance(old,dict) else old
 call('restore','POST',path+'/restore',{'revision':2,'recipe':old})
 call('stale','POST',path+'/restore',{'revision':1,'recipe':old},expected=409)
 call('missing_revision','POST',path+'/restore',{'recipe':old},expected=422)
 call('unauthenticated','GET',path,actor=None,expected=401);call('forbidden','GET',path,actor='consumer',expected=403)
 call('step_up','POST',path+'/restore',{'revision':3,'recipe':old},expected=423,no_session=True)
 call('missing','GET','/api/v1/admin/cms/assets/01AAAAAAAAAAAAAAAAAAAAAAAA',expected=404)
 for surface in ['homepage','over-ons','voor-webshops','pages/voor-webshops']:
  call('upload_'+surface,'POST','/api/v1/admin/content/'+surface+'/assets',expected=201,upload=True)
 if bindings_mode:
  content={'title':'Synthetic component','body':{'type':'doc','content':[{'type':'paragraph','content':[{'type':'text','text':'Actual saved component copy'}]},{'type':'image','attrs':{'asset_id':asset,'alt':'Synthetic image'}}]},'media_recipes':{'body.content.1.attrs.asset_id':old}}
  page=call('create_pinned_page','POST','/api/v1/admin/cms/pages',{'key':'binding-capture','type':'info','locales':{'nl':{'slug':'binding-capture','content':content}}},expected=201)['data']['id']
  base='/api/v1/admin/cms/pages/'+page+'/locales/nl'
  field=call('bindings','GET',base+'/bindings?limit=2');saved_hash=field['source']['content_sha256']
  call('bindings_next','GET',base+'/bindings?after=2&hash='+saved_hash)
  call('component_preview','GET',base+'/media-preview')
  call('publish_pinned','POST',base+'/publish',{'revision':1})
  call('published_preview','GET',base+'/media-preview?version=published')
  call('draft_usage','GET',path+'/usages?source=draft');call('revision_usage','GET',path+'/usages?source=revision')
  content['title']='Changed draft'
  call('save_changed_draft','PUT',base,{'revision':1,'content':content})
  call('stale_bindings','GET',base+'/bindings?after=2&hash='+saved_hash,expected=409)
  call('private_bindings','GET',base+'/bindings',actor=None,expected=401)
  call('consumer_preview','GET',base+'/media-preview',actor='consumer',expected=403)
  call('invalid_usage_source','GET',path+'/usages?source=unknown',expected=422)
  call('missing_source','GET','/api/v1/admin/cms/pages/01AAAAAAAAAAAAAAAAAAAAAAAA/locales/nl/bindings',expected=404)
 if ai_mode:
  proposals={}
  pages={}
  for key in ['homepage','over-ons','voor-webshops']:
   content={'title':'Welkom','hero':{'heading':'Welkom'},'body':{'type':'doc','content':[]}}
   pages[key]=call('create_ai_page_'+key,'POST','/api/v1/admin/cms/pages',{'key':key,'type':'landing','locales':{'nl':{'slug':key,'content':content}}},expected=201)['data']['id']
   result=call('legacy_'+key,'POST','/api/v1/admin/content/'+key+'/ai-suggest',{'locale':'nl','section':'hero','request_key':'capture-'+key},expected=202)
   proposals[key]=result['data']['proposal']['id']
  call('legacy_alias','POST','/api/v1/admin/content/pages/voor-webshops/ai-suggest',{'locale':'nl','section':'hero','request_key':'capture-alias'},expected=202)
  canonical=call('canonical_request','POST','/api/v1/admin/cms/pages/'+pages['homepage']+'/locales/nl/suggestions',{'kind':'improve','idempotency_key':'capture-canonical'},expected=201)['data']['id']
  worker=work/'worker.php';worker.write_text(r'''<?php
require getcwd().'/vendor/autoload.php';$app=require getcwd().'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\Http::fake(['api.openai.com/*'=>function($r){$i=json_decode($r['input'],true);return Illuminate\Support\Facades\Http::response(['status'=>'completed','usage'=>['input_tokens'=>10,'output_tokens'=>5],'output'=>[['type'=>'message','role'=>'assistant','content'=>[['type'=>'output_text','text'=>json_encode(['key'=>$i['key'],'source_hash'=>$i['source_hash'],'locale'=>$i['locale'],'segments'=>array_column($i['segments'],'text'),'review'=>true,'warnings'=>[]])]]]]]);}]);
Illuminate\Support\Facades\Artisan::call('queue:work',['connection'=>'database','--queue'=>'cms-translations','--stop-when-empty'=>true,'--tries'=>1,'--timeout'=>45]);
''')
  subprocess.run(['php',str(worker)],env=env,check=True,stdout=subprocess.DEVNULL)
  call('canonical_poll','GET','/api/v1/admin/cms/suggestions/'+canonical)
  for surface in ['homepage','over-ons','voor-webshops','pages/voor-webshops']:
   key='voor-webshops' if surface=='pages/voor-webshops' else surface
   request_key='capture-alias' if surface=='pages/voor-webshops' else 'capture-'+key
   call('ready_'+surface,'POST','/api/v1/admin/content/'+surface+'/ai-suggest',{'locale':'nl','section':'hero','request_key':request_key})
  call('proposal_list','GET','/api/v1/admin/cms/pages/'+pages['homepage']+'/suggestions')
  sid=proposals['homepage'];base='/api/v1/admin/cms/suggestions/'+sid
  call('proposal_poll','GET',base)
  call('apply_without_review','POST',base+'/apply',{},expected=409)
  review={'locale':'nl','language_correct':True,'meaning_preserved':True,'note':'Synthetic independent language and meaning review; not live model quality evidence.'}
  call('self_review','POST',base+'/review',review,expected=403)
  call('consumer_review','POST',base+'/review',review,actor='consumer',expected=403)
  call('anonymous_review','POST',base+'/review',review,actor=None,expected=401)
  call('review_missing_note','POST',base+'/review',{'locale':'nl','language_correct':True,'meaning_preserved':True},actor='reviewer',expected=422)
  call('independent_review','POST',base+'/review',review,actor='reviewer',expected=201)
  call('duplicate_review','POST',base+'/review',review,actor='reviewer',expected=409)
  call('apply_reviewed','POST',base+'/apply',{})
  call('missing_review_proposal','POST','/api/v1/admin/cms/suggestions/01AAAAAAAAAAAAAAAAAAAAAAAA/review',review,actor='reviewer',expected=404)
  other='/api/v1/admin/cms/suggestions/'+proposals['over-ons']
  call('negative_review','POST',other+'/review',{**review,'meaning_preserved':False},actor='reviewer',expected=201)
  call('apply_negative_review','POST',other+'/apply',{},expected=409)
 out={'captured_at':time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime()),'baseline':('6c01cc0 plus B81 working tree' if ai_mode else ('d31343a plus B80 working tree' if bindings_mode else '1fa850c plus B79 working tree')),'environment':'Actual Laravel loopback HTTP; temporary SQLite/storage/key, synthetic accounts/image. Temporary router attaches synthetic confirmed-password session except step_up case; this does NOT establish real cookie/CSRF/browser acceptance. Actual Sanctum authorization, validation, storage, GD transforms and synchronous jobs run. Deployed database queue worker and staging NOT RUN. Binary bytes captured as base64/hash/length. No provider calls.','ai_fixture':('Actual temporary database queue worker executes proposal jobs against explicitly synthetic OpenAI transport/pricing; unchanged copy is a structural fixture, not quality evidence. Two synthetic administrators exercise independent review. No live model/provider call.' if ai_mode else None),'exchanges':captures}
 (root/('docs/qa/B81_CMS_AI_HTTP_CAPTURE.json' if ai_mode else ('docs/qa/B80_BINDINGS_HTTP_CAPTURE.json' if bindings_mode else 'docs/qa/B79_MEDIA_HTTP_CAPTURE.json'))).write_text(json.dumps(out,indent=2)+'\n');print('Captured',len(captures),'actual HTTP responses.')
finally:
 if server:os.killpg(server.pid,signal.SIGTERM);server.wait(timeout=10)
 shutil.rmtree(work)
