<?php

namespace Tests\Feature\Integrations;

use App\Enums\EcommercePlatform;
use App\Exceptions\ApiException;
use App\Integrations\ConnectionManager;
use App\Integrations\Connectors\WixConnector;
use App\Models\User;
use App\Models\Webshop;
use App\Models\WebshopConnection;
use App\Services\Onboarding\Network\HostResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WixDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    private WebshopConnection $c;

    private string $mode = 'ok';

    protected function setUp(): void
    {
        parent::setUp();
        $u = User::factory()->create();
        $shop = Webshop::factory()->withMember($u)->create(['created_by' => $u->id, 'verified_domain' => 'fietsen-utrecht.nl', 'ownership_verified_at' => now(), 'ownership_method' => 'dns_txt']);
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        $public = openssl_pkey_get_details($key)['key'];
        [$this->c] = app(ConnectionManager::class)->create($shop->memberships()->sole(), EcommercePlatform::Wix, ['instance_id' => '11111111-1111-4111-8111-111111111111', 'site_id' => '22222222-2222-4222-8222-222222222222', 'access_token' => 'SYNTHETIC_app_token', 'public_key' => $public]);
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
            if (str_contains($r->url(), '/apps/v1/instance')) {
                return Http::response(['instance' => ['instanceId' => $this->mode === 'wrong_store' ? 'wrong' : $this->c->external_shop_ref], 'site' => ['siteId' => $this->c->credential('site_id'), 'url' => 'https://fietsen-utrecht.nl']]);
            }
            $row = ['id' => '33333333-3333-4333-8333-333333333333', 'status' => 'PENDING', 'updatedDate' => $this->mode === 'outside' ? '2026-10-26T10:00:00Z' : '2026-10-28T10:00:00Z'];

            return Http::response(['orders' => [$row], 'metadata' => ['count' => $this->mode === 'count' ? 2 : 1, 'cursors' => ['next' => isset($r['search']['cursorPaging']['cursor']) ? null : 'SYNTHETIC_CURSOR']]]);
        });
    }

    private function discover(?string $cursor = null): array
    {
        return app(WixConnector::class)->discoverOrders($this->c, CarbonImmutable::parse('2026-10-27T00:00:00Z'), CarbonImmutable::parse('2026-10-28T12:00:00Z'), $cursor);
    }

    public function test_fixed_bounded_native_search_explicitly_includes_pending_and_rejected_and_preserves_cursor(): void
    {
        $page = $this->discover();
        $this->assertSame('SYNTHETIC_CURSOR', $page['next']);
        $this->assertCount(1, $page['ids']);
        $this->assertNull($this->discover($page['next'])['next']);
        Http::assertSent(fn ($r) => $r->url() === 'https://www.wixapis.com/ecom/v1/orders/search' && $r->method() === 'POST' && $r['search']['cursorPaging'] === ['limit' => 25] && $r['search']['filter']['status']['$in'] === ['APPROVED', 'CANCELED', 'PENDING', 'REJECTED'] && $r['search']['filter']['updatedDate']['$lte'] === '2026-10-28T12:00:00+00:00');
        // Wix: cursor cannot be combined with filter or sort.
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/orders/search') && $r['search'] === ['cursorPaging' => ['limit' => 25, 'cursor' => 'SYNTHETIC_CURSOR']]);
        $this->assertDatabaseCount('webshop_orders', 0);
    }

    public function test_identity_failure_precedes_search(): void
    {
        $this->mode = 'wrong_store';
        try {
            $this->discover();
            $this->fail('Wrong store accepted.');
        } catch (ApiException $e) {
            $this->assertSame('provider_store_mismatch', $e->errorCode);
        }Http::assertSentCount(1);
    }

    public function test_invalid_page_count_and_out_of_window_rows_fail_closed(): void
    {
        foreach (['count' => 'provider_pagination_invalid', 'outside' => 'provider_order_invalid'] as $mode => $code) {
            $this->mode = $mode;
            try {
                $this->discover();
                $this->fail('Invalid native result accepted.');
            } catch (ApiException $e) {
                $this->assertSame($code, $e->errorCode);
            }
        }
    }
}
