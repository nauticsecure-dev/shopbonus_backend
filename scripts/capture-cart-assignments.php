<?php

/** Real loopback HTTP with an isolated SQLite DB and explicitly synthetic native BigCommerce transport. */
require dirname(__DIR__).'/vendor/autoload.php';

use App\Enums\EcommercePlatform;
use App\Integrations\ConnectionManager;
use App\Models\User;
use App\Models\Webshop;
use App\Services\Banners\BannerExperiments;
use App\Services\Banners\BannerService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\CreatesActiveWebshops;
use Tests\Support\BannerFixture;

$root = dirname(__DIR__);
$database = $root.'/storage/framework/cart-capture-'.bin2hex(random_bytes(8)).'.sqlite';
$router = $database.'.php';
$log = $database.'.log';
touch($database);
foreach (['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'DB_URL' => '',
    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
    'BROADCAST_CONNECTION' => 'null', 'BIGCOMMERCE_CART_ASSIGNMENTS_ENABLED' => 'true'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$server = null;
try {
    Artisan::call('migrate', ['--force' => true]);
    $owner = User::factory()->create();
    $fixture = new class
    {
        use CreatesActiveWebshops;

        public function shop(User $owner): Webshop
        {
            return $this->activeWebshop($owner, ['verified_domain' => 'shop.example.com', 'ownership_verified_at' => now(), 'ownership_method' => 'dns_txt']);
        }
    };
    $shop = $fixture->shop($owner);
    $service = app(BannerService::class);
    $campaign = $service->create($shop, 'Synthetic cart bridge', null, BannerFixture::config(), $owner->id);
    $service->publish($campaign, $owner->id);
    app(BannerExperiments::class)->start($campaign, null, 1, 5000, $owner->id);
    app(ConnectionManager::class)->create($shop->memberships()->sole(), EcommercePlatform::BigCommerce,
        ['store_hash' => 'abc123', 'access_token' => 'SYNTHETIC_bigcommerce_token', 'client_secret' => 'SYNTHETIC_bigcommerce_secret']);
    $payload = ['key' => $service->installation($shop)->public_key, 'visitor_key' => 'bdd5903b-90d9-4c6b-b0d0-7757adafbd83', 'consent' => true,
        'consent_id' => BannerFixture::consentId(), 'cart_context' => ['platform' => 'bigcommerce', 'cart_id' => 'd30016e2-23c0-4a90-884f-2e92ac135476']];
    file_put_contents($router, <<<'PHP'
<?php
$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$app->bind(App\Services\Onboarding\Network\HostResolver::class, fn () => new class implements App\Services\Onboarding\Network\HostResolver {
    public function addresses(string $host): array { return ['93.184.216.34']; }
    public function txtRecords(string $host): array { return []; }
});
Illuminate\Support\Facades\Http::fake(function ($request) {
    if (str_contains($request->url(), '/v2/store')) return Illuminate\Support\Facades\Http::response(['id' => 'abc123', 'secure_url' => 'https://shop.example.com']);
    if (str_contains($request->url(), '/v3/carts/')) return Illuminate\Support\Facades\Http::response(['data' => ['id' => 'd30016e2-23c0-4a90-884f-2e92ac135476']]);
    throw new RuntimeException('Unreviewed synthetic provider request.');
});
$app->handleRequest(Illuminate\Http\Request::capture());
PHP);
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $server = proc_open([PHP_BINARY, '-d', 'xdebug.mode=off', '-S', $address, $router],
        [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $root, options: ['create_no_window' => true]);
    $captures = [];
    foreach (['bind' => 200, 'repeat' => 200, 'conflict' => 409, 'foreign_origin' => 403, 'invalid_cart' => 422, 'native_mismatch' => 502, 'withdraw' => 200] as $action => $expected) {
        $body = $payload;
        if ($action === 'conflict') {
            $body['visitor_key'] = 'acc3541f-04b2-4e12-a80a-2fdb3db92bf9';
        }
        if ($action === 'invalid_cart') {
            $body['cart_context']['cart_id'] = '250';
        }
        if ($action === 'native_mismatch') {
            $body['cart_context']['cart_id'] = 'd30016e2-23c0-4a90-884f-2e92ac135477';
        }
        if ($action === 'withdraw') {
            $body['consent'] = false;
        }
        $origin = $action === 'foreign_origin' ? 'https://foreign.example' : 'https://shop.example.com';
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $curl = curl_init('http://'.$address.'/api/v1/banners/assignments');
            curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
                CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json', 'Origin: '.$origin],
                CURLOPT_POSTFIELDS => json_encode($body, JSON_THROW_ON_ERROR)]);
            $response = curl_exec($curl);
            $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            curl_close($curl);
            if ($status !== 0) {
                break;
            }
            usleep(100000);
        }
        if ($status !== $expected) {
            throw new RuntimeException('Unexpected '.$action.' status '.$status.': '.$response);
        }
        $captures[] = ['action' => $action, 'request' => 'POST /api/v1/banners/assignments', 'origin' => $origin, 'body' => $body,
            'status' => $status, 'response' => json_decode($response, true, flags: JSON_THROW_ON_ERROR)];
    }
    file_put_contents($root.'/docs/api/CART_ASSIGNMENT_HTTP_CAPTURE.json', json_encode(['captured_at' => now()->toIso8601String(),
        'environment' => 'Real loopback Laravel HTTP; isolated synthetic SQLite; mocked native BigCommerce transport/DNS; no provider/storefront acceptance',
        'captures' => $captures], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
    echo count($captures)." real HTTP responses captured.\n";
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    $app->make('db')->disconnect();
    gc_collect_cycles();
    foreach ([$database, $router, $log] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
}
