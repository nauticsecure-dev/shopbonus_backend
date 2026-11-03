<?php

namespace App\Services\Discovery;

use App\Models\OfferVersion;
use Carbon\Carbon;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Cache for the public discovery read model (Trello #18 "Cache published
 * webshop/offer lists with correct invalidation"). Every entry key carries a
 * version; changing a public profile, offer, webshop status or voucher-code
 * inventory bumps the version so stale lists are not served. Time-based offer
 * expiry is bounded by cache_ttl_seconds (default 30). Checkout/claim paths
 * still re-check obtainability before allocating codes.
 */
class DiscoveryCache
{
    private const VERSION_KEY = 'discovery:version';

    /**
     * @template T
     *
     * @param  array<string, mixed>  $params
     * @param  Closure(): T  $compute
     * @return T
     */
    public static function remember(string $name, array $params, Closure $compute): mixed
    {
        ksort($params);
        $key = 'discovery:'.self::version().':'.$name.':'.md5((string) json_encode($params));

        $cached = Cache::get($key);
        if ($cached !== null) {
            return $cached;
        }
        $computedAt = now();
        $value = $compute();
        // At most 30 seconds stale; scheduled starts/ends expire at the boundary.
        $expires = $computedAt->copy()->addSeconds(max(1, min(30, (int) config('shopbonus.discovery.cache_ttl_seconds', 30))));
        foreach (['starts_at', 'ends_at'] as $column) {
            $boundary = OfferVersion::where($column, '>', $computedAt)->min($column);
            if ($boundary !== null && $expires->greaterThan($boundary)) {
                $expires = Carbon::parse($boundary);
            }
        }
        Cache::put($key, $value, $expires);

        return $value;
    }

    public static function flush(): void
    {
        try {
            Cache::add(self::VERSION_KEY, 1);
            Cache::increment(self::VERSION_KEY);
            // A concurrent reader may have cached pre-commit data after the first bump.
            if (DB::transactionLevel() > 0) {
                DB::afterCommit(function () {
                    try {
                        Cache::increment(self::VERSION_KEY);
                    } catch (Throwable $e) {
                        report($e);
                    }
                });
            }
        } catch (Throwable $e) {
            // A cache outage must not break the merchant's save; entries expire by TTL.
            report($e);
        }
    }

    private static function version(): int
    {
        return (int) Cache::get(self::VERSION_KEY, 1);
    }
}
