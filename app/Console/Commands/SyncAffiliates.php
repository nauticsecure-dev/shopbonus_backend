<?php

namespace App\Console\Commands;

use App\Jobs\SyncAffiliateConnection;
use App\Models\AffiliateConnection;
use App\Services\Affiliate\AffiliateSync;
use Illuminate\Console\Command;

/** Queues (or runs) affiliate program/conversion pulls through {@see AffiliateSync}. */
class SyncAffiliates extends Command
{
    protected $signature = 'shopbonus:sync-affiliates
                            {connection? : Affiliate connection public id}
                            {--sync : Run inline instead of dispatching queue jobs}';

    protected $description = 'Pull affiliate programs and conversions from connected networks';

    public function handle(AffiliateSync $sync): int
    {
        $query = AffiliateConnection::query()->where('status', '!=', 'disabled');
        if ($this->argument('connection')) {
            $query->where('public_id', $this->argument('connection'));
        }

        $connections = $query->orderBy('id')->get();
        if ($connections->isEmpty()) {
            $this->warn('No affiliate connections to sync.');

            return self::SUCCESS;
        }

        $failures = 0;
        $inline = (bool) $this->option('sync');

        foreach ($connections as $connection) {
            if (! in_array($connection->status, ['connected', 'limited'], true)) {
                $this->line("Skipping {$connection->public_id} ({$connection->status}).");

                continue;
            }

            if (! $inline) {
                SyncAffiliateConnection::dispatch($connection->id);
                $this->info("Queued {$connection->network} ({$connection->public_id}).");

                continue;
            }

            $result = $sync->sync($connection);
            if ($result['error'] !== null) {
                $failures++;
                $this->error("{$connection->network}: {$result['error']}");
            } else {
                $this->info("{$connection->network}: {$result['programs']} program(s), {$result['transactions']} transaction(s).");
            }
        }

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }
}
