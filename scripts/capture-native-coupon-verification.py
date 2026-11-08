import os,tempfile,subprocess,pathlib,base64,json,time,urllib.request,urllib.error,shutil,signal,sys
root=pathlib.Path.cwd();work=pathlib.Path(tempfile.mkdtemp(prefix='shopbonus-b71-'));env=os.environ.copy()
wix_only='--wix' in sys.argv
additional='--additional' in sys.argv
remaining='--remaining' in sys.argv
assert sum([wix_only, additional, remaining]) <= 1
for d in ['framework/cache/data','framework/sessions','framework/views','logs','app/private']:(work/'storage'/d).mkdir(parents=True,exist_ok=True)
db=work/'capture.sqlite';db.touch();env.update({'APP_ENV':'local','APP_DEBUG':'false','APP_KEY':'base64:'+base64.b64encode(os.urandom(32)).decode(),'DB_CONNECTION':'sqlite','DB_DATABASE':str(db),'DB_URL':'','LARAVEL_STORAGE_PATH':str(work/'storage'),'CACHE_STORE':'array','SESSION_DRIVER':'array','QUEUE_CONNECTION':'sync','MAIL_MAILER':'array','BROADCAST_CONNECTION':'null','ADMIN_MFA_REQUIRED':'false','OWNER_MFA_REQUIRED':'false','OWNER_STEP_UP_REQUIRED':'false','CAPTURE_PRIVATE':str(work/'private.json')})
seed=r'''<?php
require getcwd().'/vendor/autoload.php';$app=require getcwd().'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$owner=App\Models\User::factory()->create();$foreign=App\Models\User::factory()->create();$employee=App\Models\User::factory()->create();
class CaptureShops {use Tests\Concerns\CreatesActiveWebshops;public function make($u){return $this->activeWebshop($u,['name'=>'Synthetic coupon merchant','verified_domain'=>'shop.example.com']);}}
$records=[];foreach(['shopify','bigcommerce','ecwid','lightspeed_e'] as $platform){$shop=(new CaptureShops)->make($owner);
$shop->memberships()->create(['user_id'=>$employee->id,'role'=>'employee','status'=>'active']);
$input=match($platform){'shopify'=>['shop_domain'=>'synthetic.myshopify.com','signing_secret'=>'SYNTHETIC_signing_secret','access_token'=>'shpat_SYNTHETIC_token'],'bigcommerce'=>['store_hash'=>'abc123','access_token'=>'SYNTHETIC_bc_token','client_secret'=>'SYNTHETIC_bc_secret'],default=>['store_id'=>'1003','access_token'=>'SYNTHETIC_ecwid_token','client_secret'=>'SYNTHETIC_ecwid_secret']};
app(App\Integrations\ConnectionManager::class)->create($shop->memberships()->where('user_id',$owner->id)->sole(),App\Enums\EcommercePlatform::from($platform),$input);
$offer=app(App\Services\Offers\OfferService::class)->create($shop->memberships()->where('user_id',$owner->id)->sole(),App\Enums\OfferAcquisition::MerchantReward,['name'=>'Synthetic coupon','discount_type'=>'fixed','discount_value'=>1000,'currency'=>'EUR','min_order_minor'=>10000,'max_discount_minor'=>null,'redemption_validity_days'=>30,'combinable'=>false]);
$code=App\Models\VoucherCode::create(['webshop_id'=>$shop->id,'offer_id'=>$offer->id,'code'=>'SYNTHETIC-10','code_hash'=>App\Models\VoucherCode::hashFor($shop->id,'SYNTHETIC-10'),'code_last4'=>'C-10']);app(App\Services\Offers\OfferService::class)->transition($offer,App\Enums\OfferStatus::Active);$records[$platform]=['shop'=>$shop->public_id,'code'=>$code->public_id];}
file_put_contents(getenv('CAPTURE_PRIVATE'),json_encode(['token'=>$owner->createToken('capture')->plainTextToken,'foreign_token'=>$foreign->createToken('capture')->plainTextToken,'employee_token'=>$employee->createToken('capture')->plainTextToken,'records'=>$records]));chmod(getenv('CAPTURE_PRIVATE'),0600);
'''
if wix_only:
 seed=seed.replace("['shopify','bigcommerce','ecwid','lightspeed_e']", "['wix']").replace(
  "default=>['store_id'", r"'wix'=>['instance_id'=>Tests\Support\NativeCouponFixture::WIX_INSTANCE,'site_id'=>Tests\Support\NativeCouponFixture::WIX_SITE,'access_token'=>'SYNTHETIC_wix_token','public_key'=>file_get_contents(getcwd().'/tests/fixtures/rsa_public.pem')],default=>['store_id'")
