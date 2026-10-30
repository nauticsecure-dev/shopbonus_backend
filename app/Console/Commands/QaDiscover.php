<?php

namespace App\Console\Commands;

use App\Services\Qa\QaInventory;
use Illuminate\Console\Command;

/** Run after every deployment (Trello #37 §1): records new, changed and removed functions. */
class QaDiscover extends Command
{
    protected $signature = 'shopbonus:qa-discover {--sha= : Deployed Git SHA (default APP_RELEASE_SHA)}';

    protected $description = 'Inventory routes, OpenAPI operations, jobs, events, listeners, commands, schedules, migrations and connectors for the Quality Center';

    public function handle(QaInventory $inventory): int
    {
        $sha = (string) ($this->option('sha') ?: config('qa.release_sha'));
        if (preg_match('/^[0-9a-f]{7,64}$/', $sha) !== 1) {
            $this->error('Pass --sha=<git sha> or set APP_RELEASE_SHA.');

            return self::FAILURE;
        }
        $snapshot = $inventory->scan($sha);
        $d = $snapshot->diff['endpoints'];
        $this->info(sprintf('Snapshot %s: %d endpoints (%d added, %d changed, %d removed), %d undocumented, %d components.', $snapshot->public_id,
            $snapshot->totals['endpoints'], count($d['added']), count($d['changed']), count($d['removed']), $snapshot->totals['undocumented'], $snapshot->totals['components']));

        return self::SUCCESS;
    }
}
