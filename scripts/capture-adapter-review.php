<?php

// Isolated SQLite fixture + real loopback HTTP; no application database or provider writes.
require dirname(__DIR__).'/vendor/autoload.php';

use App\Enums\EcommercePlatform;
use App\Integrations\ConnectionManager;
use App\Models\User;
use App\Models\Webshop;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;

$root = dirname(__DIR__);
$database = $root.'/storage/framework/adapter-review-'.bin2hex(random_bytes(8)).'.sqlite';
touch($database);
foreach (['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'DB_URL' => '',
    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
    'BROADCAST_CONNECTION' => 'null', 'ADMIN_MFA_REQUIRED' => 'false', 'OWNER_MFA_REQUIRED' => 'false', 'OWNER_STEP_UP_REQUIRED' => 'false'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$server = null;
$log = $root.'/storage/logs/adapter-review-'.bin2hex(random_bytes(8)).'.log';
try {
    Artisan::call('migrate', ['--force' => true]);
    $user = User::factory()->create(['email' => 'adapter-review@example.test']);
    $shop = Webshop::factory()->withMember($user)->create(['created_by' => $user->id]);
    $member = $shop->memberships()->sole();
    [$presta] = app(ConnectionManager::class)->create($member, EcommercePlatform::PrestaShop, []);
    [$open] = app(ConnectionManager::class)->create($member, EcommercePlatform::OpenCart, []);
    $token = $user->createToken('isolated-adapter-capture')->plainTextToken;
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $server = proc_open([PHP_BINARY, '-S', $address, '-t', $root.'/public', $root.'/public/index.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $root, options: ['create_no_window' => true]);
    $captures = [];
    foreach (['/api/v1/integrations/platforms', '/api/v1/workspaces/'.$shop->public_id.'/connections/'.$presta->public_id,
        '/api/v1/workspaces/'.$shop->public_id.'/connections/'.$open->public_id] as $path) {
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $curl = curl_init('http://'.$address.$path);
            curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: Bearer '.$token]]);
            $body = curl_exec($curl);
            $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            curl_close($curl);
            if ($status !== 0) {
                break;
            }
            usleep(100000);
        }
        if ($status !== 200) {
            throw new RuntimeException('Unexpected capture status '.$status.' for '.$path);
        }
        $captures[] = ['request' => 'GET '.$path, 'authorization' => 'Bearer <redacted>', 'status' => $status, 'response' => json_decode($body, true, flags: JSON_THROW_ON_ERROR)];
    }
    file_put_contents($root.'/docs/api/ADAPTER_REVIEW_PLATFORMS_HTTP_CAPTURE.json', json_encode(['captured_at' => now()->toIso8601String(),
        'environment' => 'Real loopback HTTP / isolated SQLite synthetic fixtures; backend HassanDev parent b92f46a + uncommitted adapter review; no live providers', 'captures' => $captures], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
    echo count($captures)." real HTTP responses captured.\n";
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
