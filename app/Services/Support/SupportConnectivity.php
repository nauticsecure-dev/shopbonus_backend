<?php

namespace App\Services\Support;

use App\Events\SupportBrowserProbe;
use App\Exceptions\ApiException;
use App\Jobs\ProbeSupportWorker;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

final class SupportConnectivity
{
    private const KEY = 'support-ai:reverb-round-trip';

    private const WORKER_SENT = 'support-ai:worker-probe-sent';

    private const WORKER_DONE = 'support-ai:worker-probe-done';

    private const WORKER_GRACE_SECONDS = 90;

    private const BROWSER_TTL_SECONDS = 60;

    public function __construct(private ReverbRoundTrip $transport) {}

    /** @return array<string,mixed> */
    public function run(): array
    {
        $started = hrtime(true);
        $status = 'not_configured';
        if (config('broadcasting.default') === 'reverb') {
            try {
                $this->transport->check();
                $status = 'connected';
            } catch (\Throwable) {
                $status = 'failed';
            }
        }
        Cache::put(self::KEY, ['status' => $status, 'checked_at' => now()->toIso8601String(), 'latency_ms' => (int) round((hrtime(true) - $started) / 1_000_000), 'configuration' => $this->fingerprint()], now()->addDay());
        $this->dispatchWorkerProbe();

        return $this->evidence();
    }

    /** Same default connection/queue as ProcessSupportMessage; dispatched explicitly to freeze that target. */
    private function dispatchWorkerProbe(): void
    {
        $connection = (string) config('queue.default');
        if (in_array(config('queue.connections.'.$connection.'.driver'), ['sync', 'null'], true)) {
            return;
        }
        $queue = (string) config('queue.connections.'.$connection.'.queue', 'default');
        $nonce = bin2hex(random_bytes(16));
        $at = self::clock();
        $configuration = $this->workerFingerprint();
        Cache::lock('support-ai:worker-probe-lock', 5)->block(1, function () use ($nonce, $at, $configuration, $connection, $queue) {
            Cache::put(self::WORKER_SENT, ['nonce' => $nonce, 'dispatched_at' => $at, 'configuration' => $configuration, 'connection' => $connection, 'queue' => $queue], now()->addDay());
        });
        ProbeSupportWorker::dispatch($nonce, $at, $configuration)->onConnection($connection)->onQueue($queue);
    }

    /** Only the actual queued job context supplies the connection/queue; manual handle calls do not count. */
    public function workerProcessed(string $nonce, float $dispatchedAt, string $configuration, string $connection, string $queue): void
    {
        Cache::lock('support-ai:worker-probe-lock', 5)->block(1, function () use ($nonce, $dispatchedAt, $configuration, $connection, $queue) {
            $sent = Cache::get(self::WORKER_SENT);
            if (! is_array($sent) || ! hash_equals($sent['nonce'], $nonce) || $sent['dispatched_at'] !== $dispatchedAt || $sent['configuration'] !== $configuration || $configuration !== $this->workerFingerprint()
                || $sent['connection'] !== $connection || $sent['queue'] !== $queue || $dispatchedAt > self::clock() || self::clock() - $dispatchedAt > 2 * self::WORKER_GRACE_SECONDS) {
                return;
            }
            $existing = Cache::get(self::WORKER_DONE);
            if (is_array($existing) && ($existing['nonce'] ?? null) === $nonce) {
                return;
            }
            Cache::put(self::WORKER_DONE, $sent + ['processed_at' => self::clock(), 'latency_ms' => (int) round((self::clock() - $dispatchedAt) * 1000)], now()->addDay());
        });
    }

    /** HTTP returns only a public correlation ID. The independent challenge travels solely in the private event. */
    public function startBrowserProbe(User $admin): string
    {
        $this->authorize($admin);
        if (config('broadcasting.default') !== 'reverb') {
            throw new ApiException('The realtime broadcaster is not configured.', 503, 'broadcast_unavailable');
        }
        $id = bin2hex(random_bytes(16));
        $challenge = bin2hex(random_bytes(16));
        $this->browserLock($admin, function () use ($admin, $id, $challenge) {
            $sent = ['probe_id' => $id, 'user_id' => $admin->id, 'sent_at' => self::clock(), 'configuration' => $this->fingerprint(), 'status' => 'pending'];
            Cache::put('support-ai:browser-probe:'.$id, $sent + ['challenge_hash' => hash('sha256', $challenge)], now()->addSeconds(self::BROWSER_TTL_SECONDS));
            Cache::put($this->browserKey($admin), $sent, now()->addDay());
        });
        try {
            broadcast(new SupportBrowserProbe($admin->public_id, $id, $challenge));
        } catch (\Throwable) {
            $this->browserLock($admin, function () use ($admin, $id) {
                Cache::forget('support-ai:browser-probe:'.$id);
                $last = Cache::get($this->browserKey($admin));
                if (is_array($last) && $last['probe_id'] === $id) {
                    $last['status'] = 'failed';
                    Cache::put($this->browserKey($admin), $last, now()->addDay());
                }
            });
            throw new ApiException('The realtime broadcaster could not send the probe.', 503, 'broadcast_unavailable');
        }

        return $id;
    }

