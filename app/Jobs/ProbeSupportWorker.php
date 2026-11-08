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

    // A class default also initializes this field when an older queued payload is unserialized.
    public string $configuration = '';

    public function __construct(public readonly string $nonce, public readonly float $dispatchedAt, string $configuration = '')
    {
        $this->configuration = $configuration;
    }

    public function handle(SupportConnectivity $connectivity): void
    {
        if ($this->job !== null) {
            $connectivity->workerProcessed($this->nonce, $this->dispatchedAt, $this->configuration, $this->job->getConnectionName(), $this->job->getQueue());
        }
    }
}
