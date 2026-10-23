<?php

namespace App\Services\Onboarding;

use App\Exceptions\ApiException;
use Illuminate\Support\Str;

/**
 * Normalises a website URL or host to the bare registrable hostname used
 * for ownership checks: lower-case, no scheme/port/path, no leading
 * "www.", IDN converted to punycode. Rejects IP literals, single-label
 * names and reserved/internal names, so ownership checks never target
 * infrastructure (SSRF) or non-public hosts.
 */
final class DomainName
{
    private const RESERVED_SUFFIXES = ['localhost', 'local', 'internal', 'lan', 'home', 'corp', 'test', 'example', 'invalid', 'arpa'];

    public static function fromInput(string $input): string
    {
        $input = trim($input);
        $host = Str::contains($input, '://') ? (string) parse_url($input, PHP_URL_HOST) : Str::before(Str::before($input, '/'), ':');
        $host = rtrim(Str::lower($host), '.');

        if (function_exists('idn_to_ascii') && preg_match('/[^\x20-\x7e]/', $host)) {
            $host = (string) idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        }

        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        $labels = explode('.', $host);
        $valid = count($labels) >= 2
            && strlen($host) <= 253
            && filter_var($host, FILTER_VALIDATE_IP) === false
            && ! in_array(end($labels), self::RESERVED_SUFFIXES, true)
            && collect($labels)->every(fn (string $l) => preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)$/', $l) === 1)
            && preg_match('/^[a-z]{2,63}$|^xn--[a-z0-9-]+$/', (string) end($labels)) === 1;

        if (! $valid) {
            throw new ApiException('Enter a valid public website domain.', 422, 'invalid_domain');
        }

        return $host;
    }
}
