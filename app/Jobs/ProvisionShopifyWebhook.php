<?php

namespace App\Jobs;

use App\Integrations\ShopifyWebhookSetup;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class ProvisionShopifyWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 75;

    /** @var list<int> */
    public array $backoff = [15, 60, 120];

    public function __construct(public readonly int $connectionId, public readonly int $generation, public readonly string $topic)
    {
        $this->afterCommit();
    }

    public function handle(ShopifyWebhookSetup $setup): void
    {
        if (config('integrations.shopify_webhooks.enabled')) {
            $setup->ensure($this->connectionId, $this->generation, $this->topic);
        }
    }
}
