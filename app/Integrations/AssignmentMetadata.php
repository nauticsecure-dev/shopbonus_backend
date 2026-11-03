<?php

namespace App\Integrations;

/** ShopBonus-owned bridge fields only. Missing/ambiguous values never imply an assignment or payment. */
final class AssignmentMetadata
{
    public const KEY = 'shopbonus_assignment_token';

    public static function token(mixed $value): ?string
    {
        return is_string($value) && preg_match('/\Abx_[a-f0-9]{64}\z/D', $value) ? $value : null;
    }

    public static function fromMap(mixed $map, string $key = self::KEY): ?string
    {
        return is_array($map) ? self::token($map[$key] ?? null) : null;
    }

    public static function fromRows(mixed $rows, string $keyField, string $key = self::KEY): ?string
    {
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) > 100) {
            return null;
        }
        $matches = array_values(array_filter($rows, fn ($row) => is_array($row) && ($row[$keyField] ?? null) === $key));

        return count($matches) === 1 ? self::token($matches[0]['value'] ?? null) : null;
    }

    /** Ecwid exposes the same custom checkout value as a map and optional descriptive rows. */
    public static function ecwid(mixed $order): ?string
    {
        if (! is_array($order)) {
            return null;
        }
        $map = $order['extraFields'] ?? [];
        $rows = $order['orderExtraFields'] ?? [];
        if (! is_array($map) || ! is_array($rows) || ! array_is_list($rows) || count($rows) > 100) {
            return null;
        }
        $matches = array_values(array_filter($rows, fn ($row) => is_array($row) && ($row['id'] ?? null) === self::KEY));
        if (count($matches) > 1) {
            return null;
        }
        $values = [];
        if (array_key_exists(self::KEY, $map)) {
            $values[] = self::token($map[self::KEY]);
        }
        if ($matches !== []) {
            $values[] = self::token($matches[0]['value'] ?? null);
        }

        return $values !== [] && ! in_array(null, $values, true) && count(array_unique($values)) === 1 ? $values[0] : null;
    }

    public static function bigCommerce(mixed $rows, mixed $orderId): ?string
    {
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) > 2 || (! is_int($orderId) && ! is_string($orderId))) {
            return null;
        }
        $matches = array_values(array_filter($rows, fn ($row) => is_array($row) && ($row['namespace'] ?? null) === 'shopbonus' && ($row['key'] ?? null) === 'assignment_token'));
        $row = count($matches) === 1 ? $matches[0] : [];
        $sourceId = $row['resource_id'] ?? null;

        return ($row['resource_type'] ?? null) === 'order' && (is_int($sourceId) || is_string($sourceId)) && (string) $sourceId === (string) $orderId
            ? self::token($row['value'] ?? null) : null;
    }
}
