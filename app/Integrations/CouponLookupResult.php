<?php

namespace App\Integrations;

use App\Exceptions\ApiException;

final class CouponLookupResult
{
    /** @param array<mixed> $rows
     * @return array<string,mixed>|null
     */
    public static function exact(array $rows, string $code): ?array
    {
        if (! array_is_list($rows) || count($rows) >= 100) {
            throw ApiException::conflict('The native coupon lookup is incomplete.', 'provider_coupon_lookup_incomplete');
        }
        $matches = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! is_string($row['code'] ?? null)
                || (! is_int($row['id'] ?? null) && ! is_string($row['id'] ?? null))
                || ! preg_match('/\A[1-9][0-9]{0,18}\z/D', (string) $row['id'])) {
                throw new ApiException('The native coupon response is invalid.', 502, 'provider_coupon_invalid');
            }
            if ($row['code'] === $code) {
                $matches[] = $row;
            }
        }
        if (count($matches) > 1) {
            throw ApiException::conflict('The native coupon identity is ambiguous.', 'provider_coupon_ambiguous');
        }

        return $matches[0] ?? null;
    }
}
