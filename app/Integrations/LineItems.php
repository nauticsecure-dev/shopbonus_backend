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

    /** Native flat order snapshots; parent relationships are retained, never summed here.
     * @return list<array<string,mixed>>|null
     */
    public static function magento(mixed $rows, ?string $currency): ?array
    {
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) > 100 || ! Money::isCurrency($currency)) {
            return null;
        }
        $out = $seen = [];
        foreach ($rows as $r) {
            if (! is_array($r) || ! self::id($r['item_id'] ?? null) || isset($seen[(string) $r['item_id']])) {
                return null;
            }
            $quantity = $r['qty_ordered'] ?? null;
            // Fractional quantities require a separate canonical quantity contract.
            if ((! is_int($quantity) && ! is_string($quantity)) || ! preg_match('/\A[0-9]{1,7}(?:\.0+)?\z/D', (string) $quantity) || (int) $quantity > 1000000
                || (! is_string($r['row_total'] ?? null) && ! is_int($r['row_total'] ?? null))) {
                return null;
            }
            try {
                $amount = Money::toMinor($r['row_total'], $currency);
            } catch (\InvalidArgumentException) {
                return null;
            }
            if ($amount < 0) {
                return null;
            }
            $id = (string) $r['item_id'];
            $seen[$id] = true;
            $out[] = ['id' => $id, 'parent_id' => self::id($r['parent_item_id'] ?? null) ? (string) $r['parent_item_id'] : null,
                'product_id' => self::id($r['product_id'] ?? null) ? (string) $r['product_id'] : null, 'sku' => self::sku($r['sku'] ?? null),
                'quantity' => (int) $quantity, 'amount_minor' => $amount, 'amount_basis' => 'provider_row_total_ex_tax_before_discount',
                'currency' => $currency, 'eligible_amount_minor' => null];
        }

        return $out;
    }

    /** @return list<array<string,mixed>>|null */
    public static function shopware(mixed $rows, ?string $currency, ?string $taxStatus): ?array
    {
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) > 100 || ! Money::isCurrency($currency)) {
            return null;
        }
        $out = $seen = [];
        foreach ($rows as $r) {
            $id = is_array($r) ? ($r['id'] ?? null) : null;
            if (! is_string($id) || ! preg_match('/\A[a-f0-9]{32}\z/D', $id) || isset($seen[$id]) || ! is_int($r['quantity'] ?? null) || $r['quantity'] < 0
                || (! is_string($r['totalPrice'] ?? null) && ! is_int($r['totalPrice'] ?? null))) {
                return null;
            }
            try {
                $amount = Money::toMinor($r['totalPrice'], $currency);
            } catch (\InvalidArgumentException) {
                return null;
            }
            $seen[$id] = true;
            $out[] = ['id' => $id, 'parent_id' => self::sku($r['parentId'] ?? null), 'product_id' => self::sku($r['productId'] ?? null),
                'sku' => self::sku($r['payload']['productNumber'] ?? null), 'quantity' => $r['quantity'], 'amount_minor' => $amount,
                'amount_basis' => match ($taxStatus) {
                    'gross' => 'provider_total_inc_tax', 'net', 'tax-free' => 'provider_total_ex_tax', default => 'provider_total_tax_unknown'
                },
                'currency' => $currency, 'eligible_amount_minor' => null];
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
