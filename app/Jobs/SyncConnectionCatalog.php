<?php

namespace App\Jobs;

use App\Services\Catalog\CatalogSynchronizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** One read-only catalog/coupon pass; failures are recorded on the pass, not retried blindly. */
class SyncConnectionCatalog implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public readonly int $syncId)
    {
        $this->afterCommit();
    }

    public function handle(CatalogSynchronizer $synchronizer): void
    {
        $synchronizer->run($this->syncId);
    }
}
