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

    /**
     * Wix eCom Order `lineItems[]`: `totalPriceBeforeTax` is the line total after discounts, before tax.
     *
     * @return list<array<string,mixed>>|null
     */
    public static function wix(mixed $rows, ?string $currency): ?array
    {
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) > 100 || ! Money::isCurrency($currency)) {
            return null;
        }
        $out = [];
        $seen = [];
        foreach ($rows as $r) {
            $id = is_array($r) ? ($r['id'] ?? null) : null;
            $quantity = $r['quantity'] ?? null;
            if (! is_string($id) || ! preg_match('/\A[A-Za-z0-9-]{1,64}\z/D', $id) || isset($seen[$id]) || ! is_int($quantity) || $quantity < 0 || ! is_string($r['totalPriceBeforeTax']['amount'] ?? null)) {
                return null;
            }
            try {
                $minor = Money::toMinor($r['totalPriceBeforeTax']['amount'], $currency);
            } catch (\InvalidArgumentException) {
                return null;
            }
            if ($minor < 0) {
                return null;
            }
            $seen[$id] = true;
            $product = $r['catalogReference']['catalogItemId'] ?? null;
            $out[] = ['id' => $id, 'product_id' => is_string($product) && preg_match('/\A[A-Za-z0-9-]{1,64}\z/D', $product) ? $product : null, 'sku' => self::sku($r['physicalProperties']['sku'] ?? null),
                'quantity' => $quantity, 'amount_minor' => $minor, 'amount_basis' => 'discounted_ex_tax', 'currency' => $currency, 'eligible_amount_minor' => null];
        }

        return $out;
    }

    /** Flat product rows only; composites/promotions cannot become a partial snapshot.
     * @return list<array<string,mixed>>|null
     */
    public static function shopware(mixed $rows, ?string $currency, mixed $orderId): ?array
    {
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) > 100 || ! Money::isCurrency($currency) || ! self::uuid($orderId)) {
            return null;
        }
        $out = $seen = [];
        foreach ($rows as $r) {
            $id = is_array($r) ? ($r['id'] ?? null) : null;
            if (! self::uuid($id) || isset($seen[strtolower($id)]) || ($r['type'] ?? null) !== 'product'
                || ! array_key_exists('parentId', $r) || $r['parentId'] !== null || ! self::uuid($r['orderId'] ?? null)
                || strtolower($r['orderId']) !== strtolower($orderId) || ! is_int($r['quantity'] ?? null) || $r['quantity'] < 0
                || (! is_string($r['totalPrice'] ?? null) && ! is_int($r['totalPrice'] ?? null))) {
                return null;
            }
            try {
                $minor = Money::toMinor($r['totalPrice'], $currency);
            } catch (\InvalidArgumentException) {
                return null;
            }
            if ($minor < 0) {
                return null;
            }
            $seen[strtolower($id)] = true;
            $out[] = ['id' => strtolower($id), 'product_id' => self::uuid($r['productId'] ?? null) ? strtolower($r['productId']) : null,
                'sku' => null, 'quantity' => $r['quantity'], 'amount_minor' => $minor,
                'amount_basis' => 'provider_calculated_line_total', 'currency' => $currency, 'eligible_amount_minor' => null];
        }

        return $out;
    }

    /** Magento2.4 simple root items only. Report provider row totals; do not infer discount/refund eligibility.
     * @return list<array<string,mixed>>|null
     */
    public static function magento(mixed $rows, ?string $currency, mixed $orderId): ?array
    {
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) > 100 || ! Money::isCurrency($currency) || ! self::id($orderId)) {
            return null;
        }
        $out = $seen = [];
        foreach ($rows as $r) {
            $id = is_array($r) ? ($r['item_id'] ?? null) : null;
            $qty = is_array($r) ? self::wholeQuantity($r['qty_ordered'] ?? null) : null;
            if (! self::id($id) || isset($seen[(string) $id]) || ($r['product_type'] ?? null) !== 'simple'
                || ($r['parent_item_id'] ?? null) !== null || ($r['parent_item'] ?? null) !== null
                || ! self::id($r['order_id'] ?? null) || (string) $r['order_id'] !== (string) $orderId || $qty === null
                || (! is_string($r['row_total_incl_tax'] ?? null) && ! is_int($r['row_total_incl_tax'] ?? null))) {
                return null;
            }
            try {
                $minor = Money::toMinor($r['row_total_incl_tax'], $currency);
            } catch (\InvalidArgumentException) {
                return null;
            }
            if ($minor < 0) {
                return null;
            }
            $seen[(string) $id] = true;
            $out[] = ['id' => (string) $id, 'product_id' => self::id($r['product_id'] ?? null) ? (string) $r['product_id'] : null,
                'sku' => self::sku($r['sku'] ?? null), 'quantity' => $qty, 'amount_minor' => $minor,
                'amount_basis' => 'provider_row_total_including_tax', 'currency' => $currency, 'eligible_amount_minor' => null];
        }

        return $out;
    }

    private static function uuid(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[0-9a-f]{32}\z/Di', $value) === 1;
    }

    private static function wholeQuantity(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 && $value <= 999999999 ? $value : null;
        }

        return is_string($value) && preg_match('/\A(?:0|[1-9][0-9]{0,8})(?:\.0{1,8})?\z/D', $value) === 1 ? (int) $value : null;
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
