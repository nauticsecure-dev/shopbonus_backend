<?php

namespace App\Integrations;

use App\Support\Money;

/** Bounded source snapshots only. Eligibility is determined by a separate approved rule, never inferred here. */
final class LineItems
{
    /** @return list<array<string,mixed>>|null */
    public static function woo(mixed $rows, ?string $currency): ?array
    {
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) > 100 || ! Money::isCurrency($currency)) {
            return null;
        }
        $result = [];
        $seen = [];
        foreach ($rows as $r) {
            if (! is_array($r) || ! self::id($r['id'] ?? null) || isset($seen[(string) $r['id']]) || ! is_int($r['quantity'] ?? null) || $r['quantity'] < 0 || ! is_string($r['total'] ?? null)) {
                return null;
            }
            try {
                $minor = Money::toMinor($r['total'], $currency);
            } catch (\InvalidArgumentException) {
                return null;
            }
            if ($minor < 0) {
                return null;
            }
            $seen[(string) $r['id']] = true;
            $result[] = ['id' => (string) $r['id'], 'product_id' => self::id($r['product_id'] ?? null) ? (string) $r['product_id'] : null, 'sku' => self::sku($r['sku'] ?? null), 'quantity' => $r['quantity'], 'amount_minor' => $minor, 'amount_basis' => 'discounted_ex_tax', 'currency' => $currency, 'eligible_amount_minor' => null];
        }

        return $result;
    }

    /** @return list<array<string,mixed>>|null */
    public static function shopify(mixed $connection, ?string $currency): ?array
    {
        if (! is_array($connection) || ($connection['pageInfo']['hasNextPage'] ?? null) !== false || ! is_array($connection['nodes'] ?? null) || ! array_is_list($connection['nodes']) || count($connection['nodes']) > 100 || ! Money::isCurrency($currency)) {
            return null;
        }
        $out = [];
        $seen = [];
        foreach ($connection['nodes'] as $r) {
            $id = $r['id'] ?? null;
            $money = $r['discountedTotalSet']['shopMoney'] ?? [];
            if (! is_string($id) || ! preg_match('~\Agid://shopify/LineItem/[1-9][0-9]{0,18}\z~D', $id) || isset($seen[$id]) || ! is_int($r['quantity'] ?? null) || $r['quantity'] < 0 || ($money['currencyCode'] ?? null) !== $currency || ! is_string($money['amount'] ?? null)) {
                return null;
            }
            try {
                $minor = Money::toMinor($money['amount'], $currency);
            } catch (\InvalidArgumentException) {
                return null;
            }
            if ($minor < 0) {
                return null;
            }
            $seen[$id] = true;
            $product = $r['product']['id'] ?? null;
            $out[] = ['id' => $id, 'product_id' => is_string($product) && preg_match('~\Agid://shopify/Product/[1-9][0-9]{0,18}\z~D', $product) ? $product : null, 'sku' => self::sku($r['sku'] ?? null), 'quantity' => $r['quantity'], 'amount_minor' => $minor, 'amount_basis' => 'provider_discounted_total_with_code_discounts', 'currency' => $currency, 'eligible_amount_minor' => null];
        }

        return $out;
    }

    private static function id(mixed $v): bool
    {
        return (is_int($v) || is_string($v)) && preg_match('/\A[1-9][0-9]{0,18}\z/D', (string) $v) === 1;
    }

    private static function sku(mixed $v): ?string
    {
        return is_string($v) && mb_strlen($v) <= 255 && ! preg_match('/[\x00-\x1f\x7f]/', $v) ? $v : null;
    }
}
