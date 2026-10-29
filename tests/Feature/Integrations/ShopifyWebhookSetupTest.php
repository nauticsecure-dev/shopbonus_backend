<?php

namespace Tests\Feature\Integrations;

use App\Enums\EcommercePlatform;
use App\Exceptions\ApiException;
use App\Http\Resources\ConnectionResource;
use App\Integrations\ConnectionManager;
use App\Integrations\ShopifyWebhookSetup;
use App\Jobs\ProvisionShopifyWebhook;
use App\Models\AuditEvent;
use App\Models\User;
use App\Models\Webshop;
use App\Models\WebshopConnection;
use App\Services\Onboarding\Network\HostResolver;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopifyWebhookSetupTest extends TestCase
{
    use RefreshDatabase;

    private WebshopConnection $c;

    /** @var list<array<string,mixed>> */
    private array $hooks = [];

    private string $mode = 'ok';

    private int $mutations = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://api.shopbonus.example', 'integrations.shopify_webhooks.enabled' => true, 'integrations.shopify_oauth.client_secret' => 'SYNTHETIC_client_secret']);
        $u = User::factory()->create();
        $shop = Webshop::factory()->withMember($u)->create(['created_by' => $u->id, 'verified_domain' => 'fietsen-utrecht.nl', 'ownership_verified_at' => now(), 'ownership_method' => 'dns_txt']);
        [$this->c] = app(ConnectionManager::class)->create($shop->memberships()->sole(), EcommercePlatform::Shopify, ['shop_domain' => 'fietsen.myshopify.com', 'signing_secret' => 'SYNTHETIC_client_secret', 'access_token' => 'SYNTHETIC_approved_token']);
        $this->c->credentials = $this->c->credentials + ['oauth_authority' => 'SYNTHETIC_APPROVED_GRANT'];
        $this->c->save();
        $this->app->bind(HostResolver::class, fn () => new class implements HostResolver
        {
            public function addresses(string $h): array
            {
                return ['93.184.216.34'];
            }

            public function txtRecords(string $h): array
            {
                return [];
            }
        });
        Http::fake(function ($r) {
            $query = $r['query'];
            if (str_contains($query, 'ShopbonusIdentity')) {
                return $this->reply(['shop' => ['myshopifyDomain' => 'fietsen.myshopify.com', 'primaryDomain' => ['host' => 'fietsen-utrecht.nl']], 'currentAppInstallation' => ['accessScopes' => [['handle' => 'read_orders']]]]);
            }
            if (str_contains($query, 'mutation ShopbonusCreateWebhook')) {
                $this->mutations++;
                if ($this->mode === 'no_write') {
                    return Http::failedConnection();
                }
                $hook = $this->hook();
                $this->hooks[] = $hook;
                if ($this->mode === 'rotate_after_create') {
                    app(ConnectionManager::class)->replaceCredentials($this->c, ['shop_domain' => 'fietsen.myshopify.com', 'signing_secret' => 'SYNTHETIC_rotated_secret']);
                    $this->mode = 'ok';
                }
                if ($this->mode === 'lost') {
                    return Http::failedConnection();
                }

                return $this->reply(['webhookSubscriptionCreate' => ['webhookSubscription' => $hook, 'userErrors' => []]]);
            }
            if ($this->mode === 'rotate') {
                app(ConnectionManager::class)->replaceCredentials($this->c, ['shop_domain' => 'fietsen.myshopify.com', 'signing_secret' => 'SYNTHETIC_rotated_secret']);
                $this->mode = 'ok';
            }

            return $this->reply(['webhookSubscriptions' => ['nodes' => $this->hooks, 'pageInfo' => ['hasNextPage' => $this->mode === 'truncated']]]);
        });
    }

    /** @param array<string,mixed> $data */
    private function reply(array $data): PromiseInterface
    {
        return Http::response(['data' => $data], 200, ['X-Shopify-API-Version' => '2026-10']);
    }

    /** @return array<string,mixed> */
    private function hook(): array
    {
        return ['id' => 'gid://shopify/WebhookSubscription/1', 'topic' => 'ORDERS_CREATE', 'uri' => ConnectionResource::webhookUrl($this->c), 'format' => 'JSON', 'filter' => null, 'includeFields' => []];
    }

    /** @return array{topic:string,subscription_id:string,created:bool} */
    private function ensure(?int $review = null): array
    {
        return app(ShopifyWebhookSetup::class)->ensure($this->c->id, $this->c->credential_generation, 'ORDERS_CREATE', $review);
    }

    public function test_create_is_observed_and_replay_only_lists_without_recreation_or_readiness_promotion(): void
    {
        $this->assertTrue($this->ensure()['created']);
        $this->assertFalse($this->ensure()['created']);
        $this->assertSame(1, $this->mutations);
        $this->assertSame('setup_required', $this->c->fresh()->status->value);
        $this->assertDatabaseCount('webshop_orders', 0);
        $this->assertSame(1, AuditEvent::where('action', 'integration.webhook_create_intent')->count());
        Http::assertSent(fn ($r) => str_contains($r['query'], 'mutation ShopbonusCreateWebhook') && $r['variables']['subscription'] === ['uri' => ConnectionResource::webhookUrl($this->c), 'format' => 'JSON']);
    }

    public function test_acknowledgement_loss_is_recovered_from_provider_listing_without_second_mutation(): void
    {
        $this->mode = 'lost';
        try {
            $this->ensure();
            $this->fail('Expected timeout.');
        } catch (ApiException $e) {
            $this->assertSame('provider_unreachable', $e->errorCode);
        }
        $this->mode = 'ok';
        $this->assertFalse($this->ensure()['created']);
        $this->assertSame(1, $this->mutations);
    }

    public function test_unknown_missing_outcome_requires_explicit_matching_intent_review_before_repeat(): void
    {
        $this->mode = 'no_write';
        try {
            $this->ensure();
            $this->fail('Expected timeout.');
        } catch (ApiException $e) {
            $this->assertSame('provider_unreachable', $e->errorCode);
        }
        $this->mode = 'ok';
        $intent = AuditEvent::where('action', 'integration.webhook_create_intent')->sole();
        try {
            $this->ensure();
            $this->fail('Blind create retried.');
        } catch (ApiException $e) {
            $this->assertSame('provider_hook_outcome_unknown', $e->errorCode);
            $this->assertSame($intent->id, $e->details['intent_id']);
        }
        $this->assertSame(1, $this->mutations);
        $this->assertTrue($this->ensure($intent->id)['created']);
        $this->assertSame(2, $this->mutations);
    }

    public function test_stale_generation_during_listing_cannot_create(): void
    {
        $this->mode = 'rotate';
        try {
            $this->ensure();
            $this->fail('Stale setup accepted.');
        } catch (ApiException $e) {
            $this->assertSame('connection_changed', $e->errorCode);
        }
        $this->assertSame(0, $this->mutations);
    }

    public function test_truncated_and_filtered_lists_cannot_be_certified_or_mutated(): void
    {
        $this->mode = 'truncated';
        try {
            $this->ensure();
            $this->fail('Truncated list accepted.');
        } catch (ApiException $e) {
            $this->assertSame('provider_hook_list_incomplete', $e->errorCode);
        }
        $this->mode = 'ok';
        $this->hooks = [$this->hook()];
        $this->hooks[0]['filter'] = 'total_price:>=100';
        try {
            $this->ensure();
            $this->fail('Filtered hook accepted.');
        } catch (ApiException $e) {
            $this->assertSame('provider_hook_configuration_conflict', $e->errorCode);
        }
        $this->assertSame(0, $this->mutations);
    }

    public function test_disabled_or_unapproved_manual_authority_fails_before_http(): void
    {
        config(['integrations.shopify_webhooks.enabled' => false]);
        try {
            $this->ensure();
            $this->fail('Disabled setup ran.');
        } catch (ApiException $e) {
            $this->assertSame('webhook_setup_disabled', $e->errorCode);
        }
        config(['integrations.shopify_webhooks.enabled' => true]);
        $this->c->credentials = ['signing_secret' => 'SYNTHETIC_client_secret', 'access_token' => 'SYNTHETIC_manual_token'];
        $this->c->save();
        try {
            $this->ensure();
            $this->fail('Manual authority accepted.');
        } catch (ApiException $e) {
            $this->assertSame('webhook_app_authority_required', $e->errorCode);
        }
        Http::assertNothingSent();
    }

    public function test_rotation_after_provider_mutation_never_records_current_generation_success(): void
    {
        $this->mode = 'rotate_after_create';
        try {
            $this->ensure();
            $this->fail('Stale external result certified.');
        } catch (ApiException $e) {
            $this->assertSame('connection_changed', $e->errorCode);
        }
        $this->assertSame(1, $this->mutations);
        $this->assertSame(1, AuditEvent::where('action', 'integration.webhook_create_intent')->count());
        $this->assertSame(0, AuditEvent::where('action', 'integration.webhook_subscription_observed')->count());
    }

    public function test_duplicate_destination_and_non_https_callback_are_refused(): void
    {
        $this->hooks = [$this->hook(), $this->hook()];
        try {
            $this->ensure();
            $this->fail('Duplicate subscriptions certified.');
        } catch (ApiException $e) {
            $this->assertSame('provider_hook_duplicate', $e->errorCode);
        }
        $this->assertSame(0, $this->mutations);
        config(['app.url' => 'http://api.shopbonus.example']);
        $count = count(Http::recorded());
        try {
            $this->ensure();
            $this->fail('HTTP callback allowed.');
        } catch (ApiException $e) {
            $this->assertSame('webhook_callback_invalid', $e->errorCode);
        }
        $this->assertSame($count, count(Http::recorded()));
    }

    public function test_review_without_outstanding_intent_is_refused_before_mutation(): void
    {
        try {
            $this->ensure(999);
            $this->fail('Unmatched review accepted.');
        } catch (ApiException $e) {
            $this->assertSame('webhook_intent_review_invalid', $e->errorCode);
        }
        $this->assertSame(0, $this->mutations);
        $this->assertSame(0, AuditEvent::where('action', 'integration.webhook_create_intent')->count());
    }

    public function test_operator_command_validates_input_and_queues_or_runs_explicitly(): void
    {
        $id = $this->c->public_id;
        $this->artisan('shopbonus:provision-shopify-webhooks', ['connection' => 'not-a-ulid'])->assertExitCode(2);
        $this->artisan('shopbonus:provision-shopify-webhooks', ['connection' => $id, '--topic' => 'PRODUCTS_CREATE'])->assertExitCode(2);
        $this->artisan('shopbonus:provision-shopify-webhooks', ['connection' => $id, '--review-intent' => '1'])->assertExitCode(2);
        // phpunit uses the sync queue: queued mode is refused rather than blocking on provider calls.
        $this->artisan('shopbonus:provision-shopify-webhooks', ['connection' => $id])->assertExitCode(1);
        $this->assertSame(0, $this->mutations);

        $this->artisan('shopbonus:provision-shopify-webhooks', ['connection' => $id, '--topic' => 'ORDERS_CREATE', '--inline' => true])->assertExitCode(0);
        $this->assertSame(1, $this->mutations);

        config(['queue.default' => 'database']);
        Bus::fake([ProvisionShopifyWebhook::class]);
        $this->artisan('shopbonus:provision-shopify-webhooks', ['connection' => $id])->assertExitCode(0);
        Bus::assertDispatchedTimes(ProvisionShopifyWebhook::class, 7);

        config(['integrations.shopify_webhooks.enabled' => false]);
        $this->artisan('shopbonus:provision-shopify-webhooks', ['connection' => $id, '--inline' => true])->assertExitCode(1);
        $this->assertSame(1, $this->mutations);
    }
}
