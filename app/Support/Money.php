<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Exact conversion of provider decimal strings ("25.99") to integer minor
 * units. Never uses floats and never rounds: an amount with more decimals
 * than the currency allows is rejected rather than silently changed.
 */
final class Money
{
    /** ISO 4217 exponents that differ from 2. */
    private const EXPONENTS = [
        'BIF' => 0, 'CLP' => 0, 'DJF' => 0, 'GNF' => 0, 'ISK' => 0, 'JPY' => 0, 'KMF' => 0, 'KRW' => 0, 'PYG' => 0,
        'RWF' => 0, 'UGX' => 0, 'VND' => 0, 'VUV' => 0, 'XAF' => 0, 'XOF' => 0, 'XPF' => 0,
        'BHD' => 3, 'IQD' => 3, 'JOD' => 3, 'KWD' => 3, 'LYD' => 3, 'OMR' => 3, 'TND' => 3,
    ];

    public static function exponent(string $currency): int
    {
        return self::EXPONENTS[strtoupper($currency)] ?? 2;
    }

    public static function isCurrency(mixed $currency): bool
    {
        return is_string($currency) && preg_match('/^[A-Z]{3}$/', $currency) === 1;
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function toMinor(string|int $amount, string $currency): int
    {
        $amount = trim((string) $amount);

        if (preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $amount, $m) !== 1) {
            throw new InvalidArgumentException('Not a decimal amount.');
        }

        $exponent = self::exponent($currency);
        $fraction = $m[3] ?? '';

        if (strlen(rtrim($fraction, '0')) > $exponent) {
            throw new InvalidArgumentException("More decimals than {$currency} allows.");
        }

        $digits = ltrim($m[2].str_pad(substr($fraction, 0, $exponent), $exponent, '0'), '0');
        $digits = $digits === '' ? '0' : $digits;

        if (strlen($digits) > 15) {
            throw new InvalidArgumentException('Amount out of range.');
        }

        return (int) ($m[1].$digits);
    }
}
