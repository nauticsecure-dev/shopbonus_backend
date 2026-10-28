<?php

namespace App\Jobs;

use App\Integrations\OrderRecovery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class RecoverProviderOrder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 55;

    public function __construct(public readonly int $recoveryId)
    {
        $this->afterCommit();
    }

    public function handle(OrderRecovery $recovery): void
    {
        $recovery->run($this->recoveryId);
    }
}
