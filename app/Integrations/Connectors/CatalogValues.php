<?php

namespace App\Integrations\Connectors;

use App\Support\Money;
use InvalidArgumentException;

/** Shared defensive value readers for native catalog mappers. */
trait CatalogValues
{
    private const DECIMAL = '/\A[0-9]{1,12}(?:\.[0-9]{1,6})?\z/D';

    private static function text(mixed $v, int $max): ?string
    {
        if (! is_string($v)) {
            return null;
        }
        $t = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($v), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        return $t === '' ? null : mb_substr($t, 0, $max);
    }

    private static function url(mixed $v): ?string
    {
        if (! is_string($v) || strlen($v) > 2048 || filter_var($v, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return in_array(strtolower((string) parse_url($v, PHP_URL_SCHEME)), ['http', 'https'], true) && parse_url($v, PHP_URL_USER) === null ? $v : null;
    }

    private static function minor(mixed $v, string $currency): ?int
    {
        if (! is_string($v) || ! preg_match(self::DECIMAL, $v)) {
            return null;
        }
        try {
            return Money::toMinor($v, $currency);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private static function positiveMinor(mixed $v, string $currency): ?int
    {
        $m = self::minor($v, $currency);

        return $m === null || $m === 0 ? null : $m;
    }

    private static function count(mixed $v): ?int
    {
        return is_int($v) && $v >= 0 && $v < 4_000_000_000 ? $v : null;
    }
}
