<?php

namespace Tests\Feature\Integrations;

use App\Exceptions\ApiException;
use App\Integrations\Connectors\ShopifyConnector;
use App\Integrations\Data\PaymentStatus;
use App\Models\User;
use App\Models\Webshop;
use App\Models\WebshopConnection;
use App\Services\Onboarding\Network\HostResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShopifyApiTest extends TestCase
{
    use RefreshDatabase;

    private Webshop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $owner = User::factory()->create();
        $this->shop = Webshop::factory()->withMember($owner)->create(['created_by' => $owner->id, 'verified_domain' => 'fietsen-utrecht.nl',
            'ownership_verified_at' => now(), 'ownership_method' => 'dns_txt']);
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
        Sanctum::actingAs($owner);
    }

    private function connection(): WebshopConnection
    {
        $response = $this->postJson('/api/v1/workspaces/'.$this->shop->public_id.'/connections', ['platform' => 'shopify',
            'shop_domain' => 'fietsen.myshopify.com', 'signing_secret' => 'SYNTHETIC_signing_secret', 'access_token' => 'shpat_SYNTHETIC_access_token'])->assertCreated();
        $response->assertDontSee('shpat_SYNTHETIC_access_token')->assertDontSee('SYNTHETIC_signing_secret');

        return WebshopConnection::where('public_id', $response->json('data.id'))->sole();
    }

    /** @return array<string,mixed> */
    private function identity(string $domain = 'fietsen-utrecht.nl', array $scopes = ['read_orders', 'read_products']): array
    {
        return ['shop' => ['id' => 'gid://shopify/Shop/1', 'myshopifyDomain' => 'fietsen.myshopify.com', 'primaryDomain' => ['host' => $domain]],
            'currentAppInstallation' => ['accessScopes' => array_map(fn ($s) => ['handle' => $s], $scopes)]];
    }

    /** @return array<string,mixed> */
    private function order(): array
    {
        return ['id' => 'gid://shopify/Order/4711', 'legacyResourceId' => '4711', 'name' => '#4711', 'email' => 'sample@example.test', 'phone' => null,
            'billingAddress' => ['firstName' => 'Sample', 'phone' => null], 'createdAt' => '2026-10-28T10:00:00Z', 'updatedAt' => '2026-10-28T10:02:00Z',
            'cancelledAt' => null, 'displayFinancialStatus' => 'PARTIALLY_REFUNDED', 'fullyPaid' => true,
            'totalPriceSet' => ['shopMoney' => ['amount' => '59.95', 'currencyCode' => 'EUR']],
            'currentTotalPriceSet' => ['shopMoney' => ['amount' => '49.95', 'currencyCode' => 'EUR']],
            'totalRefundedSet' => ['shopMoney' => ['amount' => '10.00', 'currencyCode' => 'EUR']],
            'discountCodes' => [], 'customAttributes' => [], 'transactions' => [
                ['kind' => 'SALE', 'status' => 'SUCCESS', 'processedAt' => '2026-10-28T10:01:00Z'],
                ['kind' => 'REFUND', 'status' => 'SUCCESS', 'processedAt' => '2026-10-28T10:02:00Z'],
            ]];
    }

    public function test_pinned_queries_prove_shop_scopes_before_reading_and_normalize_refunds_once(): void
    {
        $c = $this->connection();
        Http::fake(function (Request $r, array $options) {
            $this->assertSame('POST', $r->method());
            $this->assertSame('https://fietsen.myshopify.com/admin/api/2026-10/graphql.json', $r->url());
            $this->assertSame(['fietsen.myshopify.com:443:93.184.216.34'], $options['curl'][CURLOPT_RESOLVE]);
            $this->assertSame('shpat_SYNTHETIC_access_token', $r->header('X-Shopify-Access-Token')[0]);
            $this->assertFalse($options['allow_redirects']);
            $this->assertStringStartsWith('query ', $r['query']);
            $data = str_contains($r['query'], 'ShopbonusIdentity') ? $this->identity() : ['order' => $this->order()];

            return Http::response(['data' => $data], 200, ['X-Shopify-API-Version' => '2026-10']);
        });
        $connector = new ShopifyConnector;
        $order = $connector->normalizeOrder($connector->fetchOrder($c, '4711'));
        $this->assertSame([5995, 1000, 4995], [$order->amountMinor, $order->refundedMinor, $order->amountMinor - $order->refundedMinor]);
        $this->assertSame(PaymentStatus::PartiallyRefunded, $order->paymentStatus);
        $this->assertSame('2026-10-28T10:01:00+00:00', $order->paidAt->toIso8601String());
        $this->assertSame('2026-10-28T10:02:00+00:00', $order->refundedAt->toIso8601String());
        $this->assertSame('setup_required', $c->fresh()->status->value);
        Http::assertSentCount(2);
    }

    public function test_foreign_store_identity_stops_before_any_order_read(): void
    {
        $c = $this->connection();
        Http::fake(fn () => Http::response(['data' => $this->identity('unrelated.nl')], 200, ['X-Shopify-API-Version' => '2026-10']));
        try {
            (new ShopifyConnector)->fetchOrder($c, '4711');
            $this->fail('Foreign store read accepted.');
        } catch (ApiException $e) {
            $this->assertSame('store_identity_unverified', $e->errorCode);
        }
        Http::assertSentCount(1);
    }

    public function test_missing_order_scope_stops_before_order_read(): void
    {
        $c = $this->connection();
        Http::fake(fn () => Http::response(['data' => $this->identity(scopes: ['read_products'])], 200, ['X-Shopify-API-Version' => '2026-10']));
        try {
            (new ShopifyConnector)->fetchOrder($c, '4711');
            $this->fail('Missing scope accepted.');
        } catch (ApiException $e) {
            $this->assertSame('provider_scope_missing', $e->errorCode);
        }
        Http::assertSentCount(1);
    }

    public function test_protected_customer_denial_scrubs_partial_data_without_inventing_customer_fields(): void
    {
        $c = $this->connection();
        Http::fake(function (Request $r) {
            $body = ['data' => str_contains($r['query'], 'ShopbonusIdentity') ? $this->identity() : ['order' => $this->order()]];
            if (! str_contains($r['query'], 'ShopbonusIdentity')) {
                $body['errors'] = [['message' => 'PRIVATE provider message', 'extensions' => ['code' => 'ACCESS_DENIED'], 'path' => ['order', 'email']]];
            }

            return Http::response($body, 200, ['X-Shopify-API-Version' => '2026-10']);
        });
        $connector = new ShopifyConnector;
        $result = $connector->normalizeOrder($connector->fetchOrder($c, '4711'));
        $this->assertNull($result->email);
        $this->assertFalse($result->isComplete());
        $this->assertContains('email', $result->missingFields());
        $this->assertSame(5995, $result->amountMinor);
    }

    public function test_http200_throttle_pauses_further_requests_and_does_not_echo_provider_messages(): void
    {
        $c = $this->connection();
        Http::fake(fn () => Http::response(['errors' => [['message' => 'SECRET token detail', 'extensions' => ['code' => 'THROTTLED']]]], 200, ['X-Shopify-API-Version' => '2026-10']));
        foreach (['provider_rate_limited', 'provider_circuit_open'] as $expected) {
            try {
                (new ShopifyConnector)->testConnection($c);
                $this->fail('Throttled query passed.');
            } catch (ApiException $e) {
                $this->assertSame($expected, $e->errorCode);
                $this->assertStringNotContainsString('SECRET', $e->getMessage());
            }
        }
        Http::assertSentCount(1);
    }

    public function test_provider_version_fallback_never_counts_as_the_reviewed_version(): void
    {
        $c = $this->connection();
        Http::fake(fn () => Http::response(['data' => $this->identity()], 200, ['X-Shopify-API-Version' => '2026-07']));
        try {
            (new ShopifyConnector)->testConnection($c);
            $this->fail('Wrong version passed.');
        } catch (ApiException $e) {
            $this->assertSame('provider_version_mismatch', $e->errorCode);
        }
    }

    public function test_order_creation_or_authorization_does_not_invent_a_payment_timestamp(): void
    {
        $raw = $this->order();
        $raw['displayFinancialStatus'] = 'AUTHORIZED';
        $raw['fullyPaid'] = false;
        $raw['transactions'] = [['kind' => 'AUTHORIZATION', 'status' => 'SUCCESS', 'processedAt' => '2026-10-28T10:01:00Z']];
        $order = (new ShopifyConnector)->normalizeOrder($raw);
        $this->assertSame(PaymentStatus::Pending, $order->paymentStatus);
        $this->assertNull($order->paidAt);
    }

    public function test_cursor_pages_are_bounded_and_duplicate_cursor_fails_instead_of_looping(): void
    {
        $c = $this->connection();
        Http::fake(function (Request $r) {
            $data = str_contains($r['query'], 'ShopbonusIdentity') ? $this->identity()
                : ['orders' => ['nodes' => [$this->order()], 'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'same-cursor']]];

            return Http::response(['data' => $data], 200, ['X-Shopify-API-Version' => '2026-10']);
        });
        try {
            iterator_to_array((new ShopifyConnector)->syncOrders($c, now()->subHour()));
            $this->fail('Repeated cursor accepted.');
        } catch (ApiException $e) {
            $this->assertSame('provider_pagination_invalid', $e->errorCode);
        }
        Http::assertSentCount(3);
    }

    public function test_schema_or_money_errors_are_never_ignored_as_customer_redaction(): void
    {
        $c = $this->connection();
        Http::fake(function (Request $r) {
            return Http::response(str_contains($r['query'], 'ShopbonusIdentity') ? ['data' => $this->identity()]
                : ['data' => ['order' => $this->order()], 'errors' => [['message' => 'SECRET', 'extensions' => ['code' => 'ACCESS_DENIED'], 'path' => ['order', 'totalPriceSet']]]],
                200, ['X-Shopify-API-Version' => '2026-10']);
        });
        try {
            (new ShopifyConnector)->fetchOrder($c, '4711');
            $this->fail('Denied money fields accepted.');
        } catch (ApiException $e) {
            $this->assertSame('provider_graphql_error', $e->errorCode);
        }
    }

    public function test_polled_orders_reject_mismatched_identity_currency_and_excess_refunds(): void
    {
        $c = $this->connection();
        $variants = [
            ['legacyResourceId' => '9999'],
            ['totalRefundedSet' => ['shopMoney' => ['amount' => '10.00', 'currencyCode' => 'USD']]],
            ['totalRefundedSet' => ['shopMoney' => ['amount' => '60.00', 'currencyCode' => 'EUR']]],
        ];
        foreach ($variants as $change) {
            Http::fake(function (Request $r) use ($change) {
                $data = str_contains($r['query'], 'ShopbonusIdentity') ? $this->identity()
                    : ['orders' => ['nodes' => [array_replace($this->order(), $change)], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]];

                return Http::response(['data' => $data], 200, ['X-Shopify-API-Version' => '2026-10']);
            });
            try {
                iterator_to_array((new ShopifyConnector)->syncOrders($c, now()->subHour()));
                $this->fail('Invalid native order accepted.');
            } catch (ApiException $e) {
                $this->assertSame('provider_order_invalid', $e->errorCode);
                $this->assertSame(502, $e->status);
            }
        }
    }

    public function test_transaction_lookahead_rejects_history_larger_than_safe_bound(): void
    {
        $c = $this->connection();
        Http::fake(function (Request $r) {
            if (str_contains($r['query'], 'ShopbonusIdentity')) {
                $data = $this->identity();
            } else {
                $this->assertStringContainsString('transactions(first: 101)', $r['query']);
                $order = $this->order();
                $order['transactions'] = array_fill(0, 101, $order['transactions'][0]);
                $data = ['order' => $order];
            }

            return Http::response(['data' => $data], 200, ['X-Shopify-API-Version' => '2026-10']);
        });
        try {
            (new ShopifyConnector)->fetchOrder($c, '4711');
            $this->fail('Truncated transaction history accepted.');
        } catch (ApiException $e) {
            $this->assertSame('provider_pagination_limit', $e->errorCode);
        }
    }

    public function test_successful_reads_reset_the_consecutive_transport_failure_count(): void
    {
        $c = $this->connection();
        $sequence = Http::sequence();
        for ($i = 0; $i < 3; $i++) {
            $sequence->push(['error' => 'Synthetic unavailable'], 503)
                ->push(['data' => $this->identity()], 200, ['X-Shopify-API-Version' => '2026-10']);
        }
        Http::fake(['*' => $sequence]);
        for ($i = 0; $i < 3; $i++) {
            try {
                (new ShopifyConnector)->testConnection($c);
                $this->fail('Unavailable provider accepted.');
            } catch (ApiException $e) {
                $this->assertSame('provider_read_failed', $e->errorCode);
            }
            $this->assertTrue((new ShopifyConnector)->testConnection($c)['ok']);
        }
        Http::assertSentCount(6);
    }
}
