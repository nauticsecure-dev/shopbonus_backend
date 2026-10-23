<?php

namespace App\Support;

/**
 * The Shopbonus signature scheme, shared by inbound partner requests and
 * outbound calls: `X-Shopbonus-Signature: t=<unix>,v1=<hex HMAC-SHA256(secret, "t.body")>`.
 * Several v1 values are accepted so a secret can be rotated.
 */
final class SignatureV1
{
    public const TOLERANCE_SECONDS = 300;

    public static function header(string $secret, string $body, ?int $timestamp = null): string
    {
        $timestamp ??= time();

        return "t={$timestamp},v1=".hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    public static function verify(string $secret, string $body, string $header, ?int $now = null): bool
    {
        $parts = [];
        foreach (explode(',', $header) as $pair) {
            [$k, $v] = array_pad(explode('=', trim($pair), 2), 2, '');
            $parts[$k][] = $v;
        }

        $timestamp = isset($parts['t'][0]) && ctype_digit($parts['t'][0]) ? (int) $parts['t'][0] : null;
        if ($timestamp === null || ($parts['v1'] ?? []) === [] || abs(($now ?? time()) - $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$body, $secret);
        foreach ($parts['v1'] as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }
}
