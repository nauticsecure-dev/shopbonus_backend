<?php

namespace App\Console\Commands;

use App\Services\Admin\ApiRouteRegistry;
use Illuminate\Console\Command;

class ApiRoutes extends Command
{
    protected $signature = 'shopbonus:api-routes';

    protected $description = 'Generate the API route/access/scope/audit registry from runtime routes and OpenAPI';

    public function handle(ApiRouteRegistry $registry): int
    {
        $entries = $registry->entries();
        $this->line(json_encode(['data' => $entries], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        return collect($entries)->every(fn ($r) => $r['contract_matches']) ? self::SUCCESS : self::FAILURE;
    }
}