    public function acknowledgeBrowserProbe(User $admin, string $id, string $challenge): void
    {
        $this->authorize($admin);
        $this->browserLock($admin, function () use ($admin, $id, $challenge) {
            $key = 'support-ai:browser-probe:'.$id;
            $sent = preg_match('/\A[a-f0-9]{32}\z/D', $id) === 1 ? Cache::get($key) : null;
            $last = Cache::get($this->browserKey($admin));
            if (! is_array($sent) || ! is_array($last) || $sent['user_id'] !== $admin->id || $last['probe_id'] !== $id || $last['status'] !== 'pending' || $sent['configuration'] !== $this->fingerprint()
                || self::clock() - $sent['sent_at'] > self::BROWSER_TTL_SECONDS || $sent['sent_at'] > self::clock() || ! hash_equals($sent['challenge_hash'], hash('sha256', $challenge))) {
                throw new ApiException('This probe is unknown, expired or already acknowledged.', 404, 'probe_not_found');
            }
            Cache::forget($key);
            Cache::put($this->browserKey($admin), array_replace($last, ['status' => 'acknowledged', 'checked_at' => now()->toIso8601String(), 'latency_ms' => (int) round((self::clock() - $sent['sent_at']) * 1000)]), now()->addDay());
        });
    }

    private function authorize(User $admin): void
    {
        $current = User::find($admin->id);
        if (! $current?->isActive() || ! $current->isSuperAdmin()) {
            throw new ApiException('Current Super Admin access is required.', 403, 'forbidden');
        }
    }

    private function browserKey(User $admin): string
    {
        return 'support-ai:browser-probe-last:'.$admin->id;
    }

    /** @param callable():void $callback */
    private function browserLock(User $admin, callable $callback): void
    {
        try {
            Cache::lock('support-ai:browser-probe-lock:'.$admin->id, 5)->block(1, $callback);
        } catch (LockTimeoutException) {
            throw ApiException::conflict('Another probe operation is in progress.', 'probe_busy');
        }
    }

    /** @return array<string,mixed> */
    public function evidence(?User $admin = null): array
    {
        $result = Cache::get(self::KEY);
        $valid = is_array($result) && ($result['configuration'] ?? '') === $this->fingerprint();
        $status = config('broadcasting.default') !== 'reverb' ? 'not_configured' : (! $valid ? 'not_measured' : (strtotime($result['checked_at']) < now()->subSeconds(90)->timestamp ? 'stale' : $result['status']));
        $done = Cache::get(self::WORKER_DONE);
        $sent = Cache::get(self::WORKER_SENT);
        $sentValid = is_array($sent) && ($sent['configuration'] ?? '') === $this->workerFingerprint();
        $matched = $sentValid && is_array($done) && ($done['nonce'] ?? null) === $sent['nonce'] && ($done['dispatched_at'] ?? null) === $sent['dispatched_at'] && ($done['configuration'] ?? '') === $sent['configuration']
            && ($done['connection'] ?? null) === $sent['connection'] && ($done['queue'] ?? null) === $sent['queue']
            && $done['processed_at'] >= $sent['dispatched_at'] && $done['processed_at'] <= self::clock() && $sent['dispatched_at'] >= self::clock() - 2 * self::WORKER_GRACE_SECONDS;
        $worker = match (true) {
            config('queue.connections.'.config('queue.default').'.driver') === 'sync' => 'sync_driver_no_worker',
            config('queue.connections.'.config('queue.default').'.driver') === 'null' => 'disabled_driver_no_worker',
            ! $sentValid => 'not_measured', $matched => 'processed',
            $sent['dispatched_at'] >= self::clock() - self::WORKER_GRACE_SECONDS => 'pending',default => 'not_processed',
        };
        $browser = $admin === null ? null : Cache::get($this->browserKey($admin));
        $browserValid = is_array($browser) && ($browser['configuration'] ?? '') === $this->fingerprint();
        $browserStatus = ! $browserValid ? 'not_measured' : ($browser['status'] === 'acknowledged' ? (strtotime($browser['checked_at']) < now()->subMinutes(15)->timestamp ? 'stale' : 'acknowledged') :
            ($browser['status'] === 'failed' ? 'failed' : (self::clock() - $browser['sent_at'] > self::BROWSER_TTL_SECONDS ? 'expired' : 'pending')));

        return ['reverb_live_probe' => $status, 'reverb_probe_checked_at' => $valid ? $result['checked_at'] : null, 'reverb_probe_latency_ms' => $valid ? $result['latency_ms'] : null,
            'broadcast_evidence' => $status === 'connected' ? 'synthetic_round_trip_received' : 'dispatch_attempt_only', 'reverb_probe_scope' => 'server_to_reverb_private_channel_round_trip',
            'worker_probe' => $worker, 'worker_probe_latency_ms' => $worker === 'processed' ? $done['latency_ms'] : null, 'worker_probe_scope' => 'latest_nonce_exact_queue_configuration_and_running_job_context',
            'browser_probe' => $browserStatus, 'browser_probe_checked_at' => $browserValid ? ($browser['checked_at'] ?? null) : null, 'browser_probe_latency_ms' => $browserValid ? ($browser['latency_ms'] ?? null) : null,
            'browser_probe_scope' => 'requesting_admin_authenticated_client_receipt_not_browser_engine_or_every_customer'];
    }

    private static function clock(): float
    {
        return (float) now()->format('U.u');
    }

    private function workerFingerprint(): string
    {
        $connection = (string) config('queue.default');

        $queue = config('queue.connections.'.$connection);
        $driver = $queue['driver'] ?? null;
        $backend = null;
        if ($driver === 'database') {
            $name = $queue['connection'] ?? config('database.default');
            $backend = [$name, config('database.connections.'.$name)];
        } elseif ($driver === 'redis') {
            $name = $queue['connection'] ?? 'default';
            $backend = [$name, config('database.redis.'.$name), config('database.redis.options')];
        }

        return hash('sha256', serialize([$connection, $queue, $backend, config('cache.default'), config('cache.stores.'.config('cache.default')), config('cache.prefix')]));
    }

    private function fingerprint(): string
    {
        return hash('sha256', serialize([config('broadcasting.default'), config('broadcasting.connections.reverb'), config('support_ai.reverb_probe_origin') ?: config('app.url')]));
    }
}
