<?php

// Actual HTTP contract evidence. All accounts, mail and data are isolated and synthetic.
require dirname(__DIR__).'/vendor/autoload.php';

use App\Jobs\DeliverContactMail;
use App\Models\User;
use App\Services\Admin\PlatformSettings;
use App\Services\Cms\CmsService;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationSources;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

$root = dirname(__DIR__);
$database = $root.'/storage/framework/final-followup-http.sqlite';
if (file_exists($database)) {
    throw new RuntimeException('Isolated fixture already exists; inspect before reuse.');
}
touch($database);
foreach ([
    'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
    'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'DB_URL' => '', 'CACHE_STORE' => 'database',
    'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'database', 'MAIL_MAILER' => 'array', 'BROADCAST_CONNECTION' => 'null',
    'ADMIN_MFA_REQUIRED' => 'false', 'OWNER_MFA_REQUIRED' => 'false', 'OWNER_STEP_UP_REQUIRED' => 'false',
] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$server = null;
$log = $root.'/storage/logs/final-followup-http.log';
try {
    Artisan::call('migrate', ['--force' => true]);
    $user = User::factory()->create(['locale' => 'de', 'email' => 'synthetic-consumer@example.test']);
    $other = User::factory()->create(['email' => 'synthetic-other@example.test']);
    foreach ([$user, $other] as $actor) {
        $actor->platformRoles()->create(['role' => 'super_admin', 'granted_via' => 'console', 'granted_at' => now()]);
    }
    $cms = app(CmsService::class);
    $translations = [];
    foreach (['nl' => 'Contact NL', 'en' => 'Contact EN', 'de' => 'Kontakt DE'] as $locale => $title) {
        $translations[$locale] = ['slug' => 'contact', 'content' => ['title' => $title, 'body' => ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $title]]],
        ]]]];
    }
    $contactPage = $cms->create('contact', 'info', 'public', $translations, $user->id);
    foreach (array_keys($translations) as $locale) {
        $cms->publish($contactPage, $locale, $user->id);
    }
    app(NotificationSources::class)->capture('email_verified', 'synthetic-verified', [$user->id]);
    app(NotificationSources::class)->capture('account_welcome', 'synthetic-welcome', [$user->id]);
    app(NotificationDispatcher::class)->drain();
    $notice = $user->notifications()->where('event_type', 'email_verified')->sole();
    $token = $user->createToken('isolated-followup')->plainTextToken;
    $foreignToken = $other->createToken('isolated-followup-foreign')->plainTextToken;
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $server = proc_open([PHP_BINARY, '-d', 'xdebug.mode=off', '-S', $address, '-t', $root.'/public', $root.'/public/index.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']], $pipes, $root, options: ['create_no_window' => true]);
    if (! is_resource($server)) {
        throw new RuntimeException('HTTP server did not start.');
    }
    $captures = [];
    $request = function (string $stage, string $method, string $path, int $expected, ?array $input = null, ?string $auth = null, string $locale = 'nl') use ($address, &$captures): array {
        $headers = ['Accept: application/json', 'Accept-Language: '.$locale, 'Content-Type: application/json'];
        if ($auth !== null) {
            $headers[] = 'Authorization: Bearer '.$auth;
        }
        $responseHeaders = [];
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $curl = curl_init('http://'.$address.$path);
            curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $headers, CURLOPT_HEADERFUNCTION => function ($curl, string $header) use (&$responseHeaders) {
                    $pair = explode(':', trim($header), 2);
                    if (count($pair) === 2 && in_array(strtolower($pair[0]), ['cache-control', 'x-request-id', 'content-type'], true)) {
                        $responseHeaders[strtolower($pair[0])] = trim($pair[1]);
                    }

                    return strlen($header);
                }]);
            if ($input !== null) {
                curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($input, JSON_THROW_ON_ERROR));
            }
            $body = curl_exec($curl);
            $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            curl_close($curl);
            if ($status !== 0) {
                break;
            }
            usleep(100000);
        }
        if ($status !== $expected) {
            throw new RuntimeException('Unexpected status at '.$stage.': '.$status);
        }
        $response = json_decode((string) $body, true, flags: JSON_THROW_ON_ERROR);
        $captures[] = ['stage' => $stage, 'request' => $method.' '.$path, 'input' => $input,
            'authorization' => $auth === null ? null : 'Bearer <redacted>', 'locale' => $locale,
            'status' => $status, 'headers' => $responseHeaders, 'response' => $response];

        return $response;
    };
    foreach (['webshops', 'webshops/missing', 'webshops/missing/visit', 'offers', 'offers/missing', 'search?q=shop', 'discount-codes'] as $path) {
        $request('guest_'.$path, 'GET', '/api/v1/public/'.$path, 401);
    }
    foreach (['webshops', 'offers', 'discount-codes', 'search?q=shop'] as $path) {
        $request('signed_in_'.$path, 'GET', '/api/v1/public/'.$path, 200, auth: $token);
    }
    $request('german_notifications', 'GET', '/api/v1/notifications', 200, auth: $token, locale: 'de');
    $request('read_notification', 'PATCH', '/api/v1/notifications/'.$notice->id.'/read', 200, auth: $token);
    $request('foreign_read_rejected', 'PATCH', '/api/v1/notifications/'.$notice->id.'/read', 404, auth: $foreignToken);
    $request('read_all', 'POST', '/api/v1/notifications/read-all', 200, auth: $token);
    $request('unread_empty', 'GET', '/api/v1/notifications?filter=unread', 200, auth: $token);
    foreach (['nl', 'en', 'de'] as $locale) {
        $page = $request('contact_cms_'.$locale, 'GET', '/api/v1/public/pages/'.$locale.'/contact', 200, locale: $locale);
        if ($page['data']['content']['title'] !== $translations[$locale]['content']['title']) {
            throw new RuntimeException('Unexpected contact CMS title.');
        }
        $request('contact_errors_'.$locale, 'POST', '/api/v1/public/contact', 422, ['privacy' => false], locale: $locale);
    }
    $input = ['name' => 'Synthetic Anna', 'email' => 'synthetic-sender@example.test', 'subject' => 'Synthetic question', 'message' => 'An isolated synthetic question.', 'privacy' => true];
    $request('contact_missing_destination', 'POST', '/api/v1/public/contact', 503, $input);
    $settings = app(PlatformSettings::class);
    $settings->publish($settings->submit($settings->draft($user, 'public.support_email', 'synthetic-support@example.test', 'Synthetic HTTP fixture'), $user), $other, 'Independent synthetic review');
    $request('contact_accepted', 'POST', '/api/v1/public/contact', 202, $input, locale: 'de');
    $request('contact_rate_limited', 'POST', '/api/v1/public/contact', 429, $input);
    $jobs = $app->make('db')->table('jobs')->get()->filter(fn ($job) => json_decode($job->payload, true)['displayName'] === DeliverContactMail::class)->values();
    if ($jobs->count() !== 1 || str_contains($jobs[0]->payload, $input['email'])) {
        throw new RuntimeException('Contact queue must contain one encrypted job.');
    }
    Artisan::call('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--max-jobs' => 20, '--sleep' => 0]);
    $sent = Mail::mailer()->getSymfonyTransport()->messages();
    if ($sent->count() !== 2 || $sent->last()->getOriginalMessage()->getSubject() !== __('contact.confirmation_subject', [], 'de')) {
        throw new RuntimeException('The local worker did not render both contact messages.');
    }
    $rendered = [];
    foreach ($sent as $message) {
        $email = $message->getOriginalMessage();
        $html = $email->getHtmlBody();
        $text = $email->getTextBody();
        if (! str_contains($html, '/brand/logo-full.png') || ! str_contains($html, 'lang="de"') || $text === '') {
            throw new RuntimeException('Contact branding, locale or multipart body is missing.');
        }
        $rendered[] = ['locale' => 'de', 'html_sha256' => hash('sha256', $html), 'text_sha256' => hash('sha256', $text), 'brand_png' => true];
    }
    $sha = trim((string) shell_exec('git -c safe.directory=C:/wamp64/www/shopbonus/shopbonus_backend rev-parse HEAD'));
    file_put_contents($root.'/docs/api/FINAL_FOLLOWUP_HTTP_CAPTURE.json', json_encode([
        'captured_at' => now()->toIso8601String(), 'git_sha' => $sha, 'source_state' => 'working_tree',
        'environment' => 'Real loopback HTTP / isolated SQLite / encrypted database queue / synthetic data; no external delivery',
        'queued_encrypted_contact_jobs' => 1, 'local_worker_array_transport_messages' => $rendered, 'captures' => $captures,
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
