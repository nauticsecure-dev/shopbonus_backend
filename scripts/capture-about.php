<?php

// Real HTTP evidence with an isolated SQLite fixture; never uses the application database.
require dirname(__DIR__).'/vendor/autoload.php';

use App\Enums\PlatformRole;
use App\Models\CmsAsset;
use App\Models\User;
use App\Services\Cms\AboutUsCmsService;
use App\Services\Cms\CmsService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;

$root = dirname(__DIR__);
$database = $root.'/storage/framework/about-http.sqlite';
if (file_exists($database)) {
    throw new RuntimeException('Capture fixture already exists; inspect before reusing.');
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
    throw new RuntimeException('Cannot reserve HTTP capture port.');
}
$address = stream_socket_get_name($socket, false);
fclose($socket);
$baseUrl = 'http://'.$address;
$server = null;
$log = $root.'/storage/logs/about-capture-server.log';
try {
    Artisan::call('migrate', ['--force' => true]);
    $admin = User::factory()->create(['email' => 'about-fixture@example.test']);
    $admin->platformRoles()->create(['role' => PlatformRole::SuperAdmin, 'granted_via' => 'console', 'granted_at' => now()]);
    $cms = app(CmsService::class);
    $page = $cms->ensureAboutUs();
    $state = app(AboutUsCmsService::class)->adminState('nl');
    $content = $state['draft'];
    $asset = CmsAsset::create(['path' => 'cms/about-fixture.webp', 'mime' => 'image/webp', 'width' => 800, 'height' => 600, 'uploaded_by' => $admin->id]);
    $content['media'] = ['hero' => ['desktop_asset_id' => $asset->public_id, 'mobile_asset_id' => $asset->public_id, 'alt' => 'Approved fixture photography']];
    $cms->saveDraft($page, 'nl', $content, $state['draft_revision'], null, $admin->id);
    $cms->publish($page, 'nl', $admin->id);
    $token = $admin->createToken('isolated-about-capture')->plainTextToken;
    $server = proc_open([PHP_BINARY, '-d', 'xdebug.mode=off', '-S', $address, '-t', $root.'/public', $root.'/public/index.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $root);
    if (! is_resource($server)) {
        throw new RuntimeException('HTTP server did not start.');
    }
    $captures = [];
    foreach ([
        ['/api/v1/content/over-ons?locale=nl', false, 200],
        ['/api/v1/content/over-ons?locale=en', false, 404],
        ['/api/v1/admin/content/over-ons?locale=nl', true, 200],
        ['/api/v1/admin/content/over-ons/versions?locale=nl', true, 200],
        ['/api/v1/admin/content/over-ons/versions?locale=nl', false, 401],
    ] as [$url, $authorized, $expected]) {
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $curl = curl_init($baseUrl.$url);
            curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTPHEADER => array_filter(['Accept: application/json', $authorized ? 'Authorization: Bearer '.$token : null])]);
            $body = curl_exec($curl);
            $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            curl_close($curl);
            if ($status !== 0) {
                break;
            }
            usleep(100000);
        }
        if ($status !== $expected) {
            throw new RuntimeException('Unexpected HTTP '.$status.' for '.$url);
        }
        $captures[] = ['request' => 'GET '.$url, 'authorization' => $authorized ? 'Bearer <redacted>' : null,
            'status' => $status, 'response' => json_decode((string) $body, true, flags: JSON_THROW_ON_ERROR)];
    }
    file_put_contents($root.'/docs/api/ABOUT_HTTP_CAPTURE.json', json_encode([
        'captured_at' => now()->toIso8601String(), 'environment' => 'Real local PHP HTTP server, isolated SQLite fixture; no production data',
        'captures' => $captures,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
    echo count($captures)." real HTTP captures recorded.\n";
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
