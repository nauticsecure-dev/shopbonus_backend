<?php

// Real Laravel acceptance runtime. Never points at the developer's configured database.
require dirname(__DIR__).'/vendor/autoload.php';
use App\Enums\PlatformRole;
use App\Models\User;
use App\Services\Cms\CmsService;
use App\Services\Cms\MerchantLandingCmsService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;

$root = dirname(__DIR__);
$runtime = $root.'/storage/framework/merchant-review';
$database = $runtime.'/acceptance.sqlite';
$action = $argv[1] ?? '';
foreach (['app/private', 'framework/cache/data', 'framework/sessions', 'framework/views', 'logs'] as $folder) {
    if (! is_dir($runtime.'/'.$folder)) {
        mkdir($runtime.'/'.$folder, 0700, true);
    }
}
$settings = [
    'APP_ENV' => 'local', 'APP_DEBUG' => 'false', 'APP_URL' => 'http://localhost:8026', 'FRONTEND_URL' => 'http://localhost:3026',
    'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'DB_URL' => '', 'LARAVEL_STORAGE_PATH' => $runtime,
    'CACHE_STORE' => 'file', 'SESSION_DRIVER' => 'database', 'SESSION_COOKIE' => 'shopbonus_merchant_acceptance',
    'SESSION_DOMAIN' => 'localhost', 'SESSION_SECURE_COOKIE' => 'false', 'SESSION_SAME_SITE' => 'lax',
    'SANCTUM_STATEFUL_DOMAINS' => 'localhost:3026,localhost:8026', 'CORS_ALLOWED_ORIGINS' => 'http://localhost:3026',
    'CMS_AI_ENABLED' => 'false', 'CMS_AI_AUTO_TRANSLATE' => 'false',
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

if (in_array($action, ['serve', 'resume'], true)) {
    if ($action === 'serve') {
        Artisan::call('migrate', ['--force' => true]);
        $admin = User::factory()->create(['email' => 'merchant-review@example.test', 'password' => 'Acceptance-only-2026!']);
        $admin->platformRoles()->create(['role' => PlatformRole::SuperAdmin, 'granted_via' => 'console', 'granted_at' => now()]);
        $cms = app(CmsService::class);
        $landing = app(MerchantLandingCmsService::class);
        foreach (['nl', 'en'] as $locale) {
            $state = $landing->adminState($locale);
            $content = $state['draft'];
            $content['hero']['heading'] = $locale === 'nl' ? 'Echte goedgekeurde publicatie' : 'Real approved publication';
            $cms->saveDraft($landing->page(), $locale, $content, $state['draft_revision'], null, $admin->id);
            $cms->publish($landing->page(), $locale, $admin->id);
        }
    }
    $server = proc_open([PHP_BINARY, '-d', 'variables_order=EGPCS', '-S', '127.0.0.1:8026', '-t', $root.'/public', $root.'/public/index.php'], [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR], $pipes, $root);
    if (! is_resource($server)) {
        throw new RuntimeException('Server failed');
    }
    register_shutdown_function(function () use ($server) {
        if (is_resource($server)) {
            proc_terminate($server);
        }
    });
    exit(proc_close($server));
}
if ($action === 'reset-mfa') {
    User::where('email', 'merchant-review@example.test')->sole()->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();
    exit;
}
if ($action === 'run-schedules') {
    Artisan::call('shopbonus:publish-scheduled-content');
    echo Artisan::output();
    exit;
}
throw new RuntimeException('Unknown acceptance action');
