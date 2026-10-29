<?php

namespace App\Jobs;

use App\Integrations\BulkSynchronization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class SynchronizeProviderOrders implements ShouldQueue
{
    use Dispatchable,InteractsWithQueue,Queueable;

    public int $tries = 3;

    public int $timeout = 55;

    public function __construct(public readonly int $runId)
    {
        $this->afterCommit();
    }

    public function handle(BulkSynchronization $sync): void
    {
        $sync->run($this->runId);
    }
}
