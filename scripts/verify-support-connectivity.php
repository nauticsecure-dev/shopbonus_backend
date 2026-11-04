<?php

// Synthetic local Reverb only; no deployment settings or customer data are changed.
require dirname(__DIR__).'/vendor/autoload.php';
use App\Services\Support\ReverbRoundTrip;
use App\Services\Support\SupportConnectivity;
use Illuminate\Contracts\Console\Kernel;

$root = dirname(__DIR__);
$listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
$address = stream_socket_get_name($listener, false);
fclose($listener);
$port = explode(':', $address)[1];
foreach (['APP_ENV' => 'testing', 'APP_URL' => 'http://localhost', 'CACHE_STORE' => 'array', 'BROADCAST_CONNECTION' => 'reverb',
    'REVERB_APP_ID' => 'synthetic-probe', 'REVERB_APP_KEY' => 'synthetic-probe-key', 'REVERB_APP_SECRET' => 'synthetic-probe-secret',
    'REVERB_HOST' => '127.0.0.1', 'REVERB_PORT' => $port, 'REVERB_SCHEME' => 'http', 'REVERB_ALLOWED_ORIGINS' => 'localhost'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$log = $root.'/storage/framework/reverb-probe-'.bin2hex(random_bytes(8)).'.log';
$process = proc_open([PHP_BINARY, '-d', 'xdebug.mode=off', 'artisan', 'reverb:start', '--host=127.0.0.1', '--port='.$port],
    [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $root, options: ['create_no_window' => true]);
try {
    $ready = false;
    for ($i = 0; $i < 100; $i++) {
        $socket = @stream_socket_client('tcp://'.$address, $errno, $error, .1);
        if (is_resource($socket)) {
            fclose($socket);
            $ready = true;
            break;
        }
        usleep(100000);
    }
    if (! $ready) {
        throw new RuntimeException('Local Reverb failed to start.');
    }
    // Let transport exceptions surface for debugging without saving them as monitoring evidence.
    app(ReverbRoundTrip::class)->check();
    $success = app(SupportConnectivity::class)->run();
    if ($success['reverb_live_probe'] !== 'connected') {
        throw new RuntimeException('Round trip not confirmed.');
    }
    config(['broadcasting.connections.reverb.secret' => 'synthetic-wrong-secret']);
    $failure = app(SupportConnectivity::class)->run();
    if ($failure['reverb_live_probe'] !== 'failed') {
        throw new RuntimeException('Invalid credentials were accepted.');
    }
    config(['broadcasting.connections.reverb.secret' => 'synthetic-probe-secret']);
    $recovery = app(SupportConnectivity::class)->run();
    if ($recovery['reverb_live_probe'] !== 'connected') {
        throw new RuntimeException('Recovery failed.');
    }
    file_put_contents($root.'/docs/api/SUPPORT_REVERB_LOCAL_PROBE.json', json_encode(['scope' => 'actual local Reverb private-channel round trip, synthetic credentials; not deployment/browser/queue proof',
        'success' => $success, 'bad_credentials' => $failure, 'recovery' => $recovery], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
    echo "3 local Reverb checks passed: delivery, invalid credentials, recovery.\n";
} finally {
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
    @unlink($log);
}
