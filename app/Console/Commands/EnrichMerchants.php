<?php

namespace App\Console\Commands;

use App\Services\Enrichment\EnrichmentPipeline;
use Illuminate\Console\Command;

class EnrichMerchants extends Command
{
    protected $signature = 'shopbonus:enrich-merchants {--limit=25 : Maximum webshops to enqueue}';

    protected $description = 'Queue merchant website enrichment runs for eligible approved webshops';

    public function handle(EnrichmentPipeline $pipeline): int
    {
        if (! $pipeline->configured()) {
            $this->warn('Merchant enrichment is disabled or not configured.');

            return self::SUCCESS;
        }

        $limit = max(1, min(500, (int) $this->option('limit')));
        $webshops = $pipeline->eligibleWebshops($limit);
        if ($webshops === []) {
            $this->info('No eligible webshops for enrichment.');

            return self::SUCCESS;
        }

        $queued = 0;
        foreach ($webshops as $webshop) {
            try {
                $run = $pipeline->enqueue($webshop, 'scheduled');
                $queued++;
                $this->line("Queued {$webshop->public_id} ({$run->public_id}).");
            } catch (\Throwable $e) {
                $this->error("Skipped {$webshop->public_id}: {$e->getMessage()}");
            }
        }

        $this->info("Queued {$queued} enrichment run(s).");

        return self::SUCCESS;
    }
}
