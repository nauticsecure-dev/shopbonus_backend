<?php

namespace App\Integrations\Contracts;

use App\Models\WebshopConnection;
use Carbon\CarbonImmutable;

interface PagedOrderReader
{
    /** @return array{ids:list<string>,next:?string} */
    public function discoverOrders(WebshopConnection $connection, CarbonImmutable $since, CarbonImmutable $until, ?string $cursor): array;
}
