<?php

namespace App\Services\Health;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Throwable;

/**
 * Probes the infrastructure the API depends on. Failures are logged with the
 * exception class only; results are a plain ok/fail map safe to return.
 */
class HealthChecker
{
    public const OK = 'ok';

    public const FAIL = 'fail';

    /**
     * @return array<string, string> component => ok|fail
     */
    public function run(): array
    {
        $checks = [
            'database' => fn () => DB::connection()->select('select 1'),
            'cache' => function () {
                $key = 'health:'.Str::random(12);
                Cache::put($key, '1', 10);
                $ok = Cache::get($key) === '1';
                Cache::forget($key);

                return $ok;
            },
        ];

        if ($this->usesRedis()) {
            $checks['redis'] = fn () => Redis::connection()->ping();
        }

        return array_map(fn (callable $check) => $this->probe($check), $checks);
    }

    private function probe(callable $check): string
    {
        try {
            return $check() === false ? self::FAIL : self::OK;
        } catch (Throwable $e) {
            Log::warning('Health check failed', ['exception_class' => $e::class]);

            return self::FAIL;
        }
    }

    private function usesRedis(): bool
    {
        $cacheStore = config('cache.default');
        $queue = config('queue.default');

        return config("cache.stores.{$cacheStore}.driver") === 'redis'
            || config("queue.connections.{$queue}.driver") === 'redis';
    }
}
