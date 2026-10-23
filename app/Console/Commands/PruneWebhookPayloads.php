<?php

namespace App\Console\Commands;

use App\Models\WebhookEvent;
use Illuminate\Console\Command;

/**
 * Trello #1: "Do not retain raw webhook payloads indefinitely." Removes the
 * encrypted raw body after the retention window; the event row (dedupe key,
 * hash, safe result) stays for idempotency and history.
 */
class PruneWebhookPayloads extends Command
{
    protected $signature = 'shopbonus:prune-webhook-payloads {--days= : Override the retention window}';

    protected $description = 'Delete raw webhook payloads older than the retention window';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('integrations.webhook_retention_days', 14));

        $count = WebhookEvent::whereNotNull('payload')
            ->where('received_at', '<', now()->subDays($days))
            ->update(['payload' => null, 'payload_pruned_at' => now()]);

        $this->info("Pruned {$count} webhook payload(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
