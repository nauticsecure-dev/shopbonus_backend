<?php

// Isolated local auth acceptance. Child probes only read synthetic fixture jobs/users.
require dirname(__DIR__).'/vendor/autoload.php';

use App\Models\User;
use App\Notifications\EmailVerificationCode;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;

$root = dirname(__DIR__);
$probe = ($argv[1] ?? '') === 'probe';
$database = $root.'/storage/framework/auth-browser-review.sqlite';
if (! $probe) {
    if (file_exists($database)) {
        throw new RuntimeException('Fixture exists; inspect it before rerunning.');
    }
    touch($database);
    foreach ([
        'APP_ENV' => 'local', 'APP_DEBUG' => 'false', 'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
        'APP_CONFIG_CACHE' => $root.'/storage/framework/auth-review-no-config.php',
        'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'DB_URL' => '',
        'SESSION_DRIVER' => 'database', 'SESSION_COOKIE' => 'shopbonus_auth_review', 'SESSION_DOMAIN' => '',
        'SESSION_SECURE_COOKIE' => 'false', 'SESSION_SAME_SITE' => 'lax',
        'CACHE_STORE' => 'database', 'QUEUE_CONNECTION' => 'database', 'MAIL_MAILER' => 'array',
        'BROADCAST_CONNECTION' => 'null', 'SANCTUM_STATEFUL_DOMAINS' => '127.0.0.1:3336',
        'CORS_ALLOWED_ORIGINS' => 'http://127.0.0.1:3336', 'CORS_SUPPORTS_CREDENTIALS' => 'true',
        'FRONTEND_URL' => 'http://127.0.0.1:3336', 'API_RATE_LIMIT_PER_MINUTE' => '600',
        'LEGAL_TERMS_VERSION' => 'review-terms', 'LEGAL_PRIVACY_VERSION' => 'review-privacy',
        'TELESCOPE_ENABLED' => 'false', 'NIGHTWATCH_ENABLED' => 'false', 'PULSE_ENABLED' => 'false',
    ] as $key => $value) {
        putenv($key.'='.$value);
        $_ENV[$key] = $_SERVER[$key] = $value;
    }
}
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if ($probe) {
    if (realpath(config('database.connections.sqlite.database')) !== realpath($database)) {
        throw new RuntimeException('Probe must target the isolated fixture.');
    }
    $email = $argv[3] ?? '';
    $user = User::where('email', $email)->sole();
    if (($argv[2] ?? '') === 'totp') {
        echo app(Google2FA::class)->getCurrentOtp(decrypt($user->two_factor_secret));
        exit;
    }
    if (($argv[2] ?? '') === 'recovery-used') {
        echo in_array('synthetic-recovery-code', $user->recoveryCodes(), true) ? 'no' : 'yes';
        exit;
    }
    foreach (DB::table('jobs')->orderByDesc('id')->get() as $job) {
        $payload = json_decode($job->payload, true, flags: JSON_THROW_ON_ERROR);
        $command = unserialize(decrypt($payload['data']['command']));
        if (isset($command->notification) && $command->notification instanceof EmailVerificationCode
            && $command->notifiables->first()?->id === $user->id) {
            echo $command->notification->code;
            exit;
        }
    }
    throw new RuntimeException('No queued fixture verification code found.');
}

$server = null;
try {
    Artisan::call('migrate', ['--force' => true]);
    $secret = app(Google2FA::class)->generateSecretKey();
    User::factory()->create([
        'email' => 'mfa@auth-review.test', 'password' => 'Auth-review-password-2026',
        'two_factor_secret' => encrypt($secret),
        'two_factor_recovery_codes' => encrypt(json_encode(['synthetic-recovery-code'], JSON_THROW_ON_ERROR)),
        'two_factor_confirmed_at' => now(),
    ]);
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if ($socket === false) {
        throw new RuntimeException('Cannot reserve test port.');
    }
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $server = proc_open([PHP_BINARY, '-d', 'xdebug.mode=off', '-S', $address, '-t', $root.'/public', $root.'/public/index.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $root.'/storage/logs/auth-browser-review.log', 'w'], 2 => ['file', $root.'/storage/logs/auth-browser-review.log', 'a']], $pipes, $root);
    if (! is_resource($server)) {
        throw new RuntimeException('Cannot start test server.');
    }
    putenv('AUTH_REVIEW_API=http://'.$address);
    putenv('AUTH_REVIEW_PHP='.PHP_BINARY);
    $browser = proc_open(['node', 'scripts/auth-browser-review.cjs'], [0 => STDIN, 1 => STDOUT, 2 => STDERR], $browserPipes, dirname($root).'/shopbonus_frontend');
    if (! is_resource($browser) || proc_close($browser) !== 0) {
        throw new RuntimeException('Browser review failed.');
    }
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    $app->make('db')->disconnect();
    foreach ([$database, $database.'-wal', $database.'-shm'] as $fixtureFile) {
        if (file_exists($fixtureFile)) {
            unlink($fixtureFile);
        }
    }
}
