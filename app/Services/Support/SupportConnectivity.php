<?php

namespace App\Services\Support;

use Illuminate\Support\Facades\Cache;

/** Only a received nonce is delivery evidence; publishing alone is insufficient. */
final class SupportConnectivity
{
    private const KEY = 'support-ai:reverb-round-trip';

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

        return $this->evidence();
    }

    /** @return array<string, mixed> */
    public function evidence(): array
    {
        $result = Cache::get(self::KEY);
        $valid = is_array($result) && ($result['configuration'] ?? '') === $this->fingerprint();
        $status = config('broadcasting.default') !== 'reverb' ? 'not_configured'
            : (! $valid ? 'not_measured' : (strtotime($result['checked_at']) < now()->subSeconds(90)->timestamp ? 'stale' : $result['status']));

        return ['reverb_live_probe' => $status,
            'reverb_probe_checked_at' => $valid ? $result['checked_at'] : null,
            'reverb_probe_latency_ms' => $valid ? $result['latency_ms'] : null,
            'broadcast_evidence' => $status === 'connected' ? 'synthetic_round_trip_received' : 'dispatch_attempt_only',
            'reverb_probe_scope' => 'server_to_reverb_private_channel_round_trip_not_browser_or_worker'];
    }

    private function fingerprint(): string
    {
        return hash('sha256', serialize([config('broadcasting.default'), config('broadcasting.connections.reverb'),
            config('support_ai.reverb_probe_origin') ?: config('app.url')]));
    }
}
