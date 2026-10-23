<?php

namespace App\Support;

/**
 * Minimal E.164 normalisation, Netherlands as the default country:
 * "06 1234 5678" → "+31612345678", "0031 6…" → "+316…". Returns null when
 * the result is not a plausible E.164 number. Not a full validator: it
 * only decides whether a number is usable for a contact request.
 */
final class PhoneNumber
{
    public static function normalize(?string $raw, string $defaultCountryCode = '31'): ?string
    {
        if ($raw === null) {
            return null;
        }

        $number = preg_replace('/[\s().\-\/]/', '', trim($raw)) ?? '';

        if (str_starts_with($number, '00')) {
            $number = '+'.substr($number, 2);
        } elseif (str_starts_with($number, '0')) {
            $number = '+'.$defaultCountryCode.substr($number, 1);
        }

        return preg_match('/^\+[1-9]\d{7,14}$/', $number) === 1 ? $number : null;
    }

    public static function hash(string $e164): string
    {
        return hash_hmac('sha256', 'phone|'.$e164, (string) config('app.key'));
    }
}
