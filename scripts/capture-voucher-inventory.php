<?php

// Real HTTP evidence with an isolated SQLite fixture; never uses the application database.
require dirname(__DIR__).'/vendor/autoload.php';

use App\Enums\VoucherOrigin;
use App\Models\Offer;
use App\Models\User;
use App\Models\VoucherExport;
use App\Models\Webshop;
use App\Services\Vouchers\VoucherInventory;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesActiveWebshops;

$root = dirname(__DIR__);
$database = $root.'/storage/framework/voucher-inventory-http.sqlite';
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
$log = $root.'/storage/logs/voucher-inventory-capture-server.log';
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
    $captures = [];
    foreach ([
        ['/api/v1/workspaces/'.$shop->public_id.'/dashboard', true, 200],
        ['/api/v1/workspaces/'.$shop->public_id.'/dashboard', false, 401],
        ['/api/v1/workspaces/'.$shop->public_id.'/dashboard?per_page=999', true, 422],
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
    // Real HTTP mutations/reads; synthetic inventory lifecycle uses the actual service.
    // No provider request, application database, or customer code is used.
    $request = function (string $method, string $path, ?array $payload, int $expected, bool $authorized = true, ?string $bearer = null) use ($baseUrl, $token, &$captures): array {
        $curl = curl_init($baseUrl.$path);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => array_filter(['Accept: application/json', 'Content-Type: application/json', $authorized ? 'Authorization: Bearer '.($bearer ?? $token) : null])]);
        if ($payload !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload, JSON_THROW_ON_ERROR));
        }
        $body = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if ($status !== $expected) {
            throw new RuntimeException('Unexpected HTTP '.$status.' for '.$method.' '.$path.': '.$body);
        }
        $data = str_starts_with((string) $body, 'voucher_id,')
            ? ['csv' => $body] : json_decode((string) $body, true, flags: JSON_THROW_ON_ERROR);
        $captures[] = ['request' => $method.' '.$path, 'payload' => $payload, 'authorization' => $authorized ? 'Bearer <redacted>' : null, 'status' => $status, 'response' => $data];

        return $data;
    };
    $workspace = '/api/v1/workspaces/'.$shop->public_id;
    $offer = $request('POST', $workspace.'/offers', [
        'acquisition' => 'wallet_purchase', 'name' => 'Inventory HTTP acceptance',
        'discount_type' => 'fixed', 'discount_value' => 1000, 'price_minor' => 1000,
        'claim_validity_days' => 90, 'redemption_validity_days' => 90,
    ], 201)['data']['id'];
    $batch = $request('POST', $workspace.'/offers/'.$offer.'/code-imports', ['codes' => "SYNTHETIC-HTTP-0001\nSYNTHETIC-HTTP-0002"], 201)['data']['id'];
    $request('POST', $workspace.'/code-imports/'.$batch.'/commit', [], 200);
    $request('POST', $workspace.'/offers/'.$offer.'/publish', [], 200);
    $assertStock = function (int $available, int $reserved) use ($request, $workspace): void {
        $kpis = $request('GET', $workspace.'/dashboard', null, 200)['data']['kpis'];
        if ($kpis['available_codes'] !== $available || $kpis['reserved_codes'] !== $reserved) {
            throw new RuntimeException('Inventory counters do not match committed stock.');
        }
    };
    $assertStock(2, 0);
    $inventory = app(VoucherInventory::class);
    $model = Offer::where('public_id', $offer)->firstOrFail();
    $voucher = $inventory->reserve($model, VoucherOrigin::WalletPurchase, 'synthetic-inventory-acceptance');
    $assertStock(1, 1);
    $replay = $inventory->reserve($model, VoucherOrigin::WalletPurchase, 'synthetic-inventory-acceptance');
    if ($replay->id !== $voucher->id) {
        throw new RuntimeException('Reservation replay allocated another voucher.');
    }
    $assertStock(1, 1);
    $inventory->release($voucher, 'synthetic recovery acceptance');
    $assertStock(2, 0);
    if (in_array('--offers-review', $argv, true)) {
        $request('POST', $workspace.'/offers/'.$offer.'/code-imports', ['codes' => 'SYNTHETIC-BATCH-PREVIEW'], 201);
        $request('GET', $workspace.'/offers/'.$offer.'/code-batches?per_page=1', null, 200);
        $request('GET', $workspace.'/offers/'.$offer.'/code-batches?per_page=1&page=2', null, 200);
        $request('GET', $workspace.'/offers/'.$offer.'/code-batches?per_page=201', null, 422);
        $request('GET', $workspace.'/offers/'.$offer.'/code-batches', null, 401, false);
        $staff = User::factory()->merchant()->create();
        $shop->memberships()->create(['user_id' => $staff->id, 'role' => 'employee', 'status' => 'active', 'permissions' => [], 'joined_at' => now()]);
        $request('GET', $workspace.'/offers/'.$offer.'/code-batches', null, 403, true, $staff->createToken('isolated-no-permission')->plainTextToken);
        $request('GET', $workspace.'/offers/'.strtolower((string) Str::ulid()).'/code-batches', null, 404);
        $code = $voucher->code;
        $request('POST', $workspace.'/codes/'.$code->public_id.'/platform-validation', ['mode' => 'provider_check'], 200);
        $request('POST', $workspace.'/codes/'.$code->public_id.'/platform-validation', ['mode' => 'provider_check', 'voucher_id' => $voucher->public_id], 200);
        $request('POST', $workspace.'/codes/'.$code->public_id.'/platform-validation', ['mode' => 'manual_confirm', 'note' => 'Synthetic local manual evidence; no provider checkout acceptance.'], 200);
        $request('POST', $workspace.'/codes/'.$code->public_id.'/platform-validation', ['mode' => 'provider_check', 'voucher_id' => strtolower((string) Str::ulid())], 404);
        file_put_contents($root.'/docs/api/OFFERS_CODE_REVIEW_HTTP_CAPTURE.json', json_encode([
            'captured_at' => now()->toIso8601String(),
            'environment' => 'Real local PHP HTTP server, isolated migrated SQLite; synthetic data; no provider requests',
            'captures' => $captures,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
        echo count($captures)." real HTTP captures recorded for offers review.\n";

        return;
    }
    if (in_array('--browser', $argv, true)) {
        putenv('LOCAL_ACCEPTANCE_API='.$baseUrl);
        putenv('LOCAL_ACCEPTANCE_WORKSPACE='.$shop->public_id);
        putenv('LOCAL_ACCEPTANCE_OFFER='.$offer);
        $browser = proc_open(['node', 'scripts/merchant-voucher-local-acceptance.cjs'],
            [0 => STDIN, 1 => STDOUT, 2 => STDERR], $browserPipes, dirname($root).'/shopbonus_frontend');
        if (! is_resource($browser) || proc_close($browser) !== 0) {
            throw new RuntimeException('Local browser acceptance failed; inspect frontend docs/merchant-voucher-local-browser.log.');
        }
    }
    $before = $request('GET', $workspace.'/dashboard', null, 200)['data']['kpis']['available_codes'];
    $large = $request('POST', $workspace.'/offers/'.$offer.'/code-imports', [
        'codes' => implode("\n", array_map(fn (int $i): string => sprintf('SYNTHETIC-QUEUE-%04d', $i), range(1, 2001))),
    ], 201)['data']['id'];
    $pending = $request('POST', $workspace.'/code-imports/'.$large.'/commit', [], 202);
    if ($pending['data']['status'] !== 'committing') {
        throw new RuntimeException('Large import did not queue.');
    }
    $request('GET', $workspace.'/code-imports/'.$large, null, 200);
    $runWorker = function (): void {
        if (Artisan::call('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--sleep' => 0, '--tries' => 1, '--max-time' => 30]) !== 0) {
            throw new RuntimeException('Isolated database worker failed.');
        }
    };
    $runWorker();
    $committed = $request('GET', $workspace.'/code-imports/'.$large, null, 200);
    if ($committed['data']['status'] !== 'committed') {
        throw new RuntimeException('Worker did not complete the large import.');
    }
    $assertStock($before + 2001, 0);
    $export = $request('GET', $workspace.'/vouchers/export', null, 202)['data']['id'];
    $request('GET', $workspace.'/vouchers/exports/'.$export, null, 200);
    $request('GET', $workspace.'/vouchers/exports/'.$export.'/download', null, 409);
    $runWorker();
    $ready = $request('GET', $workspace.'/vouchers/exports/'.$export, null, 200);
    $artifact = VoucherExport::where('public_id', $export)->firstOrFail();
    $exportPath = $artifact->path;
    if ($ready['data']['status'] !== 'ready') {
        throw new RuntimeException('Worker did not build the CSV.');
    }
    $request('GET', $workspace.'/vouchers/exports/'.$export.'/download', null, 200);
    $artifact->forceFill(['expires_at' => now()->subMinute()])->save();
    $request('GET', $workspace.'/vouchers/exports/'.$export.'/download', null, 410);
    file_put_contents($root.'/docs/api/VOUCHER_INVENTORY_HTTP_CAPTURE.json', json_encode([
        'captured_at' => now()->toIso8601String(), 'environment' => 'Real local PHP HTTP server, isolated SQLite fixture; no production data',
        'captures' => $captures,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
    echo count($captures)." real HTTP captures recorded.\n";
} catch (Throwable $failure) {
    fwrite(STDERR, $failure::class.': '.$failure->getMessage().PHP_EOL);
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    if ($exportPath !== null) {
        Storage::disk('local')->delete($exportPath);
    }
    $app->make('db')->disconnect();
    gc_collect_cycles();
    @unlink($database);
    @unlink($log);
}
if (isset($failure)) {
    exit(1);
}
