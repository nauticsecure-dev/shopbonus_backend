<?php

namespace App\Console\Commands;

use App\Services\Answer24\OutboxDispatcher;
use Illuminate\Console\Command;

/**
 * Delivers pending Answer24 outbox messages. A no-op while
 * ANSWER24_DISPATCH_ENABLED is false.
 */
class DispatchOutbox extends Command
{
    protected $signature = 'shopbonus:dispatch-outbox {--limit=50}';

    protected $description = 'Deliver pending integration outbox messages to Answer24 (when enabled)';

    public function handle(OutboxDispatcher $dispatcher): int
    {
        if (! config('shopbonus.answer24.dispatch_enabled')) {
            $this->info('Answer24 dispatch is disabled; nothing sent.');

            return self::SUCCESS;
        }

        $totals = $dispatcher->dispatch((int) $this->option('limit'));
        $this->info("Sent {$totals['sent']}, retrying {$totals['retried']}, failed {$totals['failed']}, skipped {$totals['skipped']}.");

        return self::SUCCESS;
    }
}
