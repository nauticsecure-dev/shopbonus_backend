<?php

// Real local MySQL processes with a disposable schema, usable on Windows without pcntl.
require dirname(__DIR__).'/vendor/autoload.php';

use App\Enums\OfferAcquisition;
use App\Enums\OfferStatus;
use App\Exceptions\ApiException;
use App\Models\Offer;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherCode;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Models\Webshop;
use App\Services\Checkout\CheckoutService;
use App\Services\Offers\OfferService;
use App\Services\Wallet\WalletService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesActiveWebshops;

$root = dirname(__DIR__);
$worker = ($argv[1] ?? '') === 'worker';
$schema = $worker ? ($argv[2] ?? '') : 'sb_qa_wallet_'.gmdate('Ymd').'_'.bin2hex(random_bytes(5));
if (! preg_match('/\Asb_qa_wallet_[0-9]{8}_[a-f0-9]{10}\z/D', $schema)) {
    throw new RuntimeException('Invalid isolated schema.');
}
foreach ([
    'APP_ENV' => 'testing', 'APP_DEBUG' => 'false',
    'APP_CONFIG_CACHE' => $root.'/storage/framework/wallet-race-no-config.php',
    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array',
    'QUEUE_CONNECTION' => 'sync', 'BROADCAST_CONNECTION' => 'null',
    'ARGON_MEMORY' => '1024', 'ARGON_TIME' => '1',
] as $name => $value) {
    putenv($name.'='.$value);
    $_ENV[$name] = $_SERVER[$name] = $value;
}
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$connection = config('database.connections.mysql');
if (! is_array($connection)) {
    throw new RuntimeException('Local MySQL configuration is unavailable.');
}
if (! in_array($connection['host'] ?? null, ['127.0.0.1', 'localhost', '::1'], true)) {
    throw new RuntimeException('Use a loopback MySQL host for this local capture.');
}
// Credentials remain in this process environment/configuration and never enter the report.
$connection['url'] = null;
$connection['database'] = $schema;
config(['database.connections.qa_wallet' => $connection, 'database.default' => 'qa_wallet']);

if ($worker) {
    try {
        $start = (float) ($argv[7] ?? 0);
        usleep((int) max(0, min(3_000_000, ($start - microtime(true)) * 1_000_000)));
        if (($argv[3] ?? '') === 'debit') {
            $entry = app(WalletService::class)->debit(
                Wallet::findOrFail((int) $argv[4]), 500, (string) $argv[6],
            );
            $result = 'entry:'.$entry->public_id;
        } else {
            $checkout = app(CheckoutService::class)->start(
                User::findOrFail((int) $argv[4]),
                Offer::with('currentVersion')->findOrFail((int) $argv[5]),
                (string) $argv[6],
            );
            $result = $checkout->state;
        }
        echo json_encode(['result' => $result], JSON_THROW_ON_ERROR);
    } catch (ApiException $error) {
        echo json_encode(['result' => 'err:'.$error->errorCode], JSON_THROW_ON_ERROR);
    } catch (Throwable $error) {
        echo json_encode(['result' => 'crash:'.$error::class], JSON_THROW_ON_ERROR);
        exit(1);
    }
    exit;
}

$report = [
    'captured_at' => gmdate(DATE_ATOM),
    'environment' => 'isolated local MySQL, real parallel PHP processes, synthetic accounts and offers',
    'schema' => $schema,
    'git_sha' => trim((string) shell_exec('git rev-parse HEAD')),
    'source_state' => 'working_tree',
    'provider_calls' => false,
    'cases' => [],
];
$admin = $connection;
$admin['database'] = null;
config(['database.connections.qa_wallet_admin' => $admin]);
$created = false;

