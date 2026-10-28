<?php

namespace Tests\Feature\Integrations;

use App\Enums\ConnectionStatus;
use App\Enums\WebhookEventStatus;
use App\Exceptions\ApiException;
use App\Integrations\ConnectionManager;
use App\Integrations\ConnectionReadiness;
use App\Integrations\OrderRecovery;
use App\Jobs\ProcessWebhookEvent;
use App\Jobs\RecoverProviderOrder;
use App\Models\IntegrationOrderRecovery;
use App\Models\OrderEvent;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Models\Webshop;
use App\Models\WebshopConnection;
use App\Models\WebshopOrder;
use App\Services\Onboarding\Network\HostResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SignsWebhooks;
use Tests\TestCase;

class OrderRecoveryTest extends TestCase
{
    use RefreshDatabase, SignsWebhooks;

    private WebshopConnection $connection;

    private Webshop $shop;

    /** @var array<string,mixed> */
    private array $payload;

    /** @var array<string,mixed>|null */
    private ?array $shopifyPayload = null;

    private bool $refunded = false;

    private int $httpStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();
        $u = User::factory()->create();
        $this->shop = Webshop::factory()->withMember($u)->create(['created_by' => $u->id, 'verified_domain' => 'fietsen-utrecht.nl',
            'ownership_verified_at' => now(), 'ownership_method' => 'dns_txt']);
        Sanctum::actingAs($u);
        $response = $this->postJson('/api/v1/workspaces/'.$this->shop->public_id.'/connections', ['platform' => 'woocommerce',
            'store_url' => 'https://fietsen-utrecht.nl', 'consumer_key' => 'ck_SYNTHETIC_read_key', 'consumer_secret' => 'cs_SYNTHETIC_read_secret'])->assertCreated();
        $this->connection = WebshopConnection::where('public_id', $response->json('data.id'))->sole();
        $this->payload = $this->wooOrder();
        $this->app->bind(HostResolver::class, fn () => new class implements HostResolver
        {
            public function addresses(string $host): array
            {
                return ['93.184.216.34'];
            }

            public function txtRecords(string $host): array
            {
                return [];
            }
        });
        Bus::fake([RecoverProviderOrder::class, ProcessWebhookEvent::class]);
        Http::fake(function (Request $r) {
            if ($r->hasHeader('X-Shopify-Access-Token')) {
                $data = str_contains($r['query'], 'ShopbonusIdentity')
                    ? ['shop' => ['id' => 'gid://shopify/Shop/1', 'myshopifyDomain' => 'fietsen.myshopify.com', 'primaryDomain' => ['host' => 'fietsen-utrecht.nl']],
                        'currentAppInstallation' => ['accessScopes' => [['handle' => 'read_orders']]]]
                    : ['order' => $this->shopifyPayload];

                return Http::response(['data' => $data], 200, ['X-Shopify-API-Version' => '2026-10']);
            }

            return Http::response($this->httpStatus !== 200 ? ['message' => 'PRIVATE provider error']
                : (str_contains($r->url(), '/refunds') ? ($this->refunded ? [['id' => 1, 'amount' => '10.00']] : []) : $this->payload), $this->httpStatus);
        });
    }

    private function request(?string $key = null): IntegrationOrderRecovery
    {
        return app(OrderRecovery::class)->request($this->connection, '4711', $key ?? (string) Str::uuid());
    }

    private function performRecovery(IntegrationOrderRecovery $r, bool $process = true): void
    {
        app(OrderRecovery::class)->run($r->id);
        $r->refresh();
        if ($process && $r->webhook_event_id !== null) {
            $this->app->call([new ProcessWebhookEvent($r->webhook_event_id), 'handle']);
        }
        $r->refresh();
    }

    public function test_only_native_order_replay_and_real_change_recovery_satisfy_all_seven_gates(): void
    {
        $first = $this->request();
        $this->performRecovery($first);
        $this->assertSame('succeeded', $first->status);
        $gates = app(ConnectionReadiness::class)->gates($this->connection->fresh());
        $this->assertTrue($gates['authorization_verified']);
        $this->assertTrue($gates['payment_verified']);
        $this->assertFalse($gates['duplicate_processing_prevented']);
        $this->assertFalse($gates['changes_refunds_recoverable']);
        $this->assertSame(ConnectionStatus::Limited, $this->connection->fresh()->status);
        $this->performRecovery($this->request());
        $this->assertSame(1, WebhookEvent::count());
        $this->assertSame(1, OrderEvent::count());
        $this->assertSame(1, WebshopOrder::count());
        $this->payload['date_modified_gmt'] = '2026-10-23T11:00:00';
        $this->refunded = true;
        $this->performRecovery($this->request());
        $this->assertSame(1000, WebshopOrder::sole()->refunded_minor);
        $this->assertSame(ConnectionStatus::Limited, $this->connection->fresh()->status);
        $this->performRecovery($this->request());
        $this->assertNotContains(false, app(ConnectionReadiness::class)->gates($this->connection->fresh()));
        $this->assertSame(ConnectionStatus::Connected, $this->connection->fresh()->status);
        $this->assertSame(2, WebhookEvent::count());
        $this->assertSame(2, OrderEvent::count());
        app(ConnectionManager::class)->replaceCredentials($this->connection, []);
        $this->assertSame(ConnectionStatus::SetupRequired, $this->connection->fresh()->status);
        $this->assertNotContains(true, app(ConnectionReadiness::class)->gates($this->connection->fresh()));
        $this->assertSame(2, OrderEvent::count());
    }

    public function test_request_uuid_replay_is_stable_and_payload_conflict_is_rejected(): void
    {
        $key = (string) Str::uuid();
        $r = $this->request($key);
        $this->assertSame($r->id, $this->request($key)->id);
        Bus::assertDispatchedTimes(RecoverProviderOrder::class, 1);
        try {
            app(OrderRecovery::class)->request($this->connection, '4712', $key);
            $this->fail('Request identity accepted another order.');
        } catch (ApiException $e) {
            $this->assertSame('recovery_request_conflict', $e->errorCode);
        }
        Http::assertNothingSent();
    }

    public function test_rotation_before_read_supersedes_request_without_provider_or_order_effects(): void
    {
        $r = $this->request();
        app(ConnectionManager::class)->replaceCredentials($this->connection, []);
        $this->performRecovery($r);
        $this->assertSame('superseded', $r->status);
        Http::assertNothingSent();
        $this->assertSame(0, WebshopOrder::count());
        $this->assertSame(0, WebhookEvent::count());
    }

    public function test_staged_ingestion_is_durable_and_resumes_without_another_native_read(): void
    {
        $r = $this->request();
        $this->performRecovery($r, false);
        $this->assertSame('staged', $r->status);
        Http::assertSentCount(2);
        app(OrderRecovery::class)->run($r->id);
        Http::assertSentCount(2);
        $this->app->call([new ProcessWebhookEvent($r->webhook_event_id), 'handle']);
        $this->app->call([new ProcessWebhookEvent($r->webhook_event_id), 'handle']);
        $this->assertSame('succeeded', $r->fresh()->status);
        $this->assertSame(1, OrderEvent::count());
    }

    public function test_failed_native_authentication_is_explicit_and_never_leaks_provider_text(): void
    {
        $this->httpStatus = 403;
        $r = $this->request();
        $this->performRecovery($r);
        $this->assertSame('failed', $r->status);
        $this->assertSame('provider_authentication_failed', $r->last_error_code);
        $this->assertStringNotContainsString('PRIVATE', json_encode($r));
        $this->assertSame(0, WebhookEvent::count());
        $this->assertContains(false, app(ConnectionReadiness::class)->gates($this->connection->fresh()));
    }

    public function test_lease_excludes_duplicate_workers_and_expired_attempt_can_resume(): void
    {
        $r = $this->request();
        $r->forceFill(['status' => 'reading', 'attempts' => 1, 'lease_token' => (string) Str::uuid(), 'leased_until' => now()->addSeconds(60)])->save();
        $this->performRecovery($r);
        Http::assertNothingSent();
        $this->travel(61)->seconds();
        $this->performRecovery($r);
        $this->assertSame('succeeded', $r->status);
        $this->assertSame(2, $r->attempts);
        Http::globalRequestMiddleware(function ($request) {
            $this->travel(61)->seconds();

            return $request;
        });
        $expired = $this->request();
        $this->performRecovery($expired);
        $this->assertSame('reading', $expired->status);
        $this->assertNull($expired->webhook_event_id);
        $this->assertSame(1, WebhookEvent::count());
    }

    public function test_domain_change_after_staging_prevents_ingestion_and_readiness(): void
    {
        $r = $this->request();
        $this->performRecovery($r, false);
        $this->shop->forceFill(['verified_domain' => 'another-store.nl'])->save();
        $this->app->call([new ProcessWebhookEvent($r->webhook_event_id), 'handle']);
        $this->assertSame(WebhookEventStatus::Ignored, WebhookEvent::sole()->status);
        $this->assertSame('superseded', $r->fresh()->status);
        $this->assertSame(0, WebshopOrder::count());
        $this->assertContains(false, app(ConnectionReadiness::class)->gates($this->connection->fresh()));
    }

    public function test_protected_or_unpaid_native_data_cannot_pass_mapping_or_payment_gate(): void
    {
        $this->payload['billing']['email'] = null;
        $this->payload['status'] = 'on-hold';
        $this->payload['date_paid_gmt'] = null;
        $this->performRecovery($this->request());
        $gates = app(ConnectionReadiness::class)->gates($this->connection->fresh());
        $this->assertFalse($gates['mandatory_fields_mapped']);
        $this->assertFalse($gates['payment_verified']);
        $this->assertSame(ConnectionStatus::Limited, $this->connection->fresh()->status);
    }

    public function test_shopify_native_recovery_uses_real_transactions_and_same_gate_pipeline(): void
    {
        $response = $this->postJson('/api/v1/workspaces/'.$this->shop->public_id.'/connections', ['platform' => 'shopify',
            'shop_domain' => 'fietsen.myshopify.com', 'signing_secret' => 'SYNTHETIC_signing_secret', 'access_token' => 'shpat_SYNTHETIC_access_token'])->assertCreated();
        $this->connection = WebshopConnection::where('public_id', $response->json('data.id'))->sole();
        $this->shopifyPayload = ['id' => 'gid://shopify/Order/4711', 'legacyResourceId' => '4711', 'name' => '#4711',
            'email' => 'sample@example.test', 'phone' => null, 'billingAddress' => ['firstName' => 'Sample', 'phone' => null],
            'createdAt' => '2026-10-28T10:00:00Z', 'updatedAt' => '2026-10-28T10:01:00Z', 'cancelledAt' => null,
            'displayFinancialStatus' => 'PAID', 'fullyPaid' => true,
            'totalPriceSet' => ['shopMoney' => ['amount' => '59.95', 'currencyCode' => 'EUR']],
            'currentTotalPriceSet' => ['shopMoney' => ['amount' => '59.95', 'currencyCode' => 'EUR']],
            'totalRefundedSet' => ['shopMoney' => ['amount' => '0.00', 'currencyCode' => 'EUR']],
            'discountCodes' => [], 'customAttributes' => [], 'transactions' => [['kind' => 'SALE', 'status' => 'SUCCESS', 'processedAt' => '2026-10-28T10:01:00Z']]];
        $this->performRecovery($this->request());
        $this->performRecovery($this->request());
        $this->shopifyPayload['totalRefundedSet']['shopMoney']['amount'] = '10.00';
        $this->shopifyPayload['currentTotalPriceSet']['shopMoney']['amount'] = '49.95';
        $this->shopifyPayload['displayFinancialStatus'] = 'PARTIALLY_REFUNDED';
        $this->shopifyPayload['updatedAt'] = '2026-10-28T11:00:00Z';
        $this->shopifyPayload['transactions'][] = ['kind' => 'REFUND', 'status' => 'SUCCESS', 'processedAt' => '2026-10-28T11:00:00Z'];
        $this->performRecovery($this->request());
        $this->performRecovery($this->request());
        $this->assertNotContains(false, app(ConnectionReadiness::class)->gates($this->connection->fresh()));
        $this->assertSame(ConnectionStatus::Connected, $this->connection->fresh()->status);
        $order = WebshopOrder::sole();
        $this->assertSame(5995, $order->amount_minor);
        $this->assertSame(1000, $order->refunded_minor);
        $this->assertSame(2, OrderEvent::count());
    }

    public function test_processing_failure_closes_staged_request_instead_of_endless_requeue(): void
    {
        $r = $this->request();
        $this->performRecovery($r, false);
        (new ProcessWebhookEvent($r->webhook_event_id))->failed(new \RuntimeException('Synthetic internal failure'));
        $this->assertSame('failed', $r->fresh()->status);
        app(OrderRecovery::class)->run($r->id);
        $this->assertSame(0, WebshopOrder::count());
        Http::assertSentCount(2);
    }

    public function test_attempt_budget_and_operator_drain_are_bounded_without_new_store_discovery(): void
    {
        $r = $this->request();
        $r->forceFill(['status' => 'reading', 'attempts' => 3, 'lease_token' => (string) Str::uuid(), 'leased_until' => now()->subSecond()])->save();
        $this->performRecovery($r);
        $this->assertSame('failed', $r->status);
        $this->assertSame('provider_attempts_exhausted', $r->last_error_code);
        Http::assertNothingSent();
        $this->artisan('shopbonus:recover-integration-order', ['--drain' => true, '--limit' => '101'])->assertExitCode(2);
        $this->artisan('shopbonus:recover-integration-order', ['--drain' => true, '--limit' => '1'])->assertSuccessful();
    }

    public function test_exact_store_reference_or_unknown_legacy_reference_cannot_reuse_staged_proof(): void
    {
        $r = $this->request();
        $this->assertSame('https://fietsen-utrecht.nl', $r->provider_store_ref);
        $this->performRecovery($r, false);
        $this->connection->forceFill(['external_shop_ref' => 'https://fietsen-utrecht.nl/another-installation'])->save();
        $this->app->call([new ProcessWebhookEvent($r->webhook_event_id), 'handle']);
        $this->assertSame('superseded', $r->fresh()->status);
        $this->assertSame(0, WebshopOrder::count());
        $this->connection->forceFill(['external_shop_ref' => 'https://fietsen-utrecht.nl'])->save();
        $legacy = $this->request();
        $legacy->forceFill(['provider_store_ref' => null])->save();
        $this->performRecovery($legacy);
        $this->assertSame('superseded', $legacy->status);
        $this->assertSame(0, WebshopOrder::count());
    }

    public function test_store_switch_cannot_silently_merge_another_store_order_namespace(): void
    {
        $this->performRecovery($this->request());
        $credentials = ['store_url' => 'https://fietsen-utrecht.nl/other-store',
            'consumer_key' => 'ck_SYNTHETIC_read_key', 'consumer_secret' => 'cs_SYNTHETIC_read_secret'];
        $this->postJson('/api/v1/workspaces/'.$this->shop->public_id.'/connections/'.$this->connection->public_id.'/credentials', $credentials)
            ->assertConflict()->assertJsonPath('error.code', 'provider_store_change_requires_review');
        $this->assertSame('https://fietsen-utrecht.nl', $this->connection->fresh()->external_shop_ref);
        app(ConnectionManager::class)->disconnect($this->connection);
        $response = $this->postJson('/api/v1/workspaces/'.$this->shop->public_id.'/connections', ['platform' => 'woocommerce'] + $credentials)->assertCreated();
        $this->connection = WebshopConnection::where('public_id', $response->json('data.id'))->sole();
        try {
            $this->request();
            $this->fail('A different store reused the original canonical order namespace.');
        } catch (ApiException $e) {
            $this->assertSame('provider_store_change_requires_review', $e->errorCode);
        }
        $this->assertSame(1, WebshopOrder::count());
    }
}
