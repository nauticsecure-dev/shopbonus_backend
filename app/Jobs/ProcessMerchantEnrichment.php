<?php

namespace App\Jobs;

use App\Services\Enrichment\EnrichmentPipeline;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class ProcessMerchantEnrichment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 90;

    public function __construct(public readonly int $runId) {}

    public function handle(EnrichmentPipeline $pipeline): void
    {
        $pipeline->execute($this->runId);
    }

    public function failed(?\Throwable $exception): void
    {
        app(EnrichmentPipeline::class)->fail($this->runId, 'job_failed');
    }
}
