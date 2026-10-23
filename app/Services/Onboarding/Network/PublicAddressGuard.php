<?php

namespace App\Services\Onboarding\Network;

/**
 * SSRF guard: an address is acceptable only if it is a public unicast
 * address. Private, loopback, link-local, CGNAT, documentation, multicast
 * and reserved ranges (v4 and v6, including v4-mapped v6) are refused.
 */
final class PublicAddressGuard
{
    /** Ranges PHP's FILTER_FLAG_NO_PRIV_RANGE / NO_RES_RANGE do not cover. */
    private const EXTRA_BLOCKED_V4 = [
        ['100.64.0.0', 10],   // CGNAT
        ['192.0.0.0', 24],    // IETF protocol assignments
        ['192.0.2.0', 24],    // TEST-NET-1
        ['198.18.0.0', 15],   // benchmarking
        ['198.51.100.0', 24], // TEST-NET-2
        ['203.0.113.0', 24],  // TEST-NET-3
        ['224.0.0.0', 4],     // multicast
    ];

    public static function isPublic(string $ip): bool
    {
        if (str_starts_with(strtolower($ip), '::ffff:')) {
            $ip = substr($ip, 7); // v4-mapped v6
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $long = ip2long($ip);
            foreach (self::EXTRA_BLOCKED_V4 as [$base, $bits]) {
                $mask = -1 << (32 - $bits);
                if (($long & $mask) === (ip2long($base) & $mask)) {
                    return false;
                }
            }

            return true;
        }

        // IPv6: refuse unique-local (fc00::/7), link-local (fe80::/10), multicast (ff00::/8), documentation (2001:db8::/32).
        $bin = inet_pton($ip);

        return $bin !== false
            && (ord($bin[0]) & 0xFE) !== 0xFC
            && ! (ord($bin[0]) === 0xFE && (ord($bin[1]) & 0xC0) === 0x80)
            && ord($bin[0]) !== 0xFF
            && substr($bin, 0, 4) !== "\x20\x01\x0d\xb8";
    }

    /**
     * @param  list<string>  $ips
     */
    public static function allPublic(array $ips): bool
    {
        return $ips !== [] && collect($ips)->every(fn (string $ip) => self::isPublic($ip));
    }
}