function ensureRace(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

/** @param list<array{0:string,1:int,2:int,3:string}> $requests
 * @return list<string>
 */
function raceRequests(array $requests, string $schema, string $root): array
{
    $children = [];
    $start = microtime(true) + 2;
    try {
        foreach ($requests as [$operation, $actor, $offer, $key]) {
            $process = proc_open(
                [PHP_BINARY, '-d', 'xdebug.mode=off', __FILE__, 'worker', $schema, $operation, (string) $actor, (string) $offer, $key, (string) $start],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes, $root, options: ['create_no_window' => true],
            );
            if (! is_resource($process)) {
                throw new RuntimeException('A race worker could not start.');
            }
            fclose($pipes[0]);
            $children[] = [$process, $pipes];
        }
        $results = [];
        foreach ($children as [$process, $pipes]) {
            stream_set_timeout($pipes[1], 45);
            $body = stream_get_contents($pipes[1]);
            $timedOut = stream_get_meta_data($pipes[1])['timed_out'];
            if ($timedOut) {
                proc_terminate($process);
            }
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($process);
            if ($timedOut || $exit !== 0) {
                throw new RuntimeException('A race worker failed or timed out.');
            }
            $results[] = json_decode($body, true, flags: JSON_THROW_ON_ERROR)['result'];
        }

        return $results;
    } finally {
        foreach ($children as [$process]) {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
        }
    }
}

try {
    DB::connection('qa_wallet_admin')->statement('CREATE DATABASE `'.$schema.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $created = true;
    Artisan::call('migrate', ['--force' => true, '--database' => 'qa_wallet']);
    ensureRace(DB::connection()->getDatabaseName() === $schema, 'Isolated database check failed.');
    $wallets = app(WalletService::class);
    $wallet = $wallets->consumerWallet(User::factory()->create());
    $wallets->credit($wallet, 2000, 'race-seed');
    $requests = [];
    for ($index = 0; $index < 8; $index++) {
        $requests[] = ['debit', $wallet->id, 0, 'race-'.$index];
    }
    $results = raceRequests($requests, $schema, $root);
    ensureRace(count(array_filter($results, fn ($value) => str_starts_with($value, 'entry:'))) === 4, 'Expected four successful debits.');
    ensureRace(count(array_filter($results, fn ($value) => $value === 'err:insufficient_funds')) === 4, 'Expected four insufficient-funds refusals.');
    ensureRace($wallet->fresh()->available === 0 && $wallet->fresh()->reserved === 0, 'Race balance differs.');
    $report['cases'][] = ['name' => 'eight_debits_on_one_balance', 'status' => 'PASS', 'results' => $results, 'balance' => $wallet->fresh()->available];

    $fixture = new class
    {
        use CreatesActiveWebshops;

        public function shop(User $owner): Webshop
        {
            return $this->activeWebshop($owner);
        }
    };
    $shop = $fixture->shop(User::factory()->create());
    $offers = app(OfferService::class);
    $offer = $offers->transition($offers->create($shop->memberships()->sole()->setRelation('webshop', $shop), OfferAcquisition::WalletPurchase, [
        'name' => 'Local race voucher', 'discount_type' => 'fixed', 'discount_value' => 2000,
        'price_minor' => 2000, 'redemption_validity_days' => 30,
    ]), OfferStatus::Active);
    VoucherCode::create(['webshop_id' => $shop->id, 'offer_id' => $offer->id, 'code' => 'LOCAL-RACE-ONE',
        'code_hash' => VoucherCode::hashFor($shop->id, 'LOCAL-RACE-ONE'), 'code_last4' => '-ONE']);
    $buyer = User::factory()->create();
    $buyerWallet = $wallets->consumerWallet($buyer);
    $wallets->credit($buyerWallet, 2000, 'buyer-seed');
    $results = raceRequests([
        ['checkout', $buyer->id, $offer->id, 'same-checkout'],
        ['checkout', $buyer->id, $offer->id, 'same-checkout'],
    ], $schema, $root);
    ensureRace($results === ['completed', 'completed'], 'Checkout replay did not return one completed purchase.');
    ensureRace(Voucher::where('consumer_user_id', $buyer->id)->count() === 1, 'Checkout issued duplicate vouchers.');
    ensureRace(WalletLedgerEntry::where('wallet_id', $buyerWallet->id)->where('type', 'debit')->count() === 1, 'Checkout debited more than once.');
    ensureRace($buyerWallet->fresh()->available === 0, 'Checkout balance differs.');
    ensureRace($wallets->reconcile() === [], 'Wallet reconciliation differs.');
    $report['cases'][] = ['name' => 'concurrent_checkout_replay', 'status' => 'PASS', 'results' => $results, 'vouchers' => 1, 'debits' => 1, 'reconciliation' => []];
} catch (Throwable $error) {
    $report['status'] = 'FAIL';
    $report['error'] = $error instanceof RuntimeException && ! $error instanceof PDOException ? $error->getMessage() : $error::class;
    // Connection exceptions may contain credentials in their messages.
    if ($error instanceof QueryException) {
        $report['error'] = $error::class;
    }
    $failed = true;
} finally {
    DB::disconnect('qa_wallet');
    if ($created) {
        DB::connection('qa_wallet_admin')->statement('DROP DATABASE `'.$schema.'`');
    }
    $report['isolated_schema_removed'] = $created;
    $report['status'] ??= 'PASS';
    file_put_contents($root.'/../qa-evidence/final-integration-mysql-races.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
    echo $report['status']." local MySQL races.\n";
}
exit(empty($failed) ? 0 : 1);
