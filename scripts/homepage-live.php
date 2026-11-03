<?php

// Real Laravel acceptance runtime. Never points at the developer's configured database.
require dirname(__DIR__).'/vendor/autoload.php';
use App\Enums\OfferAcquisition;
use App\Enums\OfferStatus;
use App\Enums\PlatformRole;
use App\Enums\VoucherCodeStatus;
use App\Models\AuditEvent;
use App\Models\CmsAsset;
use App\Models\CmsRevision;
use App\Models\Offer;
use App\Models\User;
use App\Models\VoucherCode;
use App\Models\WebshopPublicProfile;
use App\Services\Cms\CmsService;
use App\Services\Cms\HomepageCmsService;
use App\Services\Offers\OfferService;
use App\Services\Wallet\WalletService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesActiveWebshops;

$root = dirname(__DIR__);
$runtime = $root.'/storage/framework/homepage-live';
$database = $runtime.'/acceptance.sqlite';
$action = $argv[1] ?? '';
foreach (['app/private', 'framework/cache/data', 'framework/sessions', 'framework/views', 'logs'] as $folder) {
    if (! is_dir($runtime.'/'.$folder)) {
        mkdir($runtime.'/'.$folder, 0700, true);
    }
}
$settings = [
    'APP_ENV' => 'local', 'APP_DEBUG' => 'false', 'APP_URL' => 'http://localhost:8017', 'FRONTEND_URL' => 'http://localhost:3017',
    'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'DB_URL' => '', 'LARAVEL_STORAGE_PATH' => $runtime,
    'CACHE_STORE' => 'file', 'SESSION_DRIVER' => 'database', 'SESSION_COOKIE' => 'shopbonus_homepage_acceptance',
    'SESSION_DOMAIN' => 'localhost', 'SESSION_SECURE_COOKIE' => 'false', 'SESSION_SAME_SITE' => 'lax',
    'SANCTUM_STATEFUL_DOMAINS' => 'localhost:3017,localhost:8017', 'CORS_ALLOWED_ORIGINS' => 'http://localhost:3017',
    'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'BROADCAST_CONNECTION' => 'null', 'ADMIN_MFA_REQUIRED' => 'true',
    'TELESCOPE_ENABLED' => 'false', 'NIGHTWATCH_ENABLED' => 'false', 'PULSE_ENABLED' => 'false',
    'LEGAL_TERMS_VERSION' => 'acceptance-terms', 'LEGAL_PRIVACY_VERSION' => 'acceptance-privacy',
];
foreach ($settings as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
if ($action === 'serve') {
    if (file_exists($database)) {
        throw new RuntimeException('Acceptance database already exists; inspect and explicitly clean it before a fresh run.');
    }
    touch($database);
}
if (! file_exists($database)) {
    throw new RuntimeException('Acceptance database is absent.');
}
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if ($app->configurationIsCached()) {
    throw new RuntimeException('Cached configuration would defeat runtime isolation.');
}
if (config('database.connections.sqlite.database') !== $database) {
    throw new RuntimeException('Unexpected database configuration.');
}
if ($action === 'serve') {
    Artisan::call('migrate', ['--force' => true]);
    $admin = User::factory()->create(['email' => 'homepage-admin@example.test', 'first_name' => 'Homepage Admin', 'password' => 'Acceptance-only-2026!']);
    $admin->platformRoles()->create(['role' => PlatformRole::SuperAdmin, 'granted_via' => 'console', 'granted_at' => now()]);
    $consumer = User::factory()->create(['email' => 'homepage-consumer@example.test', 'first_name' => 'Homepage Consumer', 'password' => 'Acceptance-only-2026!']);
    $wallets = app(WalletService::class);
    $wallets->credit($wallets->consumerWallet($consumer), 1234, 'homepage-live-seed', neverExpires: true);
    $cms = app(CmsService::class);
    $page = $cms->ensureHomepage($admin->id);
    foreach (['nl', 'en'] as $locale) {
        $state = app(HomepageCmsService::class)->adminState($locale);
        $content = $state['draft'];
        $content['hero']['heading'] = $locale === 'nl' ? 'Live NL publicatie' : 'Live EN publication';
        $content['seo_title'] = $locale === 'nl' ? 'Live NL SEO' : 'Live EN SEO';
        $content['nav']['links'] = [['label' => $locale === 'nl' ? 'Webshops' : 'Webshops', 'href' => '/webshops'], ['label' => $locale === 'nl' ? 'Kortingscodes' : 'Discount codes', 'href' => '/kortingscodes']];
        $content['footer']['blurb'] = $locale === 'nl' ? 'Live NL voettekst' : 'Live EN footer';
        $cms->saveDraft($page, $locale, $content, $state['draft_revision'], null, $admin->id);
        $cms->publish($page, $locale, $admin->id);
    }
    $fixture = new class
    {
        use CreatesActiveWebshops;

        public function shop(User $user, string $name)
        {
            return $this->activeWebshop($user, ['name' => $name]);
        }
    };
    foreach (['alpha' => 'Alpha Acceptance Shop', 'beta' => 'Beta Acceptance Shop'] as $slug => $name) {
        $shop = $fixture->shop($consumer, $name);
        WebshopPublicProfile::create(['webshop_id' => $shop->id, 'slug' => 'acceptance-'.$slug, 'display_name' => $name, 'short_description' => 'Real local acceptance merchant', 'published' => true, 'published_at' => now(), 'first_published_at' => now()]);
        $member = $shop->memberships()->sole()->setRelation('webshop', $shop);
        $offer = app(OfferService::class)->create($member, OfferAcquisition::PromotionalCode, ['name' => 'Acceptance '.$slug.' coupon', 'discount_type' => 'percentage', 'discount_value' => 500, 'redemption_validity_days' => 30, 'code_mode' => 'reusable', 'ends_at' => now()->addDay()]);
        $offer = app(OfferService::class)->transition($offer, OfferStatus::Active);
        VoucherCode::create(['webshop_id' => $shop->id, 'offer_id' => $offer->id, 'code' => 'ACCEPTANCE'.strtoupper($slug), 'code_hash' => VoucherCode::hashFor($shop->id, 'ACCEPTANCE'.strtoupper($slug)), 'code_last4' => strtoupper(substr($slug, -4)), 'status' => VoucherCodeStatus::Available, 'reusable' => true]);
    }
    echo "Real Laravel acceptance database ready; MFA and CSRF enabled.\n";
    flush();
    $server = proc_open([PHP_BINARY, '-d', 'variables_order=EGPCS', '-S', '127.0.0.1:8018', '-t', $root.'/public', $root.'/public/index.php'], [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR], $pipes, $root);
    if (! is_resource($server)) {
        throw new RuntimeException('Cannot start real Laravel HTTP server.');
    }
    register_shutdown_function(function () use ($server) {
        if (is_resource($server)) {
            proc_terminate($server);
        }
    });
    exit(proc_close($server));
}
if ($action === 'reset-mfa') {
    User::where('email', 'homepage-admin@example.test')->sole()->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();
} elseif ($action === 'wallet-down') {
    if (Schema::hasTable('wallets')) {
        Schema::rename('wallets', 'acceptance_wallets_unavailable');
    }
} elseif ($action === 'wallet-up') {
    if (Schema::hasTable('acceptance_wallets_unavailable')) {
        Schema::rename('acceptance_wallets_unavailable', 'wallets');
    }
} elseif ($action === 'credit') {
    $user = User::where('email', 'homepage-consumer@example.test')->sole();
    app(WalletService::class)->credit(app(WalletService::class)->consumerWallet($user), 250, 'homepage-live-recovery-credit', neverExpires: true);
} elseif ($action === 'coupon-expiry' || $action === 'coupon-reset') {
    foreach (Offer::all() as $offer) {
        $member = $offer->webshop->memberships()->sole()->setRelation('webshop', $offer->webshop);
        app(OfferService::class)->update($member, $offer, ['ends_at' => $action === 'coupon-reset' ? now()->addDay() : now()->addSeconds(12)]);
    }
} elseif ($action === 'verify') {
    echo json_encode(['users' => User::count(), 'revisions' => CmsRevision::count(), 'assets' => CmsAsset::count(), 'publish_audits' => AuditEvent::where('action', 'like', 'cms.%')->count(), 'wallet_reconciles' => app(WalletService::class)->reconcile() === []]);
    exit;
} else {
    throw new RuntimeException('Unknown acceptance action.');
}
echo json_encode(['action' => $action, 'ok' => true]);
