<?php

namespace App\Services\Onboarding\Network;

interface HostResolver
{
    /**
     * IPv4/IPv6 addresses the host resolves to (empty when unresolvable).
     *
     * @return list<string>
     */
    public function addresses(string $host): array;

    /**
     * TXT record strings for the host (empty when none).
     *
     * @return list<string>
     */
    public function txtRecords(string $host): array;
}
