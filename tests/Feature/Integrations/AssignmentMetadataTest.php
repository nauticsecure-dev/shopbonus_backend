<?php

namespace Tests\Feature\Integrations;

use App\Integrations\AssignmentMetadata;
use App\Integrations\Connectors\BigCommerceConnector;
use App\Integrations\Connectors\EcwidConnector;
use App\Integrations\Connectors\LightspeedEConnector;
use App\Integrations\Connectors\MagentoConnector;
use App\Integrations\Connectors\ShopifyConnector;
use App\Integrations\Connectors\ShopwareConnector;
use App\Integrations\Connectors\WixConnector;
use App\Models\Webshop;
use App\Models\WebshopConnection;
use App\Services\Onboarding\Network\HostResolver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AssignmentMetadataTest extends TestCase
{
    private function token(): string
    {
        return 'bx_'.str_repeat('a', 64);
    }

    public function test_documented_native_containers_map_only_the_reserved_token_and_never_change_payment_proof(): void
    {
        $token = $this->token();
        foreach ([
            [new ShopwareConnector, ['shopware_order' => ['id' => str_repeat('a', 32), 'currency' => ['isoCode' => 'EUR'], 'amountTotal' => '10.00', 'customFields' => ['shopbonus_assignment_token' => $token]]]],
            [new MagentoConnector, ['magento_order' => ['entity_id' => 42, 'order_currency_code' => 'EUR', 'grand_total' => '10.00', 'extension_attributes' => ['shopbonus_assignment_token' => $token]]]],
            [new EcwidConnector, ['native_order' => ['internalId' => 42, 'extraFields' => ['shopbonus_assignment_token' => $token]], 'shop_currency' => 'EUR']],
            [new LightspeedEConnector, ['native_order' => ['internalId' => 42, 'extraFields' => ['shopbonus_assignment_token' => $token]], 'shop_currency' => 'EUR']],
            [new WixConnector, ['order' => ['id' => 'synthetic', 'currency' => 'EUR', 'customFields' => [['title' => 'shopbonus_assignment_token', 'value' => $token]]]]],
            [new BigCommerceConnector, ['order' => ['id' => 250], 'assignment_metafields' => [['namespace' => 'shopbonus', 'key' => 'assignment_token', 'value' => $token, 'resource_id' => 250, 'resource_type' => 'order']]]],
        ] as [$connector, $payload]) {
            $normalized = $connector->normalizeOrder($payload);
            $this->assertSame($token, $normalized->bannerAssignmentToken, $connector::class);
            $this->assertNull($normalized->paidAt);
            $this->assertNull($normalized->refundedAt);
        }
    }

    public function test_duplicates_malformed_tokens_and_conflicting_ecwid_mirrors_are_unknown(): void
    {
        $token = $this->token();
        foreach ([' '.$token, strtoupper($token), $token."\n", ['value' => $token], 123, null] as $bad) {
            $this->assertNull(AssignmentMetadata::token($bad));
        }
        $row = ['key' => AssignmentMetadata::KEY, 'value' => $token];
        $this->assertNull(AssignmentMetadata::fromRows([$row, $row], 'key'));
        $this->assertNull(AssignmentMetadata::fromRows(['not-a-list' => $row], 'key'));
        $this->assertSame($token, AssignmentMetadata::ecwid(['extraFields' => [AssignmentMetadata::KEY => $token], 'orderExtraFields' => [['id' => AssignmentMetadata::KEY, 'value' => $token]]]));
        $this->assertNull(AssignmentMetadata::ecwid(['extraFields' => [AssignmentMetadata::KEY => $token], 'orderExtraFields' => [['id' => AssignmentMetadata::KEY, 'value' => 'bx_'.str_repeat('b', 64)]]]));
        $this->assertNull(AssignmentMetadata::ecwid(['extraFields' => [AssignmentMetadata::KEY => null], 'orderExtraFields' => [['id' => AssignmentMetadata::KEY, 'value' => $token]]]));
        $this->assertSame($token, AssignmentMetadata::ecwid(['orderExtraFields' => [['id' => AssignmentMetadata::KEY, 'value' => $token]]]));
        $this->assertNull((new ShopifyConnector)->normalizeOrder(['note_attributes' => [['name' => AssignmentMetadata::KEY, 'value' => $token], ['name' => AssignmentMetadata::KEY, 'value' => $token]]])->bannerAssignmentToken);
    }

    public function test_bigcommerce_requires_exact_namespace_order_identity_and_one_value(): void
    {
        $row = ['namespace' => 'shopbonus', 'key' => 'assignment_token', 'value' => $this->token(), 'resource_id' => 250, 'resource_type' => 'order'];
        $this->assertSame($this->token(), AssignmentMetadata::bigCommerce([$row], '250'));
        foreach (['namespace' => 'other', 'resource_id' => 251, 'resource_type' => 'product'] as $key => $bad) {
            $changed = $row;
            $changed[$key] = $bad;
            $this->assertNull(AssignmentMetadata::bigCommerce([$changed], 250));
        }
        $this->assertNull(AssignmentMetadata::bigCommerce([$row, $row], 250));
    }

    public function test_optional_bigcommerce_read_uses_fixed_authenticated_scoped_metadata_query(): void
    {
        config(['integrations.assignment_metadata.bigcommerce' => true]);
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
        $shop = (new Webshop)->forceFill(['verified_domain' => 'store.example.com', 'ownership_verified_at' => now(), 'ownership_method' => 'dns_txt']);
        $connection = (new WebshopConnection(['status' => 'limited', 'external_shop_ref' => 'abc123', 'credentials' => ['access_token' => 'SYNTHETIC']]))->setRelation('webshop', $shop);
        Http::fake(function (Request $request) {
            $this->assertSame('GET', $request->method());
            $this->assertSame('SYNTHETIC', $request->header('X-Auth-Token')[0]);
            if (str_contains($request->url(), '/v2/store')) {
                return Http::response(['id' => 'abc123', 'secure_url' => 'https://store.example.com']);
            }
            if (str_contains($request->url(), '/v2/orders/')) {
                return Http::response(['id' => 250, 'default_currency_code' => 'EUR', 'currency_code' => 'EUR']);
            }
            if (str_contains($request->url(), '/metafields')) {
                parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
                $this->assertSame(['namespace' => 'shopbonus', 'key' => 'assignment_token', 'limit' => '2', 'page' => '1'], $query);

                return Http::response(['data' => [['namespace' => 'shopbonus', 'key' => 'assignment_token', 'value' => $this->token(), 'resource_id' => 250, 'resource_type' => 'order']], 'meta' => ['pagination' => ['total_pages' => 1]]]);
            }

            return Http::response(['data' => [], 'meta' => ['pagination' => ['total_pages' => 1]]]);
        });
        $connector = new BigCommerceConnector;
        $this->assertSame($this->token(), $connector->normalizeOrder($connector->fetchOrder($connection, '250'))->bannerAssignmentToken);
        Http::assertSentCount(5);
    }
}
