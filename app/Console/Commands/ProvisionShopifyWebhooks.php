<?php

namespace App\Console\Commands;

use App\Exceptions\ApiException;
use App\Integrations\Http\ShopifyApi;
use App\Integrations\ShopifyWebhookSetup;
use App\Jobs\ProvisionShopifyWebhook;
use App\Models\WebshopConnection;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ProvisionShopifyWebhooks extends Command
{
    protected $signature = 'shopbonus:provision-shopify-webhooks {connection} {--topic=} {--inline} {--review-intent= : Explicitly confirm the specified unresolved intent was reviewed as missing at Shopify}';

    protected $description = 'Provision fixed Shopify webhook callbacks; native listing precedes each mutation';

    public function handle(ShopifyWebhookSetup $setup): int
    {
        $id = $this->argument('connection');
        $topic = $this->option('topic');
        $review = $this->option('review-intent');
        if (! Str::isUlid($id) || ($topic !== null && ! in_array($topic, ShopifyApi::WEBHOOK_TOPICS, true))
            || ($review !== null && (! $this->option('inline') || $topic === null || ! ctype_digit($review) || (int) $review < 1))) {
            $this->error('Use a connection ULID, supported topic and an explicit single-topic inline review when required.');

            return self::INVALID;
        }
        $c = WebshopConnection::where('public_id', strtolower($id))->first();
        if ($c === null) {
            $this->error('Connection not found.');

            return self::FAILURE;
        }
        if (! config('integrations.shopify_webhooks.enabled')) {
            $this->error('Webhook provisioning is disabled.');

            return self::FAILURE;
        }
        if (! $this->option('inline') && config('queue.default') === 'sync') {
            $this->error('Configure an asynchronous queue or explicitly use --inline.');

            return self::FAILURE;
        }
        foreach ($topic === null ? ShopifyApi::WEBHOOK_TOPICS : [$topic] as $item) {
            try {
                if ($this->option('inline')) {
                    $this->line(json_encode($setup->ensure($c->id, $c->credential_generation, $item, $review === null ? null : (int) $review), JSON_THROW_ON_ERROR));
                } else {
                    ProvisionShopifyWebhook::dispatch($c->id, $c->credential_generation, $item);
                }
            } catch (ApiException $e) {
                $this->error(json_encode(['code' => $e->errorCode, 'details' => $e->details], JSON_THROW_ON_ERROR));

                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
