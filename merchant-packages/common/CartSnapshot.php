<?php

/** Session-only cart totals. No identifiers, items, customer data or provider credentials leave the shop. */
final class ShopbonusCartSnapshot
{
    private const CURRENCIES = ['EUR', 'USD', 'GBP', 'CAD', 'AUD', 'NZD', 'CHF', 'SEK', 'NOK', 'DKK', 'PLN', 'CZK'];

    public static function fromMajor(mixed $amount, mixed $currency, mixed $precision, string $platform): ?array
    {
        if (PHP_INT_SIZE < 8 || $precision !== 2 || ! in_array($currency, self::CURRENCIES, true) || ! in_array($platform, ['prestashop', 'opencart', 'magento'], true)
            || (! is_int($amount) && ! is_float($amount) && ! is_string($amount)) || (is_float($amount) && ! is_finite($amount))) {
            return null;
        }
        // Native decimal output only: reject sub-cent precision instead of rounding or parsing formatted prices.
        if (! preg_match('/\A(0|[1-9][0-9]{0,12})(?:\.([0-9]+))?\z/D', (string) $amount, $m)) {
            return null;
        }
        $fraction = rtrim($m[2] ?? '', '0');
        if (strlen($fraction) > 2) {
            return null;
        }
        $minor = (int) ($m[1].str_pad($fraction, 2, '0'));

        return ['schema' => 'shopbonus.cart-source.v1', 'platform' => $platform, 'total_minor' => $minor, 'currency' => $currency, 'minor_unit' => 2];
    }
}
