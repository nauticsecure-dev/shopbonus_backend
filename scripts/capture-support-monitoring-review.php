<?php

// Actual HTTP monitoring responses, isolated synthetic SQLite fixtures.
require dirname(__DIR__).'/vendor/autoload.php';
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;

$root = dirname(__DIR__);
$database = $root.'/storage/framework/support-monitoring-'.bin2hex(random_bytes(8)).'.sqlite';
$log = $database.'.log';
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
try {
    Artisan::call('migrate', ['--force' => true]);
    $admin = User::factory()->create(['email' => 'support-admin@example.test']);
    $admin->platformRoles()->create(['role' => 'super_admin', 'granted_via' => 'console', 'granted_at' => now()]);
    $consumer = User::factory()->create(['email' => 'support-consumer@example.test']);
    $adminToken = $admin->createToken('synthetic-capture')->plainTextToken;
    $consumerToken = $consumer->createToken('synthetic-capture')->plainTextToken;
    $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    $address = stream_socket_get_name($listener, false);
    fclose($listener);
    $server = proc_open([PHP_BINARY, '-d', 'xdebug.mode=off', '-S', $address, '-t', $root.'/public', $root.'/public/index.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $root, options: ['create_no_window' => true]);
    $captures = [];
    foreach ([['', $adminToken, 200], ['', null, 401], ['', $consumerToken, 403], ['?status=INVALID', $adminToken, 422]] as [$query, $token, $expected]) {
        $url = '/api/v1/admin/ai/operations'.$query;
        for ($i = 0; $i < 30; $i++) {
            $curl = curl_init('http://'.$address.$url);
            curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => array_filter(['Accept: application/json', $token ? 'Authorization: Bearer '.$token : null])]);
            $body = curl_exec($curl);
            $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            curl_close($curl);
            if ($status !== 0) {
                break;
            } usleep(100000);
        }
        if ($status !== $expected) {
            throw new RuntimeException('Unexpected monitoring status '.$status.': '.$body);
        }
        $captures[] = ['request' => 'GET '.$url, 'authorization' => $token ? 'Bearer <SYNTHETIC_TOKEN>' : null,
            'status' => $status, 'response' => json_decode($body, true, flags: JSON_THROW_ON_ERROR)];
    }
    file_put_contents($root.'/docs/api/SUPPORT_MONITORING_REVIEW_HTTP_CAPTURE.json', json_encode(['captured_at' => now()->toIso8601String(),
        'environment' => 'Actual loopback HTTP, isolated synthetic SQLite, broadcaster disabled; 9546163 plus uncommitted review fixes. Local Reverb proof is separate.', 'captures' => $captures], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
    echo count($captures)." actual HTTP monitoring captures passed.\n";
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
