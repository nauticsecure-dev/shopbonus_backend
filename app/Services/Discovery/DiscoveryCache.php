<?php

namespace App\Services\Discovery;

use Closure;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Cache for the public discovery read model (Trello #18 "Cache published
 * webshop/offer lists with correct invalidation"). Every entry key carries a
 * version; changing a public profile, an offer or a webshop's status bumps
 * the version, so stale lists are never served after such a change. Stock
 * (codes left) only changes through the TTL, and every protected action
 * re-checks availability itself.
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

        return Cache::remember($key, (int) config('shopbonus.discovery.cache_ttl_seconds', 60), $compute);
    }

    public static function flush(): void
    {
        try {
            Cache::add(self::VERSION_KEY, 1);
            Cache::increment(self::VERSION_KEY);
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
