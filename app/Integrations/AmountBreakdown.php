<?php

namespace App\Integrations;

use App\Support\Money;

/**
 * Provider-reported monetary detail of an order (Trello #32), kept beside the
 * canonical `amount_minor`, never replacing it: `amount_minor` stays the gross
 * total with refunds held separately, so nothing is subtracted twice. A part is
 * null when the provider does not report it; nothing is derived or guessed.
 */
final class AmountBreakdown
{
    /**
     * @param  array<string, string|null>  $amounts  original_total, current_total, tax, shipping, discount as provider decimal strings
     * @return array<string, mixed>|null
     */
    public static function make(?string $currency, string $source, array $amounts, ?bool $taxIncluded = null): ?array
    {
        if (! Money::isCurrency($currency)) {
            return null;
        }
        $out = ['source' => $source, 'currency' => $currency, 'tax_included' => $taxIncluded];
        foreach (['original_total', 'current_total', 'tax', 'shipping', 'discount'] as $part) {
            $out["{$part}_minor"] = self::minor($amounts[$part] ?? null, $currency);
        }

        return $out['original_total_minor'] === null && $out['current_total_minor'] === null && $out['tax_minor'] === null
            && $out['shipping_minor'] === null && $out['discount_minor'] === null ? null : $out;
    }

    private static function minor(mixed $value, string $currency): ?int
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            $minor = Money::toMinor($value, $currency);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return $minor >= 0 ? $minor : null;
    }
}
