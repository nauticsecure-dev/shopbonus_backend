<?php

// Synthetic data, isolated SQLite, actual authenticated loopback HTTP. No provider writes.
require dirname(__DIR__).'/vendor/autoload.php';

use App\Models\User;
use App\Models\Webshop;
use App\Services\Banners\BannerService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesActiveWebshops;
use Tests\Support\BannerFixture;

$root = dirname(__DIR__);
$database = $root.'/storage/framework/experiment-capture-'.bin2hex(random_bytes(8)).'.sqlite';
$log = $database.'.log';
touch($database);
foreach (['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'DB_URL' => '',
    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
    'BROADCAST_CONNECTION' => 'null', 'OWNER_MFA_REQUIRED' => 'false', 'OWNER_STEP_UP_REQUIRED' => 'false'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$server = null;
try {
    Artisan::call('migrate', ['--force' => true]);
    $user = User::factory()->create(['email' => 'experiment-capture@example.test']);
    $fixture = new class
    {
        use CreatesActiveWebshops;

        public function shop(User $user): Webshop
        {
            return $this->activeWebshop($user);
        }
    };
    $shop = $fixture->shop($user);
    $service = app(BannerService::class);
    $campaign = $service->create($shop, 'Synthetic comparison', null, BannerFixture::config(), $user->id);
    $service->publish($campaign, $user->id);
    $token = $user->createToken('isolated-experiment-capture')->plainTextToken;
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $server = proc_open([PHP_BINARY, '-d', 'xdebug.mode=off', '-S', $address, '-t', $root.'/public', $root.'/public/index.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $root, options: ['create_no_window' => true]);
    $path = '/api/v1/workspaces/'.$shop->public_id.'/banners/'.$campaign->public_id.'/experiment';
    $captures = [];
    foreach (['launch', 'read', 'stop', 'unauthenticated'] as $action) {
        $method = in_array($action, ['launch', 'stop'], true) ? 'POST' : 'GET';
        $url = $path.($action === 'stop' ? '/stop' : '');
        $payload = $action === 'launch' ? ['control_version' => null, 'variant_version' => 1, 'variant_bps' => 5000] : null;
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $curl = curl_init('http://'.$address.$url);
            $headers = ['Accept: application/json', 'Content-Type: application/json'];
            if ($action !== 'unauthenticated') {
                $headers[] = 'Authorization: Bearer '.$token;
            }
            curl_setopt_array($curl, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => $headers]);
            if ($payload !== null) {
                curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload, JSON_THROW_ON_ERROR));
            }
            $body = curl_exec($curl);
            $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            curl_close($curl);
            if ($status !== 0) {
                break;
            }
            usleep(100000);
        }
        $expected = $action === 'launch' ? 201 : ($action === 'unauthenticated' ? 401 : 200);
        if ($status !== $expected) {
            throw new RuntimeException('Unexpected status '.$status.' for '.$action.': '.$body);
        }
        $captures[] = ['action' => $action, 'request' => $method.' '.$url, 'authorization' => $action === 'unauthenticated' ? null : 'Bearer <WORKSPACE_TOKEN>',
            'body' => $payload, 'status' => $status, 'response' => json_decode($body, true, flags: JSON_THROW_ON_ERROR)];
        if ($action === 'launch') {
            // Deliberately synthetic denominator only: never represent these as real visits or payments.
            $experiment = DB::table('banner_experiments')->sole();
            foreach (['control', 'variant'] as $arm) {
                DB::table('banner_assignments')->insert(['experiment_id' => $experiment->id, 'arm' => $arm,
                    'visitor_hash' => hash('sha256', $arm), 'token_hash' => hash('sha256', 'token'.$arm),
                    'created_at' => now(), 'expires_at' => now()->addDays(30)]);
            }
        }
    }
    file_put_contents($root.'/docs/api/EXPERIMENT_COMPARISON_HTTP_CAPTURE.json', json_encode(['captured_at' => now()->toIso8601String(),
        'environment' => 'Real loopback HTTP / isolated SQLite synthetic fixtures; HassanDev b92f46ab2b471fc4796310e059568bdddde276a4 plus uncommitted comparison; no live providers',
        'captures' => $captures], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
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
