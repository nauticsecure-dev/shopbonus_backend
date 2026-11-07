<?php

namespace App\Jobs;

use App\Services\Support\SupportConnectivity;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Synthetic queue-worker probe (no customer data). Dispatched by the scheduled support
 * connectivity probe on the same connection/queue as ProcessSupportMessage, so only a
 * running worker can complete it.
 */
class ProbeSupportWorker implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 10;

    public function __construct(public readonly string $nonce, public readonly float $dispatchedAt) {}

    public function handle(SupportConnectivity $connectivity): void
    {
        $connectivity->workerProcessed($this->nonce, $this->dispatchedAt);
    }
}