if additional:
 seed=seed.replace("app(App\\Services\\Offers\\OfferService::class)->transition($offer,App\\Enums\\OfferStatus::Active);", "")
 seed=seed.replace("['shopify','bigcommerce','ecwid','lightspeed_e']", "['magento','squarespace','shopware6']").replace("'discount_type'=>'fixed'", "'discount_type'=>'percentage'").replace("'min_order_minor'=>10000", "'min_order_minor'=>$platform==='shopware6'?0:10000").replace(
  "default=>['store_id'", "'magento'=>['store_url'=>'https://shop.example.com','store_code'=>'default','store_id'=>'1','access_token'=>'SYNTHETIC_magento_token'],'squarespace'=>['website_id'=>'111111111111111111111111','api_key'=>'SYNTHETIC_squarespace_key'],'shopware6'=>['store_url'=>'https://shop.example.com','sales_channel_id'=>str_repeat('b',32),'client_id'=>'SYNTHETIC_shopware_client','client_secret'=>'SYNTHETIC_shopware_secret'],default=>['store_id'")
if remaining:
 seed=seed.replace("app(App\\Services\\Offers\\OfferService::class)->transition($offer,App\\Enums\\OfferStatus::Active);", "").replace("['shopify','bigcommerce','ecwid','lightspeed_e']", "['opencart','prestashop','ccv']").replace("'discount_type'=>'fixed'", "'discount_type'=>'percentage'").replace(
  "default=>['store_id'", "'opencart'=>['store_url'=>'https://shop.example.com','timezone'=>'Europe/Amsterdam'],'prestashop'=>['store_url'=>'https://shop.example.com','shop_id'=>'1','webservice_key'=>'SYNTHETIC_prestashop_key','timezone'=>'Europe/Amsterdam'],'ccv'=>['store_url'=>'https://shop.example.com','webshop_id'=>'1','api_key'=>'SYNTHETIC_ccv_key','api_secret'=>'SYNTHETIC_ccv_secret'],default=>['store_id'")
