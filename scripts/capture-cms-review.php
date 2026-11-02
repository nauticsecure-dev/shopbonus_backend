<?php

require dirname(__DIR__).'/vendor/autoload.php';
use App\Enums\PlatformRole;
use App\Models\User;
use App\Services\Cms\CmsService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

$root = dirname(__DIR__);
$database = $root.'/storage/framework/cms-review-http.sqlite';
if (file_exists($database)) {
    throw new RuntimeException('Fixture already exists.');
}
touch($database);
foreach (['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'database', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'BROADCAST_CONNECTION' => 'null', 'ADMIN_MFA_REQUIRED' => 'false', 'OWNER_MFA_REQUIRED' => 'false', 'OWNER_STEP_UP_REQUIRED' => 'false', 'TELESCOPE_ENABLED' => 'false', 'NIGHTWATCH_ENABLED' => 'false', 'PULSE_ENABLED' => 'false'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$socket = stream_socket_server('tcp://127.0.0.1:0');
$address = stream_socket_get_name($socket, false);
fclose($socket);
$server = null;
$log = $root.'/storage/logs/cms-review-capture.log';
try {
    Artisan::call('migrate', ['--force' => true]);
    $admin = User::factory()->create(['email' => 'cms-review@example.test']);
    $admin->platformRoles()->create(['role' => PlatformRole::SuperAdmin, 'granted_via' => 'console', 'granted_at' => now()]);
    $cms = app(CmsService::class);
    $content = ['title' => 'Reviewed legal text', 'seo_title' => 'Legal', 'seo_description' => 'Fixture', 'body' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Reviewed content']]]]]];
    $page = $cms->create('review-http', 'terms', 'public', ['nl' => ['slug' => 'review-http', 'content' => $content]], $admin->id);
    $sessionId = str_repeat('a', 40);
    $csrf = str_repeat('b', 40);
    $cookieName = config('session.cookie');
    DB::table('sessions')->insert(['id' => $sessionId, 'user_id' => $admin->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'CMS regression', 'payload' => base64_encode(json_encode(['_token' => $csrf, app('auth')->guard('web')->getName() => $admin->id, 'auth.password_confirmed_at' => time()])), 'last_activity' => time()]);
    $cookie = Crypt::encryptString(CookieValuePrefix::create($cookieName, app('encrypter')->getKey()).$sessionId);
    $server = proc_open([PHP_BINARY, '-S', $address, '-t', $root.'/public', $root.'/public/index.php'], [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $root);
    $captures = [];
    $call = function ($method, $path, $body, $expected) use ($address, $cookieName, $cookie, $csrf, &$captures) {
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $curl = curl_init('http://'.$address.$path);
            curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json', 'Origin: http://localhost:3000', 'Referer: http://localhost:3000/', 'X-CSRF-TOKEN: '.$csrf, 'Cookie: '.$cookieName.'='.rawurlencode($cookie)]]);
            if ($body !== null) {
                curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body));
            }$raw = curl_exec($curl);
            $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            curl_close($curl);
            if ($status) {
                break;
            }usleep(100000);
        }
        if ($status !== $expected) {
            throw new RuntimeException($method.' '.$path.' returned '.$status.' '.$raw);
        }
        $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        $captures[] = ['request' => $method.' '.$path, 'body' => $body, 'status' => $status, 'response' => $decoded];

        return $decoded;
    };
    $url = '/api/v1/admin/cms/pages/'.$page->public_id.'/locales/nl';
    $legal = ['legal_version' => 'review-2026.11', 'effective_date' => '2026-11-02', 'approve' => true];
    $call('GET', $url.'/preview', null, 200);
    $call('POST', $url.'/publish', $legal, 422);
    $call('PUT', $url, ['revision' => 1, 'content' => array_replace($content, ['title' => 'New unreviewed draft'])], 200);
    $call('POST', $url.'/publish', $legal + ['revision' => 1], 409);
    $call('GET', $url.'/preview', null, 200);
    $call('POST', $url.'/publish', $legal + ['revision' => 2], 200);
    $call('GET', '/api/v1/public/pages/nl/review-http', null, 200);
    $call('GET', '/api/v1/public/faq?locale=en', null, 200);
    $call('GET', '/api/v1/public/cookie-policy?locale=en', null, 200);
    file_put_contents($root.'/docs/api/CMS_REVIEW_HTTP_CAPTURE.json', json_encode(['environment' => 'Real local PHP HTTP server with isolated SQLite and synthetic signed-in session; no production data', 'captures' => $captures], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
    echo count($captures)." real HTTP captures recorded.\n";
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }$app->make('db')->disconnect();
    gc_collect_cycles();
    @unlink($database);
    @unlink($log);
}
