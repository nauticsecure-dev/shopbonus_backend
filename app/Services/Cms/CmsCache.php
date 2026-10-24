<?php

namespace App\Services\Cms;

use Closure;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Versioned cache for published CMS content: every publish/restore/archive
 * bumps the version, so public reads never serve stale content.
 */
class CmsCache
{
    private const KEY = 'cms:version';

    /**
     * @template T
     *
     * @param  Closure(): T  $compute
     * @return T
     */
    public static function remember(string $name, Closure $compute): mixed
    {
        return Cache::remember('cms:'.(int) Cache::get(self::KEY, 1).':'.$name, 300, $compute);
    }

    public static function flush(): void
    {
        try {
            Cache::add(self::KEY, 1);
            Cache::increment(self::KEY);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
