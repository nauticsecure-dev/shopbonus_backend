<?php

namespace App\Console\Commands;

use App\Integrations\BulkSynchronization;
use App\Jobs\SynchronizeProviderOrders;
use App\Models\IntegrationSyncRun;
use App\Models\WebshopConnection;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class SynchronizeIntegrationOrders extends Command
{
    protected $signature = 'shopbonus:sync-integration-orders {connection?} {--since=} {--until=} {--request=} {--drain} {--auto} {--limit=25}';

    protected $description = 'Durable bounded native order discovery and reconciliation; fixed UTC windows, no provider writes';

    public function handle(BulkSynchronization $sync): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 100) {
            return self::INVALID;
        }
        if ($this->option('auto')) {
            if (! config('integrations.sync.enabled')) {
                $this->info('Automatic discovery disabled.');

                return self::SUCCESS;
            }
            foreach (WebshopConnection::whereIn('public_id', config('integrations.sync.connections'))->where('status', '!=', 'disconnected')->limit($limit)->get() as $c) {
                $active = IntegrationSyncRun::where('connection_id', $c->id)->where('credential_generation', $c->credential_generation)->whereIn('status', ['pending', 'reading', 'waiting'])->exists();
                if ($active) {
                    continue;
                }
                $last = IntegrationSyncRun::where('connection_id', $c->id)->where('credential_generation', $c->credential_generation)->where('status', 'completed')->latest('until_at')->first();
                $recentFailure = IntegrationSyncRun::where('connection_id', $c->id)->where('status', 'failed')->where('updated_at', '>', now()->subMinutes(15))->exists();
                if ($recentFailure) {
                    continue;
                }
                $until = CarbonImmutable::now()->utc();
                $since = $last === null ? $until->subDay() : CarbonImmutable::parse($last->until_at)->subSeconds(120);
                $r = $sync->request($c, $since, $until, (string) Str::uuid());
                SynchronizeProviderOrders::dispatch($r->id);
            }

            return self::SUCCESS;
        }
        if ($this->option('drain')) {
            $rows = IntegrationSyncRun::whereIn('status', ['pending', 'reading', 'waiting'])->where(function ($q) {
                $q->whereNull('leased_until')->orWhere('leased_until', '<=', now());
            }
            )->orderBy('id')->limit($limit)->get();
            foreach ($rows as $r) {
                SynchronizeProviderOrders::dispatch($r->id);
            }
            $this->info('Dispatched '.$rows->count().' discovery steps.');

            return self::SUCCESS;
        }
        $id = $this->argument('connection');
        if (! is_string($id) || ! Str::isUlid($id)) {
            return self::INVALID;
        }
        $c = WebshopConnection::where('public_id', strtolower($id))->first();
        if ($c === null) {
            return self::FAILURE;
        }
        try {
            $until = $this->option('until') ? CarbonImmutable::parse($this->option('until'))->utc() : CarbonImmutable::now()->utc();
            $since = $this->option('since') ? CarbonImmutable::parse($this->option('since'))->utc() : $until->subDay();
            $r = $sync->request($c, $since, $until, $this->option('request') ?: (string) Str::uuid());
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::INVALID;
        }
        SynchronizeProviderOrders::dispatch($r->id);
        $this->line(json_encode(['run' => $r->public_id, 'status' => $r->fresh()?->status, 'coverage' => 'provider_window_observation_not_atomic_snapshot'], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
