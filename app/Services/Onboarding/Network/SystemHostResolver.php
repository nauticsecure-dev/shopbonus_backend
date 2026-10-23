<?php

namespace App\Services\Onboarding\Network;

class SystemHostResolver implements HostResolver
{
    public function addresses(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        return array_values(array_unique(array_filter(array_map(
            fn (array $r) => $r['ip'] ?? $r['ipv6'] ?? null,
            $records,
        ))));
    }

    public function txtRecords(string $host): array
    {
        $records = @dns_get_record($host, DNS_TXT) ?: [];

        return array_map(
            fn (array $r) => isset($r['entries']) ? implode('', $r['entries']) : (string) ($r['txt'] ?? ''),
            $records,
        );
    }
}
