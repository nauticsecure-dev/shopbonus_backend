<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Throwable;

/**
 * Production launch readiness (Trello #22): checks configuration that must
 * hold before go-live. Only check names and outcomes are printed; no
 * configured value or secret is ever shown. FAIL blocks launch, WARN needs
 * an explicit owner decision, MANUAL cannot be proven from configuration.
 */
class LaunchCheck extends Command
{
    protected $signature = 'shopbonus:launch-check {--json : Machine-readable output}';

    protected $description = 'Check production configuration for launch readiness without printing secrets';

    public function handle(Migrator $migrator): int
    {
        $https = fn (?string $url) => is_string($url) && str_starts_with(strtolower($url), 'https://');
        $origins = (array) config('cors.allowed_origins');
        $checks = [
            ['app.environment', config('app.env') === 'production', 'FAIL', 'APP_ENV must be production.'],
            ['app.debug_disabled', config('app.debug') === false, 'FAIL', 'APP_DEBUG must be false.'],
            ['app.key_set', is_string(config('app.key')) && strlen((string) config('app.key')) >= 32, 'FAIL', 'APP_KEY must be generated.'],
            ['app.url_https', $https(config('app.url')), 'FAIL', 'APP_URL must be https.'],
            ['session.secure_cookie', config('session.secure') === true, 'FAIL', 'SESSION_SECURE_COOKIE must be true.'],
            ['session.http_only', config('session.http_only') === true, 'FAIL', 'Session cookie must be HttpOnly.'],
            ['session.same_site', in_array(config('session.same_site'), ['lax', 'strict'], true), 'FAIL', 'SESSION_SAME_SITE must be lax or strict.'],
            ['session.driver_shared', ! in_array(config('session.driver'), ['array', 'cookie'], true), 'FAIL', 'Use a server-side session driver.'],
            ['cors.explicit_https_origins', $origins !== [] && collect($origins)->every(fn ($o) => $o !== '*' && $https($o)), 'FAIL', 'CORS_ALLOWED_ORIGINS must list only https frontends.'],
            ['sanctum.stateful_domains', ($stateful = array_filter(array_map('trim', (array) config('sanctum.stateful')))) !== []
                && collect($stateful)->every(fn ($d) => ! preg_match('/\A(localhost|127\.0\.0\.1|::1|\[::1\])(:\d+)?\z/', $d)), 'FAIL', 'SANCTUM_STATEFUL_DOMAINS must list only the production frontends.'],
            ['security.hsts', (int) config('shopbonus.security.hsts_max_age') >= 15552000, 'FAIL', 'HSTS_MAX_AGE must be at least 180 days.'],
            ['security.trusted_proxies', filled(config('shopbonus.security.trusted_proxies')), 'WARN', 'Set TRUSTED_PROXIES when TLS ends at a proxy/load balancer.'],
            ['admin.mfa_required', config('shopbonus.admin.mfa_required') === true, 'FAIL', 'ADMIN_MFA_REQUIRED must be true.'],
            ['queue.async', ! in_array(config('queue.default'), ['sync', 'null'], true), 'FAIL', 'QUEUE_CONNECTION must be a real queue with a running worker.'],
            ['cache.shared', ! in_array(config('cache.default'), ['array', 'null'], true), 'FAIL', 'CACHE_STORE must be shared (locks, rate limits).'],
            ['mail.delivering', ! in_array(config('mail.default'), ['log', 'array'], true), 'FAIL', 'MAIL_MAILER must deliver mail.'],
            ['payments.mollie_key', filled(config('shopbonus.payments.mollie.key')), 'FAIL', 'MOLLIE_API_KEY must be configured.'],
            ['payments.mollie_live', config('shopbonus.payments.mollie.allow_live') === true && str_starts_with((string) config('shopbonus.payments.mollie.key'), 'live_'), 'WARN', 'Live Mollie payments are off or the key is not a live key.'],
            ['answer24.signing_secret', filled(config('shopbonus.answer24.signing_secret')) && $https(config('shopbonus.answer24.base_url')), 'FAIL', 'ANSWER24_BASE_URL (https) and ANSWER24_SIGNING_SECRET must be configured.'],
            ['answer24.dispatch_enabled', config('shopbonus.answer24.dispatch_enabled') === true, 'WARN', 'Answer24 contact dispatch is off (requires the confirmed contract and lawful basis).'],
            ['backups.enabled', config('recovery.enabled') === true && filled(config('recovery.key')), 'FAIL', 'BACKUPS_ENABLED and BACKUP_ENCRYPTION_KEY must be set.'],
            ['backups.restore_target', filled(config('recovery.restore_connection')), 'WARN', 'Configure BACKUP_RESTORE_CONNECTION to verify restores.'],
            ['database.migrations_applied', $this->pendingMigrations($migrator) === 0, 'FAIL', 'Run php artisan migrate --force.'],
            ['manual.provider_live_runs', false, 'MANUAL', 'Live store, Mollie and Answer24 acceptance runs are recorded outside configuration.'],
            ['manual.legal_decisions', false, 'MANUAL', 'Open legal/business decisions (e.g. affiliate credit timing) need owner sign-off.'],
        ];
        $rows = array_map(fn ($c) => ['check' => $c[0], 'result' => $c[1] ? 'PASS' : $c[2], 'action' => $c[1] ? null : $c[3]], $checks);
        if ($this->option('json')) {
            $this->line(json_encode(['data' => $rows], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        } else {
            $this->table(['Check', 'Result', 'Action'], array_map(fn ($r) => [$r['check'], $r['result'], $r['action'] ?? ''], $rows));
        }

        return collect($rows)->contains('result', 'FAIL') ? self::FAILURE : self::SUCCESS;
    }

    private function pendingMigrations(Migrator $migrator): int
    {
        try {
            if (! $migrator->repositoryExists()) {
                return PHP_INT_MAX;
            }
            $ran = $migrator->getRepository()->getRan();

            return count(array_diff(array_keys($migrator->getMigrationFiles([database_path('migrations')])), $ran));
        } catch (Throwable) {
            return PHP_INT_MAX;
        }
    }
}
