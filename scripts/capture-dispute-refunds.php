<?php

// Real HTTP evidence with an isolated SQLite fixture; never uses the application database.
require dirname(__DIR__).'/vendor/autoload.php';

use App\Models\User;
use App\Models\VoucherDispute;
use App\Models\Webshop;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesActiveWebshops;

$root = dirname(__DIR__);
$database = $root.'/storage/framework/dispute-refund-http.sqlite';
if (file_exists($database)) {
    throw new RuntimeException('Capture fixture already exists; inspect before reusing.');
}
touch($database);
foreach ([
    'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database,
    'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'database', 'QUEUE_CONNECTION' => 'database',
    'VOUCHER_EXPORT_SYNC_MAX_ROWS' => '0',
    'SESSION_DOMAIN' => '', 'SESSION_SECURE_COOKIE' => 'false', 'SESSION_COOKIE' => 'shopbonus_local_acceptance',
    'SANCTUM_STATEFUL_DOMAINS' => '127.0.0.1:3334', 'CORS_ALLOWED_ORIGINS' => 'http://127.0.0.1:3334',
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
$exportPath = null;
$log = $root.'/storage/logs/dispute-refund-capture-server.log';
try {
    Artisan::call('migrate', ['--force' => true]);
    $owner = User::factory()->merchant()->create(['email' => 'merchant-dashboard@example.test']);
    $helper = new class
    {
        use CreatesActiveWebshops;

        public function create(User $user): Webshop
        {
            return $this->activeWebshop($user, ['name' => 'Dashboard fixture']);
        }
    };
    $shop = $helper->create($owner);
    $token = $owner->createToken('isolated-dashboard-capture')->plainTextToken;
    $server = proc_open([PHP_BINARY, '-d', 'xdebug.mode=off', '-d', 'sys_temp_dir='.$root.'/storage/framework', '-d', 'display_errors=0', '-S', $address, '-t', $root.'/public', $root.'/public/index.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $root);
    if (! is_resource($server)) {
        throw new RuntimeException('HTTP server did not start.');
    }
    $consumer = User::factory()->create(['email' => 'refund-fixture@example.test']);
    $consumerToken = $consumer->createToken('refund-capture')->plainTextToken;
    $dispute = VoucherDispute::create(['user_id' => $consumer->id, 'webshop_id' => $shop->id, 'reason' => 'missing_after_payment', 'description' => 'Synthetic refund lifecycle contract fixture', 'status' => 'resolved', 'compensation' => 'refund', 'compensation_amount_minor' => 1000, 'resolution_note' => 'Synthetic lifecycle fixture', 'resolved_at' => now()]);
    $captures = [];
    foreach (['requested', 'pending', 'failed', 'completed'] as $state) {
        $dispute->forceFill(['compensation_status' => $state, 'compensated_at' => $state === 'completed' ? now() : null])->save();
        $path = '/api/v1/consumer/disputes/'.$dispute->public_id;
        $curl = curl_init($baseUrl.$path);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: Bearer '.$consumerToken]]);
        $body = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if ($status !== 200) {
            throw new RuntimeException('Unexpected HTTP '.$status.': '.$body);
        }
        $captures[] = ['request' => 'GET '.$path, 'authorization' => 'Bearer <redacted>', 'fixture_state' => $state, 'status' => $status, 'response' => json_decode($body, true, flags: JSON_THROW_ON_ERROR)];
    }
    file_put_contents($root.'/docs/api/DISPUTE_REFUND_HTTP_CAPTURE.json', json_encode(['environment' => 'Isolated SQLite, local PHP HTTP server, synthetic states, no provider requests', 'captures' => $captures], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
    echo 'Captured four real HTTP responses; synthetic refund states, no provider calls.'.PHP_EOL;
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    DB::disconnect();
    if (file_exists($database)) {
        unlink($database);
    }
}
