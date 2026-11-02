<?php

// Real HTTP evidence with an isolated SQLite fixture; never uses the application database.
require dirname(__DIR__).'/vendor/autoload.php';

use App\Enums\OfferAcquisition;
use App\Enums\OfferStatus;
use App\Models\BannerPairing;
use App\Models\User;
use App\Models\Webshop;
use App\Services\Banners\BannerAi;
use App\Services\Banners\BannerService;
use App\Services\Offers\OfferService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesActiveWebshops;
use Tests\Support\BannerFixture;

$root = dirname(__DIR__);
$database = $root.'/storage/framework/banner-studio-http.sqlite';
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
$log = $root.'/storage/logs/banner-studio-capture-server.log';
try {
    Artisan::call('migrate', ['--force' => true]);
    $owner = User::factory()->create();
    $fixture = new class
    {
        use CreatesActiveWebshops;

        public function make(User $owner): Webshop
        {
            return $this->activeWebshop($owner, ['verified_domain' => 'banner-fixture.test']);
        }
    };
    $shop = $fixture->make($owner);
    $offers = app(OfferService::class);
    $offer = $offers->transition($offers->create($shop->memberships()->sole()->setRelation('webshop', $shop), OfferAcquisition::MerchantReward,
        ['name' => 'Fixture next-order reward', 'discount_type' => 'fixed', 'discount_value' => 1000, 'min_order_minor' => 10000, 'redemption_validity_days' => 90]), OfferStatus::Active);
    $cfg = BannerFixture::config();
    $cfg['placement']['width'] = 360;
    $cfg['placement']['height'] = 180;
    $cfg['layers'] = array_map(fn ($type) => ['type' => $type, 'visible' => true, 'align' => 'left', 'font_size' => 16, 'width_pct' => 100], ['text', 'image', 'cta']);
    $banners = app(BannerService::class);
    $campaign = $banners->create($shop, 'Isolated studio fixture', $offer, $cfg, $owner->id);
    $banners->publish($campaign, $owner->id);
    $installation = $banners->installation($shop);
    $token = 'isolated-fixture-editor-token';
    BannerPairing::create(['token_hash' => BannerPairing::hashToken($token), 'webshop_id' => $shop->id, 'user_id' => $owner->id,
        'origin' => 'https://banner-fixture.test', 'kind' => 'editor', 'expires_at' => now()->addMinutes(5)]);
    // Provider fixture only: no network or genuine model acceptance is implied.
    Http::preventStrayRequests();
    Http::fake(['api.openai.com/*' => Http::response(BannerFixture::output())]);
    config(['banner_ai.enabled' => true, 'banner_ai.api_key' => 'isolated-fixture-key', 'banner_ai.model' => 'fixture-model']);
    $run = app(BannerAi::class)->request($campaign->fresh(), $owner->id, 'designs', (string) Str::uuid());
    $ownerToken = $owner->createToken('isolated-banner-capture')->plainTextToken;
    $server = proc_open([PHP_BINARY, '-d', 'xdebug.mode=off', '-S', $address, '-t', $root.'/public', $root.'/public/index.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $root);
    if (! is_resource($server)) {
        throw new RuntimeException('HTTP server did not start.');
    }
    $captures = [];
    foreach ([
        ['/api/v1/banners/config?key='.$installation->public_key, false, 200],
        ['/api/v1/banners/editor/campaigns/'.$campaign->public_id, true, 200],
        ['/api/v1/banners/editor/campaigns/'.$campaign->public_id, false, 401],
        ['/api/v1/workspaces/'.$shop->public_id.'/banners/'.$campaign->public_id.'/ai-requests/'.$run->public_id, 'merchant', 200],
        ['/api/v1/workspaces/'.$shop->public_id.'/banners/'.$campaign->public_id.'/ai-requests', 'merchant', 200],
    ] as [$url, $authorized, $expected]) {
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $curl = curl_init($baseUrl.$url);
            curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTPHEADER => array_filter(['Accept: application/json', 'Origin: https://banner-fixture.test', $authorized === 'merchant' ? 'Authorization: Bearer '.$ownerToken : ($authorized ? 'X-Banner-Editor-Token: '.$token : null)])]);
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
        $captures[] = ['request' => 'GET '.$url, 'authorization' => $authorized === 'merchant' ? 'Bearer <isolated-fixture>' : ($authorized ? 'X-Banner-Editor-Token: <isolated-fixture>' : null),
            'status' => $status, 'response' => json_decode((string) $body, true, flags: JSON_THROW_ON_ERROR)];
    }
    file_put_contents($root.'/docs/api/BANNER_STUDIO_HTTP_CAPTURE.json', json_encode([
        'captured_at' => now()->toIso8601String(), 'environment' => 'Real local PHP HTTP server, isolated SQLite fixture; AI provider synthetic via Http::fake; no production data or model acceptance',
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
