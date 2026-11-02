<?php

// Isolated fixture database; real local HTTP responses, no external providers.
require dirname(__DIR__).'/vendor/autoload.php';

use App\Models\User;
use App\Models\Webshop;
use App\Models\WebshopPublicProfile;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;

$root = dirname(__DIR__);
$database = $root.'/storage/framework/affiliate-detail-http.sqlite';
if (file_exists($database)) {
    throw new RuntimeException('Capture database already exists; inspect before reuse.');
}
touch($database);
foreach ([
    'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database,
    'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
    'MAIL_MAILER' => 'array', 'BROADCAST_CONNECTION' => 'null', 'ADMIN_MFA_REQUIRED' => 'false',
] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($socket === false) {
    throw new RuntimeException('Cannot reserve capture port.');
}
$address = stream_socket_get_name($socket, false);
fclose($socket);
$server = null;
$log = $root.'/storage/logs/affiliate-detail-capture.log';
try {
    Artisan::call('migrate', ['--force' => true]);
    $consumer = User::factory()->create(['email' => 'affiliate-fixture@example.test']);
    $shop = Webshop::factory()->approved()->create(['name' => 'Affiliate capture fixture']);
    WebshopPublicProfile::create(['webshop_id' => $shop->id, 'slug' => 'affiliate-fixture',
        'display_name' => 'Affiliate capture fixture', 'published' => true]);
    $token = $consumer->createToken('isolated-http-capture')->plainTextToken;
    $server = proc_open([PHP_BINARY, '-S', $address, '-t', $root.'/public', $root.'/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $root.'/public');
    if (! is_resource($server)) {
        throw new RuntimeException('Cannot start capture server.');
    }
    $captures = [];
    foreach ([
        ['/api/v1/public/webshops/affiliate-fixture', false, 200],
        ['/api/v1/consumer/wallet/transactions?filter=pending', true, 200],
        ['/api/v1/consumer/affiliate-activity?locale=en', true, 200],
        ['/api/v1/public/webshops/affiliate-fixture/cover?width=640', false, 404],
    ] as [$path, $authorized, $expected]) {
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $curl = curl_init('http://'.$address.$path);
            curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_ENCODING => '',
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
            throw new RuntimeException('Unexpected HTTP '.$status.' for '.$path);
        }
        $captures[] = ['request' => 'GET '.$path, 'authorization' => $authorized ? 'Bearer <redacted>' : null,
            'status' => $status, 'response' => json_decode((string) $body, true, flags: JSON_THROW_ON_ERROR)];
    }
    file_put_contents($root.'/docs/api/AFFILIATE_DETAIL_HTTP_CAPTURE.json', json_encode([
        'captured_at' => now()->toIso8601String(), 'environment' => 'Real local PHP HTTP server, isolated SQLite; synthetic records and no provider calls',
        'captures' => $captures,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
    echo count($captures)." HTTP captures recorded.\n";
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
