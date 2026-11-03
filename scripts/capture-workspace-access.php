<?php

// Real HTTP evidence with an isolated SQLite fixture; never uses the application database.
require dirname(__DIR__).'/vendor/autoload.php';

use App\Models\User;
use App\Models\Webshop;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\CreatesActiveWebshops;

$root = dirname(__DIR__);
$database = $root.'/storage/framework/workspace-access-http.sqlite';
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
$log = $root.'/storage/logs/workspace-access-capture-server.log';
try {
    Artisan::call('migrate', ['--force' => true]);
    $owner = User::factory()->create(['email' => 'workspace-access@example.test']);
    $helper = new class
    {
        use CreatesActiveWebshops;

        public function create(User $user): Webshop
        {
            return $this->activeWebshop($user, ['name' => 'Access fixture']);
        }
    };
    $shop = $helper->create($owner);
    app(\App\Services\Audit\AuditLogger::class)->record('team.member_updated', category: \App\Enums\AuditCategory::Workspace, webshopId: $shop->id);
    $token = $owner->createToken('isolated-access-capture')->plainTextToken;
    $server = proc_open([PHP_BINARY, '-d', 'xdebug.mode=off', '-S', $address, '-t', $root.'/public', $root.'/public/index.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $root);
    if (! is_resource($server)) {
        throw new RuntimeException('HTTP server did not start.');
    }
    $captures = [];
    foreach ([
        ['/api/v1/workspaces/'.$shop->public_id.'/access-history', true, 200],
        ['/api/v1/workspaces/'.$shop->public_id.'/access-history', false, 401],
        ['/api/v1/workspaces/'.$shop->public_id.'/access-history?page=0', true, 422],
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
    file_put_contents($root.'/docs/api/WORKSPACE_ACCESS_HTTP_CAPTURE.json', json_encode([
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
