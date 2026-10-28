<?php

namespace App\Services\Reliability;

use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Opt-in, fixed provider labels and numeric metadata only; never URLs or payloads. */
class ProviderObservations
{
    public function record(ResponseReceived|ConnectionFailed $event): void
    {
        if (! config('reliability.enabled')) {
            return;
        }
        $host = strtolower((string) parse_url($event->request->url(), PHP_URL_HOST));
        $hosts = ['openai' => 'api.openai.com', 'google_places' => 'places.googleapis.com',
            'mollie' => parse_url((string) config('shopbonus.payments.mollie.base_url'), PHP_URL_HOST),
            'daisycon' => parse_url((string) config('shopbonus.affiliate.networks.daisycon.base_url'), PHP_URL_HOST),
            'answer24' => parse_url((string) config('shopbonus.answer24.base_url'), PHP_URL_HOST)];
        $provider = array_search($host, $hosts, true);
        if ($provider === false || $host === '') {
            return;
        }
        try {
            $duration = $event instanceof ResponseReceived ? $event->response->transferStats?->getTransferTime() : null;
            DB::table('operational_observations')->insert(['component' => 'provider_'.$provider, 'observed_at' => now(),
                'metrics' => json_encode(['status' => $event instanceof ResponseReceived ? $event->response->status() : 0,
                    'duration_ms' => $duration === null ? null : max(0, min(3600000, (int) round($duration * 1000)))], JSON_THROW_ON_ERROR)]);
        } catch (Throwable $e) {
            Log::warning('Provider observation failed', ['exception_class' => $e::class]);
        }
    }

    /** @return array<string,mixed> */
    public function report(): array
    {
        if (! config('reliability.enabled')) {
            return ['http_status' => 'UNKNOWN', 'http_requests' => 0, 'http_failures' => 0, 'http_sample_limit_exceeded' => false, 'by_provider' => []];
        }
        $rows = DB::table('operational_observations')->where('component', 'like', 'provider_%')->where('observed_at', '>=', now()->subMinutes(5))->latest('id')->limit(1001)->get();
        $truncated = $rows->count() > 1000;
        $rows = $rows->take(1000);
        $failed = $rows->filter(function ($row) {
            $status = json_decode($row->metrics, true)['status'];

            return $status === 0 || $status >= 400;
        })->count();
        $providers = [];
        foreach ($rows->groupBy('component') as $label => $samples) {
            $metrics = $samples->map(fn ($sample) => json_decode($sample->metrics, true));
            $durations = $metrics->pluck('duration_ms')->filter(fn ($duration) => $duration !== null);
            $providers[] = ['provider' => substr($label, 9), 'requests' => $metrics->count(),
                'failures' => $metrics->filter(fn ($sample) => $sample['status'] === 0 || $sample['status'] >= 400)->count(),
                'average_observed_ms' => $durations->isEmpty() ? null : round($durations->avg(), 2)];
        }
        $threshold = max(1, min(100, (int) config('reliability.provider_failure_threshold', 3)));

        return ['http_status' => $failed >= $threshold ? 'CRITICAL' : ($failed > 0 ? 'WARNING' : 'UNKNOWN'),
            'http_requests' => $rows->count(), 'http_failures' => $failed, 'http_failure_threshold' => $threshold,
            'http_sample_limit_exceeded' => $truncated, 'by_provider' => $providers,
            'definition' => 'Latest1000 allowlisted Laravel HTTP observations/5minutes; SOAP and independent uptime remain unknown. Status0 is a connection failure.'];
    }
}
