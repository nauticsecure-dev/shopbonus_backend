<?php

namespace App\Jobs;

use App\Affiliate\Adapters\DaisyconAdapter;
use App\Affiliate\NetworkFailure;
use App\Models\AffiliateConnection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Independent, recoverable resource snapshots. Missing/failed data never grants permission. */
class SyncDaisyconEvidence implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 20;

    public int $timeout = 120;

    public function __construct(public int $connectionId, public string $resource, public ?string $programId = null)
    {
        $this->onQueue('affiliate');
        $this->afterCommit();
    }

    public function middleware(): array
    {
        return [new RateLimited('daisycon-evidence')];
    }

    public function backoff(): array
    {
        return [60, 120, 300, 600];
    }

    public function handle(DaisyconAdapter $adapter): void
    {
        $connection = AffiliateConnection::find($this->connectionId);
        if ($connection === null || $connection->network !== 'daisycon' || $connection->status === 'disabled') {
            return;
        }
        $key = $this->resource.($this->programId === null ? '' : ':'.$this->programId);
        try {
            $payload = $adapter->fetchEvidence($connection, $this->resource, $this->programId);
            DB::table('affiliate_provider_resources')->updateOrInsert(['connection_id' => $connection->id, 'resource_key' => $key], [
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'fetched_at' => now(), 'last_error' => null, 'updated_at' => now(),
            ]);
        } catch (Throwable $e) {
            // Preserve the last complete snapshot and timestamp; queue retries this resource independently.
            DB::table('affiliate_provider_resources')->updateOrInsert(['connection_id' => $connection->id, 'resource_key' => $key], [
                'last_error' => $e instanceof NetworkFailure ? $e->error : 'provider_resource_failed', 'updated_at' => now(),
            ]);
            throw $e;
        }
    }
}
