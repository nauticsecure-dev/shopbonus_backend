<?php

// Reproducible local HTTP evidence. Uses only an isolated SQLite fixture database.
require dirname(__DIR__).'/vendor/autoload.php';

use App\Enums\OfferAcquisition;
use App\Enums\OfferStatus;
use App\Enums\PlatformRole;
use App\Enums\VoucherOrigin;
use App\Models\AffiliateConnection;
use App\Models\AffiliateTransaction;
use App\Models\User;
use App\Models\VoucherCode;
use App\Models\Webshop;
use App\Services\Offers\OfferService;
use App\Services\Vouchers\VoucherInventory;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\CreatesActiveWebshops;

$root = dirname(__DIR__);
$database = $root.'/storage/framework/cockpit-http.sqlite';
if (file_exists($database)) {
    throw new RuntimeException('Capture database already exists; inspect before reusing.');
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
    throw new RuntimeException('Cannot reserve a local capture port.');
}
$baseUrl = 'http://'.stream_socket_get_name($socket, false);
fclose($socket);
$address = substr($baseUrl, strlen('http://'));
$server = null;
$log = $root.'/storage/logs/cockpit-capture-server.log';
try {
    Artisan::call('migrate', ['--force' => true]);
    $path = 'database/migrations/2026_10_31_170000_add_voucher_acquisition_evidence.php';
    Artisan::call('migrate:rollback', ['--path' => $path, '--step' => 1, '--force' => true]);
    Artisan::call('migrate', ['--path' => $path, '--force' => true]);
    $admin = User::factory()->create(['onboarding_intent' => 'merchant']);
    $admin->platformRoles()->create(['role' => PlatformRole::SuperAdmin, 'granted_via' => 'console', 'granted_at' => now()]);
    $consumer = User::factory()->create(['onboarding_intent' => 'consumer']);
    $fixture = new class
    {
        use CreatesActiveWebshops;

        public function shop(User $owner): Webshop
        {
            return $this->activeWebshop($owner, ['name' => 'Cockpit HTTP fixture']);
        }
    };
    $shop = $fixture->shop($consumer);
    $offers = app(OfferService::class);
    $offer = $offers->transition($offers->create($shop->memberships()->sole()->setRelation('webshop', $shop), OfferAcquisition::MerchantReward,
        ['name' => 'HTTP fixture', 'discount_type' => 'fixed', 'discount_value' => 100, 'min_order_minor' => 500, 'redemption_validity_days' => 30]), OfferStatus::Active);
    $inventory = app(VoucherInventory::class);
    $connection = AffiliateConnection::create(['network' => 'daisycon', 'name' => 'Fixture only', 'status' => 'connected', 'credentials' => [], 'postback_token_hash' => hash('sha256', random_bytes(32))]);
    $transaction = AffiliateTransaction::create(['network' => 'daisycon', 'connection_id' => $connection->id,
        'external_id' => 'HTTP-FIXTURE', 'user_id' => $consumer->id, 'webshop_id' => $shop->id, 'order_amount_minor' => 1000,
        'currency' => 'EUR', 'commission_minor' => 100, 'network_status' => 'approved', 'status' => 'approved', 'reward_status' => 'none', 'transaction_at' => now()]);
    foreach (range(1, 2) as $i) {
        $code = 'HTTP-CODE-'.$i;
        VoucherCode::create(['webshop_id' => $shop->id, 'offer_id' => $offer->id, 'code' => $code,
            'code_hash' => VoucherCode::hashFor($shop->id, $code), 'code_last4' => substr($code, -4)]);
        $voucher = $inventory->issue($inventory->reserve($offer, VoucherOrigin::MerchantReward, 'http-'.$i), $consumer);
        if ($i === 1) {
            $inventory->recordAcquisition($voucher, $transaction);
        }
    }
    $token = $admin->createToken('isolated-http-capture')->plainTextToken;
    $consumerToken = $consumer->createToken('isolated-denial-capture')->plainTextToken;
    $server = proc_open([PHP_BINARY, '-S', $address, '-t', $root.'/public', $root.'/public/index.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $root);
    if (! is_resource($server)) {
        throw new RuntimeException('HTTP server did not start.');
    }
    $day = now()->setTimezone(config('shopbonus.business_timezone'))->toDateString();
    $captures = [];
    foreach ([
        ['/api/v1/admin/cockpit?period=custom&from='.$day.'&to='.$day, $token, 200],
        ['/api/v1/admin/vouchers?acquisition_network=daisycon&from='.$day.'&to='.$day, $token, 200],
        ['/api/v1/admin/vouchers?usable=1', $token, 200],
        ['/api/v1/admin/affiliate/transactions?status=approved&network=daisycon&from='.$day.'&to='.$day, $token, 200],
        ['/api/v1/admin/search?q=http-code-1', $token, 200],
        ['/api/v1/admin/users?role=consumer&status=active', $token, 200],
        ['/api/v1/admin/integrations', $token, 200],
        ['/api/v1/admin/integrations/failed-events', $token, 200],
        ['/api/v1/admin/cockpit', null, 401],
        ['/api/v1/admin/cockpit', $consumerToken, 403],
        ['/api/v1/admin/affiliate/transactions?from='.$day, $token, 422],
    ] as [$url, $authorization, $expected]) {
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $curl = curl_init($baseUrl.$url);
            curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTPHEADER => array_filter(['Accept: application/json', $authorization ? 'Authorization: Bearer '.$authorization : null])]);
            $body = curl_exec($curl);
            $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            curl_close($curl);
            if ($status !== 0) {
                break;
            }
            usleep(100000);
        }
        $captures[] = ['request' => 'GET '.$url, 'authorization' => $authorization ? 'Bearer <redacted>' : null,
            'status' => $status, 'response' => json_decode((string) $body, true, flags: JSON_THROW_ON_ERROR)];
        if ($status !== $expected) {
            throw new RuntimeException('Unexpected HTTP '.$status.' for '.$url);
        }
    }
    file_put_contents($root.'/docs/api/COCKPIT_HTTP_CAPTURE.json', json_encode([
        'captured_at' => now()->toIso8601String(), 'base_url' => $baseUrl, 'environment' => 'Local PHP HTTP server, isolated SQLite fixtures; no external providers',
        'migration_apply_rollback_reapply' => 'passed', 'captures' => $captures,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
    echo count($captures)." real HTTP captures written; migration apply/rollback/reapply passed.\n";
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
