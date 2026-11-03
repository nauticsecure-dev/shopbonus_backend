<?php

// Real loopback HTTP responses with isolated synthetic fixtures; no provider calls.
require dirname(__DIR__).'/vendor/autoload.php';

use App\Models\AffiliateClick;
use App\Models\AffiliateConnection;
use App\Models\AffiliateProgram;
use App\Models\AffiliateRewardRule;
use App\Models\AffiliateTransaction;
use App\Models\AuditEvent;
use App\Models\User;
use App\Models\WalletLedgerEntry;
use App\Models\Webshop;
use App\Services\Affiliate\AffiliateSync;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;

$root = dirname(__DIR__);
$database = $root.'/storage/framework/revised-brief-http.sqlite';
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
$server = null;
$log = $root.'/storage/logs/revised-brief-capture-server.log';
try {
    Artisan::call('migrate', ['--force' => true]);
    $consumer = User::factory()->create(['email' => 'brief-consumer@example.test']);
    $owner = User::factory()->create(['email' => 'brief-owner@example.test']);
    $shop = Webshop::factory()->withMember($owner)->create(['created_by' => $owner->id]);
    $connection = AffiliateConnection::create(['network' => 'daisycon', 'name' => 'Synthetic export', 'status' => 'connected',
        'postback_token_hash' => AffiliateConnection::hashToken('SYNTHETIC-brief-postback'),
        'credentials' => ['publisher_id' => '1', 'access_token' => 'SYNTHETIC', 'media_id' => '9']]);
    $program = AffiliateProgram::create(['connection_id' => $connection->id, 'external_id' => 'P1', 'name' => 'Synthetic program',
        'commission_type' => 'percentage', 'commission_value' => 475, 'tracking_url_template' => 'https://example.test/track',
        'webshop_id' => $shop->id, 'currency' => 'EUR', 'active' => true]);
    $click = AffiliateClick::create(['reference' => 'BRIEFCLICK0000000001', 'user_id' => $consumer->id, 'webshop_id' => $shop->id, 'program_id' => $program->id]);
    AffiliateRewardRule::create(['version' => 1, 'unit' => 'eur_credit', 'share_bps' => 10000, 'min_commission_minor' => 0,
        'active' => true, 'created_by' => $owner->id]);
    $row = ['affiliatemarketing_id' => 'BRIEF-475', 'program_id' => 'P1', 'date' => now()->toIso8601String(), 'last_modified' => now()->toIso8601String(),
        'parts' => [['subid' => $click->reference, 'referencenumber' => 'SYNTHETIC-ORDER', 'revenue' => 100, 'commission' => 4.75, 'status' => 'pending', 'currency_code' => 'EUR']]];
    $sync = app(AffiliateSync::class);
    $sync->import($connection, [], [$row], $owner->id);
    $sync->import($connection, [], [$row], $owner->id);
    $token = $consumer->createToken('isolated-brief-capture')->plainTextToken;
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if ($socket === false) {
        throw new RuntimeException('Cannot reserve HTTP capture port.');
    }
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $server = proc_open([PHP_BINARY, '-S', $address, '-t', $root.'/public', $root.'/public/index.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $root,
        options: ['create_no_window' => true]);
    if (! is_resource($server)) {
        throw new RuntimeException('HTTP server did not start.');
    }
    $captures = [];
    foreach ([
        ['after_import_and_replay', '/api/v1/consumer/wallet', 200],
        ['after_rejection', '/api/v1/consumer/wallet', 200],
        ['non_member', '/api/v1/workspaces/'.$shop->public_id.'/connections', 404],
        ['missing_cover', '/api/v1/public/webshops/missing-fixture/cover', 404],
    ] as [$stage, $path, $expected]) {
        if ($stage === 'after_rejection') {
            $row['last_modified'] = now()->addMinute()->toIso8601String();
            $row['parts'][0]['status'] = 'disapproved';
            $sync->import($connection, [], [$row], $owner->id);
        }
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $curl = curl_init('http://'.$address.$path);
            curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
                CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: Bearer '.$token]]);
            $body = curl_exec($curl);
            $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            curl_close($curl);
            if ($status !== 0) {
                break;
            }
            usleep(100000);
        }
        $response = json_decode((string) $body, true, flags: JSON_THROW_ON_ERROR);
        if ($status !== $expected || ($expected === 200 && (($response['data']['balances']['eur_credit']['available'] ?? null) !== 475
            || ($response['data']['balances']['eur_credit']['pending'] ?? null) !== 0))) {
            throw new RuntimeException('Unexpected response at '.$stage.': '.$status);
        }
        $captures[] = ['stage' => $stage, 'request' => 'GET '.$path, 'authorization' => 'Bearer <redacted>', 'status' => $status, 'response' => $response];
    }
    $transaction = AffiliateTransaction::sole();
    $creditCount = WalletLedgerEntry::where('source_ref', $transaction->public_id)->where('type', 'credit')->count();
    $denialCount = AuditEvent::where('action', 'workspace.access_denied')->where('http_status', 404)->count();
    if ($creditCount !== 1 || $denialCount !== 1) {
        throw new RuntimeException('Credit deduplication or denial audit failed.');
    }
    file_put_contents($root.'/docs/api/REVISED_BRIEF_HTTP_CAPTURE.json', json_encode([
        'captured_at' => now()->toIso8601String(), 'environment' => 'Real loopback HTTP server / isolated SQLite synthetic fixtures; no live provider evidence',
        'rule_share_bps' => 10000, 'credit_entries' => $creditCount, 'audited_non_member_denials' => $denialCount,
        'reward_timing' => $transaction->reward_timing, 'settlement_evidence' => $transaction->settlement_evidence, 'captures' => $captures,
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
