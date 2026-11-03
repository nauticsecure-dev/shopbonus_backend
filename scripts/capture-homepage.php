<?php

// Reproducible local HTTP evidence. Uses only an isolated SQLite fixture database.
require dirname(__DIR__).'/vendor/autoload.php';

use App\Enums\PlatformRole;
use App\Models\User;
use App\Services\Cms\CmsService;
use App\Services\Cms\HomepageCmsService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;

$root = dirname(__DIR__);
$database = $root.'/storage/framework/homepage-http.sqlite';
if (file_exists($database)) {
    throw new RuntimeException('Capture database already exists; inspect before reusing.');
}
touch($database);
foreach ([
    'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database,
    'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
    'MAIL_MAILER' => 'array', 'BROADCAST_CONNECTION' => 'null', 'ADMIN_MFA_REQUIRED' => 'false',
    'OWNER_MFA_REQUIRED' => 'false', 'OWNER_STEP_UP_REQUIRED' => 'false', 'TELESCOPE_ENABLED' => 'false',
    'NIGHTWATCH_ENABLED' => 'false', 'PULSE_ENABLED' => 'false',
] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($socket === false) {
    throw new RuntimeException('Cannot reserve a local capture port.');
}
$baseUrl = 'http://'.stream_socket_get_name($socket, false);
fclose($socket);
$address = substr($baseUrl, strlen('http://'));
$server = null;
$log = $root.'/storage/logs/homepage-capture-server.log';
try {
    Artisan::call('migrate', ['--force' => true]);
    $admin = User::factory()->create(['onboarding_intent' => 'merchant']);
    $admin->platformRoles()->create(['role' => PlatformRole::SuperAdmin, 'granted_via' => 'console', 'granted_at' => now()]);
    $consumer = User::factory()->create(['onboarding_intent' => 'consumer']);
    $cms = app(CmsService::class);
    $page = $cms->ensureHomepage($admin->id);
    $state = app(HomepageCmsService::class)->adminState('en');
    $content = $state['draft'];
    $content['section_order'] = ['search', 'hero', 'benefits', 'cta_band', 'faq_teaser'];
    $content['benefits'] = ['heading' => 'Benefits', 'consumer_title' => 'Consumers', 'consumer_items' => ['Save credit'], 'merchant_title' => 'Merchants', 'merchant_items' => ['Reach shoppers']];
    $content['cta_band'] = ['heading' => 'Start', 'subtitle' => 'Join us', 'consumer_cta' => ['label' => 'Join', 'href' => '/account'], 'merchant_cta' => ['label' => 'Register', 'href' => '/voor-webshops']];
    $content['faq_teaser'] = ['heading' => 'FAQ', 'subtitle' => 'Answers', 'cta_label' => 'Read more', 'cta_href' => '/faq'];
    $cms->saveDraft($page, 'en', $content, $state['draft_revision'], null, $admin->id);
    $cms->publish($page, 'en', $admin->id);
    $token = $admin->createToken('isolated-http-capture')->plainTextToken;
    $consumerToken = $consumer->createToken('isolated-denial-capture')->plainTextToken;
    $server = proc_open([PHP_BINARY, '-S', $address, '-t', $root.'/public', $root.'/public/index.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $root);
    if (! is_resource($server)) {
        throw new RuntimeException('HTTP server did not start.');
    }
    $captures = [];
    foreach ([
        ['/api/v1/content/homepage?locale=en', null, 200],
        ['/api/v1/public/discount-codes', null, 200],
        ['/api/v1/admin/content/homepage/versions?locale=en', $token, 200],
        ['/api/v1/admin/content/homepage/versions?locale=en', null, 401],
        ['/api/v1/admin/content/homepage/versions?locale=en', $consumerToken, 403],
    ] as [$url, $authorization, $expected]) {
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $curl = curl_init($baseUrl.$url);
            curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTPHEADER => array_filter(['Accept: application/json', $authorization ? 'Authorization: Bearer '.$authorization : null])]);
            $body = curl_exec($curl);
            $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            curl_close($curl);
            if ($status !== 0) {
                break;
            }
            usleep(100000);
        }
        $captures[] = ['request' => 'GET '.$url, 'authorization' => $authorization ? 'Bearer <redacted>' : null,
            'status' => $status, 'response' => json_decode((string) $body, true, flags: JSON_THROW_ON_ERROR)];
        if ($status !== $expected) {
            throw new RuntimeException('Unexpected HTTP '.$status.' for '.$url);
        }
    }
    file_put_contents($root.'/docs/api/HOMEPAGE_HTTP_CAPTURE.json', json_encode([
        'captured_at' => now()->toIso8601String(), 'base_url' => $baseUrl, 'environment' => 'Local PHP HTTP server, isolated SQLite fixtures; no external providers',
        'captures' => $captures,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
    echo count($captures)." real HTTP captures written using isolated SQLite fixtures.\n";
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    $app->make('db')->disconnect();
    gc_collect_cycles();
    @unlink($database);
    @unlink($log);
}
