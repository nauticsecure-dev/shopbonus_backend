<?php

namespace Tests\Fakes;

use App\Services\Onboarding\Network\HostResolver;

class FakeHostResolver implements HostResolver
{
    /** @var array<string, list<string>> */
    public array $addresses = [];

    /** @var array<string, list<string>> */
    public array $txt = [];

    public function addresses(string $host): array
    {
        return $this->addresses[$host] ?? [];
    }

    public function txtRecords(string $host): array
    {
        return $this->txt[$host] ?? [];
    }
}
