<?php

namespace App\Jobs;

use App\Models\AffiliateConnection;
use App\Services\Affiliate\AffiliateSync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Queued program/conversion pull for one affiliate network connection.
 * Failures update connection status; they never mutate wallets.
 */
class SyncAffiliateConnection implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public readonly int $connectionId)
    {
        $this->afterCommit();
        $this->onQueue('affiliate');
    }

    public function handle(AffiliateSync $sync): void
    {
        $connection = AffiliateConnection::find($this->connectionId);
        if ($connection === null || $connection->status === 'disabled') {
            return;
        }

        $sync->sync($connection);
    }
}