server=None
try:
 subprocess.run(['php','artisan','migrate','--force'],env=env,stdout=subprocess.DEVNULL,stderr=subprocess.PIPE,check=True)
 sf=work/'seed.php';sf.write_text(seed);subprocess.run(['php',str(sf)],env=env,check=True)
 data=json.loads((work/'private.json').read_text());router=work/'router.php';router.write_text("<?php\nrequire getcwd().'/vendor/autoload.php';$app=require getcwd().'/bootstrap/app.php';$kernel=$app->make(Illuminate\\Contracts\\Http\\Kernel::class);$kernel->bootstrap();\n$app->bind(App\\Services\\Onboarding\\Network\\HostResolver::class,fn()=>new class implements App\\Services\\Onboarding\\Network\\HostResolver {public function addresses(string $h):array{return ['93.184.216.34'];}public function txtRecords(string $h):array{return [];}});\n$platform=$_SERVER['HTTP_X_CAPTURE_PLATFORM']??'shopify';$scenario=$_SERVER['HTTP_X_CAPTURE_CASE']??'match';$native=Tests\\Support\\NativeCouponFixture::coupon($platform);\nif($platform==='shopify')$native['codeDiscount']['customerGets']['value']=['__typename'=>'DiscountAmount','amount'=>['amount'=>'10.00','currencyCode'=>'EUR'],'appliesOnEachItem'=>false];elseif($platform==='bigcommerce'){$native['type']='per_total_discount';$native['amount']='10.00';}else{$native['discountType']='ABS';$native['discount']='10.00';}\nif($scenario==='mismatch')$native['codeDiscount']['customerGets']['value']['amount']['amount']='11.00';\nif($scenario==='unknown')$native['codeDiscount']['context']['__typename']='DiscountContextUnknown';\nIlluminate\\Support\\Facades\\Http::fake(function($r)use($platform,$native,$scenario){if($scenario==='context_changed'&&str_contains($r['query']??'','ShopbonusCouponByCode')){preg_match('~/codes/([^/]+)/platform-validation~',$_SERVER['REQUEST_URI'],$m);$code=App\\Models\\VoucherCode::where('public_id',$m[1])->firstOrFail();app(App\\Services\\Offers\\OfferService::class)->update(App\\Models\\Webshop::findOrFail($code->webshop_id)->memberships()->where('role','owner')->sole(),$code->offer,['discount_value'=>2000]);}return Tests\\Support\\NativeCouponFixture::response($r,$platform,$native);});\n$request=Illuminate\\Http\\Request::capture();$response=$kernel->handle($request);$response->send();$kernel->terminate($request,$response);\n");log=open(work/'server.log','w');server=subprocess.Popen(['php','-S','127.0.0.1:8795',str(router)],env=env,stdout=log,stderr=log,start_new_session=True)
 for i in range(60):
  try:urllib.request.urlopen('http://127.0.0.1:8795/api/v1/health',timeout=1);break
  except Exception:time.sleep(.2)
 if wix_only:
  # Re-read the router on the next request with a fixed minimum-subtotal Wix coupon.
  router.write_text(router.read_text().replace("else{$native['discountType']", "elseif($platform==='wix'){unset($native['specification']['percentOffRate']);$native['specification']['moneyOffAmount']=10;}else{$native['discountType']"))
 if additional:
  source=router.read_text()
  start=source.index("if($platform==='shopify')")
  end=source.index("Illuminate\\Support\\Facades\\Http::fake",start)
  source=source[:start]+"if($scenario==='mismatch'){if($platform==='magento')$native['discount_amount']='11';elseif($platform==='shopware6')$native['discounts'][0]['value']='11';else $native['template']['percentage']=11;}\nif($scenario==='unknown'){if($platform==='magento')$native['uses_per_customer']=1;elseif($platform==='shopware6')$native['customerRestriction']=true;else $native['isOncePerCustomer']=true;}\n"+source[end:]
  router.write_text(source)
 if remaining:
  source=router.read_text()
  start=source.index("if($platform==='shopify')")
  end=source.index("Illuminate\\Support\\Facades\\Http::fake",start)
  source=source[:start]+"if($scenario==='mismatch')$native[$platform==='prestashop'?'reduction_percent':'discount']='11.00';\nif($scenario==='unknown')$native[match($platform){'opencart'=>'logged','prestashop'=>'quantity_per_user',default=>'productwhitelist'}]=match($platform){'opencart'=>1,'prestashop'=>'0',default=>true};\n"+source[end:]
  source=source.replace("return Tests\\Support\\NativeCouponFixture::response($r,$platform,$native);", "preg_match('~/codes/([^/]+)/platform-validation~',$_SERVER['REQUEST_URI'],$m);$code=App\\Models\\VoucherCode::where('public_id',$m[1]??'')->first();$secret=$code?App\\Models\\WebshopConnection::where('webshop_id',$code->webshop_id)->first()?->credential('signing_secret'):null;return Tests\\Support\\NativeCouponFixture::response($r,$platform,$native,'EUR',true,false,$secret);")
  router.write_text(source)
 captures=[]


 cases=[(p,'match',data['token'],200) for p in data['records']]+[('shopify','mismatch',data['token'],200),('shopify','unknown',data['token'],200),('shopify','context_changed',data['token'],200),('shopify','foreign_workspace',data['foreign_token'],404),('shopify','no_manage_permission',data['employee_token'],403),('shopify','unauthenticated',None,401),('shopify','invalid_mode',data['token'],422)]
 if additional:
  cases=[(p,label,data['token'],200) for p in data['records'] for label in ['match','mismatch','unknown']]+[('magento','foreign_workspace',data['foreign_token'],404),('squarespace','no_manage_permission',data['employee_token'],403),('shopware6','unauthenticated',None,401)]
 if remaining:
  cases=[(p,label,data['token'],200) for p in data['records'] for label in ['match','mismatch','unknown']]+[('opencart','foreign_workspace',data['foreign_token'],404),('prestashop','no_manage_permission',data['employee_token'],403),('ccv','unauthenticated',None,401)]
 for platform,label,token,expected in cases[:1] if wix_only else cases:
  r=data['records'][platform];path='/api/v1/workspaces/'+r['shop']+'/codes/'+r['code']+'/platform-validation'
  payload={'mode':'invalid' if label=='invalid_mode' else 'provider_check'}
  headers={'Accept':'application/json','Content-Type':'application/json','X-Capture-Platform':platform,'X-Capture-Case':label}
  if token:headers['Authorization']='Bearer '+token
  try:response=urllib.request.urlopen(urllib.request.Request('http://127.0.0.1:8795'+path,headers=headers,method='POST',data=json.dumps(payload).encode()))
  except urllib.error.HTTPError as e:response=e
  body=json.loads(response.read());assert response.status==expected,(label,response.status,body)
  if wix_only:assert body['data']['platform_validation']=='verified',body
  if additional and expected==200:assert body['data']['platform_validation']=={'match':'verified','mismatch':'mismatch','unknown':'merchant_verification_required'}[label],body
  if remaining and expected==200:assert body['data']['platform_validation']==('merchant_verification_required' if platform=='ccv' and label=='match' else {'match':'verified','mismatch':'mismatch','unknown':'merchant_verification_required'}[label]),body
  captures.append({'label':platform+'_'+label,'request':{'method':'POST','path':path,'body':payload,'headers':{'Accept':'application/json',**({'Authorization':'Bearer <token>'} if token else {})}},'response':{'status':response.status,'body':body}})

 out={'captured_at':time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime()),'baseline':'52ef8c5 plus B71 working tree','environment':'Actual Laravel loopback HTTP; private temporary SQLite/key/storage. Synthetic native provider transport and public resolver fixtures are installed only in the disposable router; actual authentication, workspace permissions, lookups, comparison and persistence execute. No genuine provider, checkout acceptance or redemption proof. X-Capture headers are harness-only controls, not production API inputs.','exchanges':captures}
 if wix_only:out['baseline']='904239a plus Wix working tree'
 name='B71_WIX_COUPON_HTTP_CAPTURE.json' if wix_only else 'B71_COUPON_VERIFICATION_HTTP_CAPTURE.json'
 if additional:
  name='B71_ADDITIONAL_COUPON_HTTP_CAPTURE.json'
  out['baseline']='06d43d9 plus additional coupon adapter working tree'
 if remaining:
  name='B71_REMAINING_COUPON_HTTP_CAPTURE.json'
  out['baseline']='06d43d9 plus remaining coupon adapter working tree'
 (root/'docs/qa'/name).write_text(json.dumps(out,indent=2)+'\n');print(f'Captured {len(captures)} HTTP responses.')
finally:
 if server:
  server.terminate() if os.name=='nt' else os.killpg(server.pid,signal.SIGTERM)
  server.wait(timeout=10)
  log.close()
 shutil.rmtree(work)
