<?php

namespace App\Services\Support;

use App\Events\SupportBrowserProbe;
use App\Exceptions\ApiException;
use App\Jobs\ProbeSupportWorker;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Only a received nonce is delivery evidence; publishing alone is insufficient.
 * Three separate proofs: server-to-Reverb round trip, a queue worker completing a
 * synthetic job, and a real browser echoing a nonce it received over Reverb.
 */
final class SupportConnectivity
{
    private const KEY = 'support-ai:reverb-round-trip';

    private const WORKER_SENT = 'support-ai:worker-probe-sent';

    private const WORKER_DONE = 'support-ai:worker-probe-done';

    private const BROWSER_LAST = 'support-ai:browser-probe-last';

    /** A worker probe dispatched this long ago without completing means no worker took it. */
    private const WORKER_GRACE_SECONDS = 90;

    private const BROWSER_TTL_SECONDS = 60;

    public function __construct(private ReverbRoundTrip $transport) {}

    /** @return array<string, mixed> */
    public function run(): array
    {
        $started = hrtime(true);
        $status = 'not_configured';
        if (config('broadcasting.default') === 'reverb') {
            try {
                $this->transport->check();
                $status = 'connected';
            } catch (\Throwable) {
                // Never persist exception text: connection errors can include credentials.
                $status = 'failed';
            }
        }
        $result = ['status' => $status, 'checked_at' => now()->toIso8601String(),
            'latency_ms' => (int) round((hrtime(true) - $started) / 1_000_000), 'configuration' => $this->fingerprint()];
        Cache::put(self::KEY, $result, now()->addDay());
        $this->dispatchWorkerProbe();

        return $this->evidence();
    }

    /** Same connection and queue as ProcessSupportMessage; a sync driver proves no worker. */
    private function dispatchWorkerProbe(): void
    {
        if (config('queue.default') === 'sync') {
            return;
        }
        $nonce = bin2hex(random_bytes(16));
        $at = self::clock();
        Cache::put(self::WORKER_SENT, ['nonce' => $nonce, 'dispatched_at' => $at], now()->addDay());
        ProbeSupportWorker::dispatch($nonce, $at);
    }

    /** Called by the worker job itself. */
    public function workerProcessed(string $nonce, float $dispatchedAt): void
    {
        Cache::put(self::WORKER_DONE, ['nonce' => $nonce, 'processed_at' => self::clock(), 'latency_ms' => (int) round((self::clock() - $dispatchedAt) * 1000)], now()->addDay());
    }

    /** Sends a one-time nonce to the admin's own private channel; returns the nonce for the client to expect. */
    public function startBrowserProbe(User $admin): string
    {
        $nonce = bin2hex(random_bytes(16));
        Cache::put('support-ai:browser-probe:'.$nonce, ['user_id' => $admin->id, 'sent_at' => self::clock()], now()->addSeconds(self::BROWSER_TTL_SECONDS));
        try {
            broadcast(new SupportBrowserProbe($admin->public_id, $nonce));
        } catch (\Throwable) {
            Cache::forget('support-ai:browser-probe:'.$nonce);

            throw new ApiException('The realtime broadcaster could not send the probe.', 503, 'broadcast_unavailable');
        }

        return $nonce;
    }

    /** Single use, same admin, within the window: proof the browser received it over Reverb. */
    public function acknowledgeBrowserProbe(User $admin, string $nonce): void
    {
        $key = 'support-ai:browser-probe:'.$nonce;
        $sent = preg_match('/\A[a-f0-9]{32}\z/D', $nonce) === 1 ? Cache::get($key) : null;
        // Ownership first: another admin's attempt must not consume this admin's nonce.
        if (! is_array($sent) || $sent['user_id'] !== $admin->id || Cache::pull($key) === null) {
            throw new ApiException('This probe is unknown, expired or already acknowledged.', 404, 'probe_not_found');
        }
        Cache::put(self::BROWSER_LAST, ['checked_at' => now()->toIso8601String(), 'latency_ms' => (int) round((self::clock() - $sent['sent_at']) * 1000)], now()->addDay());
    }

    /** @return array<string, mixed> */
    public function evidence(): array
    {
        $result = Cache::get(self::KEY);
        $valid = is_array($result) && ($result['configuration'] ?? '') === $this->fingerprint();
        $status = config('broadcasting.default') !== 'reverb' ? 'not_configured'
            : (! $valid ? 'not_measured' : (strtotime($result['checked_at']) < now()->subSeconds(90)->timestamp ? 'stale' : $result['status']));

        $done = Cache::get(self::WORKER_DONE);
        $sent = Cache::get(self::WORKER_SENT);
        $worker = match (true) {
            config('queue.default') === 'sync' => 'sync_driver_no_worker',
            is_array($done) && $done['processed_at'] >= self::clock() - 2 * self::WORKER_GRACE_SECONDS => 'processed',
            ! is_array($sent) => 'not_measured',
            $sent['dispatched_at'] >= self::clock() - self::WORKER_GRACE_SECONDS => 'pending',
            default => 'not_processed',
        };
        $browser = Cache::get(self::BROWSER_LAST);
        $browserStatus = ! is_array($browser) ? 'not_measured' : (strtotime($browser['checked_at']) < now()->subMinutes(15)->timestamp ? 'stale' : 'acknowledged');

        return ['reverb_live_probe' => $status,
            'reverb_probe_checked_at' => $valid ? $result['checked_at'] : null,
            'reverb_probe_latency_ms' => $valid ? $result['latency_ms'] : null,
            'broadcast_evidence' => $status === 'connected' ? 'synthetic_round_trip_received' : 'dispatch_attempt_only',
            'reverb_probe_scope' => 'server_to_reverb_private_channel_round_trip',
            'worker_probe' => $worker,
            'worker_probe_latency_ms' => $worker === 'processed' ? $done['latency_ms'] : null,
            'browser_probe' => $browserStatus,
            'browser_probe_checked_at' => is_array($browser) ? $browser['checked_at'] : null,
            'browser_probe_latency_ms' => is_array($browser) ? $browser['latency_ms'] : null,
            'browser_probe_scope' => 'requesting_admin_browser_only_not_every_customer_client'];
    }

    /** Framework clock (seconds with microseconds), so scheduling and tests share one time source. */
    private static function clock(): float
    {
        return (float) now()->format('U.u');
    }

    private function fingerprint(): string
    {
        return hash('sha256', serialize([config('broadcasting.default'), config('broadcasting.connections.reverb'),
            config('support_ai.reverb_probe_origin') ?: config('app.url')]));
    }
}
