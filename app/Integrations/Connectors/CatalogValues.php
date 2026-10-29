<?php

namespace App\Integrations\Connectors;

use App\Support\Money;
use Carbon\CarbonImmutable;
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
        // The pinned reader keeps fractional JSON numbers as exact strings; whole numbers stay ints.
        $v = is_int($v) && $v >= 0 ? (string) $v : $v;
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

    /**
     * Every snapshot column, so a mapper only states what its platform documents.
     *
     * @param  array<string, mixed>  $v
     * @return array<string, mixed>
     */
    private static function productRow(array $v): array
    {
        return $v + ['sku' => null, 'description' => null, 'url' => null, 'image_url' => null, 'categories' => null, 'price_minor' => null,
            'regular_price_minor' => null, 'sale_price_minor' => null, 'product_type' => null, 'status' => null, 'visibility' => null,
            'stock_status' => null, 'stock_quantity' => null, 'provider_updated_at' => null];
    }

    /**
     * @param  array<string, mixed>  $v
     * @return array<string, mixed>
     */
    private static function couponRow(array $v): array
    {
        return $v + ['amount_minor' => null, 'percent_bps' => null, 'minimum_spend_minor' => null, 'maximum_spend_minor' => null, 'usage_limit' => null,
            'usage_limit_per_customer' => null, 'usage_count' => 0, 'stackable' => false, 'exclude_sale_items' => false, 'free_shipping' => false,
            'customer_restricted' => false, 'scope' => null, 'status' => null, 'expires_at' => null, 'provider_updated_at' => null];
    }

    private static function enum(mixed $v): ?string
    {
        return is_string($v) && preg_match('/\A[a-z_-]{1,32}\z/D', $v) ? $v : null;
    }

    /** Native numeric or GUID-like identifier, as a string. */
    private static function nativeId(mixed $v): ?string
    {
        return (is_int($v) && $v > 0) || (is_string($v) && preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D', $v)) ? (string) $v : null;
    }

    /** @return list<string> */
    private static function idList(mixed $v, int $max = 500): array
    {
        $out = [];
        foreach (is_array($v) ? $v : [] as $id) {
            if (($id = self::nativeId($id)) !== null) {
                $out[] = $id;
            }
        }

        return array_slice(array_values(array_unique($out)), 0, $max);
    }

    /**
     * Percent as an exact decimal string, plus basis points when exactly representable.
     *
     * @return array{0: string, 1: int|null}|null
     */
    private static function percentString(mixed $v): ?array
    {
        $v = is_int($v) ? (string) $v : $v;
        if (! is_string($v) || ! preg_match('/\A([0-9]{1,3})(?:\.([0-9]{1,6}))?\z/D', $v, $m) || (float) $v <= 0 || (float) $v > 100) {
            return null;
        }
        $fraction = rtrim($m[2] ?? '', '0');
        $text = ltrim($m[1], '0') === '' ? '0' : ltrim($m[1], '0');

        return [$fraction === '' ? $text : $text.'.'.$fraction, strlen($fraction) <= 2 ? (int) $m[1] * 100 + (int) str_pad($fraction, 2, '0') : null];
    }

    private static function iso(mixed $v): ?CarbonImmutable
    {
        if (! is_string($v) || ! preg_match('/\A\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?(?:Z| ?[+-]\d{2}:?\d{2})?\z/D', $v)) {
            return null;
        }
        try {
            // Values without an offset are documented as UTC by the providers that send them.
            return CarbonImmutable::parse($v, 'UTC')->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function epochMs(mixed $v): ?CarbonImmutable
    {
        $v = is_int($v) ? (string) $v : $v;

        return is_string($v) && preg_match('/\A[1-9][0-9]{12}\z/D', $v) ? CarbonImmutable::createFromTimestampMs((int) $v, 'UTC') : null;
    }
}
