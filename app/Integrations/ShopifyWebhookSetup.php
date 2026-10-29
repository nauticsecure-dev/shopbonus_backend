<?php

namespace App\Integrations;

use App\Enums\AuditCategory;
use App\Enums\ConnectionStatus;
use App\Enums\EcommercePlatform;
use App\Exceptions\ApiException;
use App\Http\Resources\ConnectionResource;
use App\Integrations\Http\ShopifyApi;
use App\Models\AuditEvent;
use App\Models\WebshopConnection;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Provider listing is authoritative; unresolved create intents prohibit blind repeat mutations. */
class ShopifyWebhookSetup
{
    public function __construct(private ShopifyApi $api, private AuditLogger $audit) {}

    /** @return array{topic:string,subscription_id:string,created:bool} */
    public function ensure(int $connectionId, int $generation, string $topic, ?int $reviewIntentId = null): array
    {
        if (! in_array($topic, ShopifyApi::WEBHOOK_TOPICS, true)) {
            throw new \InvalidArgumentException('Unsupported webhook topic.');
        }
        if (! config('integrations.shopify_webhooks.enabled')) {
            throw new ApiException('Webhook provisioning is disabled.', 503, 'webhook_setup_disabled');
        }
        $lock = Cache::lock('shopify-hook:'.$connectionId.':'.$topic, 90);
        if (! $lock->get()) {
            throw new ApiException('Webhook setup is already in progress. Retry shortly.', 503, 'webhook_setup_busy');
        }
        try {
            $snapshot = WebshopConnection::with('webshop')->findOrFail($connectionId);
            $this->authorize($snapshot, $generation);
            $uri = ConnectionResource::webhookUrl($snapshot);
            $parts = parse_url($uri);
            if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || empty($parts['host'])
                || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || isset($parts['port'])) {
                throw ApiException::conflict('Configure a public HTTPS application URL before provisioning webhooks.', 'webhook_callback_invalid');
            }
            $context = ['generation' => $generation, 'topic' => $topic, 'callback_digest' => hash('sha256', $uri)];
            $hooks = $this->api->webhooks($snapshot, $topic);
            $existing = $this->matching($hooks, $uri);
            if ($existing !== null) {
                $this->observe($snapshot, $generation, $context, $existing);

                return ['topic' => $topic, 'subscription_id' => $existing['id'], 'created' => false];
            }
            // Durable intent commits before the HTTP mutation. A crash/timeout cannot erase it.
            $intent = DB::transaction(function () use ($snapshot, $generation, $context, $reviewIntentId) {
                $current = $this->current($snapshot, $generation);
                $latest = AuditEvent::where('resource_type', $current->getMorphClass())->where('resource_id', (string) $current->id)
                    ->where('action', 'integration.webhook_create_intent')->where('metadata->generation', $generation)
                    ->where('metadata->topic', $context['topic'])->where('metadata->callback_digest', $context['callback_digest'])->latest('id')->first();
                $resolved = $latest !== null && AuditEvent::where('resource_type', $current->getMorphClass())->where('resource_id', (string) $current->id)
                    ->where('action', 'integration.webhook_subscription_observed')->where('id', '>', $latest->id)
                    ->where('metadata->generation', $generation)->where('metadata->topic', $context['topic'])
                    ->where('metadata->callback_digest', $context['callback_digest'])->exists();
                if ($latest !== null && ! $resolved) {
                    if ($reviewIntentId !== $latest->id) {
                        throw ApiException::conflict('A previous create may have succeeded. Inspect native subscriptions before explicitly reviewing this intent.', 'provider_hook_outcome_unknown', ['intent_id' => $latest->id]);
                    }
                    $this->audit->record('integration.webhook_missing_reviewed', category: AuditCategory::Integration, resource: $current,
                        webshopId: $current->webshop_id, metadata: $context + ['reviewed_intent_id' => $latest->id]);
                } elseif ($reviewIntentId !== null) {
                    throw ApiException::conflict('The review does not match an outstanding create intent.', 'webhook_intent_review_invalid');
                }

                return $this->audit->record('integration.webhook_create_intent', category: AuditCategory::Integration, resource: $current,
                    webshopId: $current->webshop_id, metadata: $context);
            });
            $created = $this->api->createWebhook($snapshot, $topic);
            $observed = $this->matching($this->api->webhooks($snapshot, $topic), $uri);
            if ($observed === null || $observed['id'] !== $created['id']) {
                throw ApiException::conflict('The provider has not confirmed this subscription. Inspect it before retrying creation.', 'provider_hook_outcome_unknown', ['intent_id' => $intent->id]);
            }
            $this->observe($snapshot, $generation, $context, $observed);

            return ['topic' => $topic, 'subscription_id' => $observed['id'], 'created' => true];
        } finally {
            $lock->release();
        }
    }

    private function authorize(WebshopConnection $c, int $generation): void
    {
        if ($c->platform !== EcommercePlatform::Shopify || $c->status === ConnectionStatus::Disconnected || $c->credential_generation !== $generation) {
            throw ApiException::conflict('The connection changed during webhook setup.', 'connection_changed');
        }
        $secret = config('integrations.shopify_oauth.client_secret');
        if (! $c->webshop->isOwnershipVerified() || $c->credential('oauth_authority') === null || $c->credential('access_token') === null
            || ! is_string($secret) || strlen($secret) < 16 || ! hash_equals($secret, (string) $c->credential('signing_secret'))) {
            throw ApiException::conflict('Authorize the configured Shopify app for this verified store before provisioning hooks.', 'webhook_app_authority_required');
        }
    }

    /** Caller holds a transaction; connection-first lock matches the existing lifecycle. */
    private function current(WebshopConnection $snapshot, int $generation): WebshopConnection
    {
        $c = WebshopConnection::with('webshop')->whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
        $this->authorize($c, $generation);
        if (! $snapshot->hasSameCredentialAuthority($c) || $snapshot->external_shop_ref !== $c->external_shop_ref
            || $snapshot->webhook_token !== $c->webhook_token || $snapshot->webshop->verified_domain !== $c->webshop->verified_domain) {
            throw ApiException::conflict('The connection changed during webhook setup.', 'connection_changed');
        }

        return $c;
    }

    /** @param list<array<string,mixed>> $hooks
     * @return array<string,mixed>|null
     */
    private function matching(array $hooks, string $uri): ?array
    {
        $found = array_values(array_filter($hooks, fn ($h) => $h['uri'] === $uri));
        if (count($found) > 1) {
            throw ApiException::conflict('Duplicate subscriptions require operator review.', 'provider_hook_duplicate');
        }
        if ($found === []) {
            return null;
        }
        $hook = $found[0];
        if (($hook['format'] ?? null) !== 'JSON' || ! in_array($hook['filter'] ?? null, [null, ''], true) || ($hook['includeFields'] ?? null) !== []) {
            throw ApiException::conflict('The existing subscription filters or format require operator review.', 'provider_hook_configuration_conflict');
        }

        return $hook;
    }

    /** @param array<string,mixed> $context
     * @param  array<string,mixed>  $hook
     */
    private function observe(WebshopConnection $snapshot, int $generation, array $context, array $hook): void
    {
        DB::transaction(function () use ($snapshot, $generation, $context, $hook) {
            $c = $this->current($snapshot, $generation);
            $this->audit->record('integration.webhook_subscription_observed', category: AuditCategory::Integration, resource: $c,
                webshopId: $c->webshop_id, metadata: $context + ['subscription_id' => $hook['id']]);
        });
    }
}
