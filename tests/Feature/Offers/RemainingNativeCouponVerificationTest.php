<?php

namespace Tests\Feature\Offers;

use App\Enums\EcommercePlatform;
use App\Enums\OfferAcquisition;
use App\Integrations\ConnectionManager;
use App\Models\User;
use App\Models\VoucherCode;
use App\Models\Webshop;
use App\Models\WebshopConnection;
use App\Services\Offers\OfferService;
use App\Services\Onboarding\Network\HostResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesActiveWebshops;
use Tests\Support\NativeCouponFixture;
use Tests\TestCase;

class RemainingNativeCouponVerificationTest extends TestCase
{
    use CreatesActiveWebshops, RefreshDatabase;

    private string $platform;

    private ?array $native;

    private string $currency = 'EUR';

    private bool $authorized = true;

    private bool $wrongStore = false;

    private Webshop $shop;

    private User $owner;

    private WebshopConnection $connection;

    private VoucherCode $code;

    private string $url;

    private function fixture(string $platform): void
    {
        $this->platform = $platform;
        $this->native = NativeCouponFixture::coupon($platform);
        $this->owner = User::factory()->create();
        $this->shop = $this->activeWebshop($this->owner, ['verified_domain' => 'shop.example.com']);
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
        $input = match ($platform) {
            'opencart' => ['store_url' => 'https://shop.example.com', 'timezone' => 'Europe/Amsterdam'],
            'prestashop' => ['store_url' => 'https://shop.example.com', 'shop_id' => '1', 'webservice_key' => 'SYNTHETIC_prestashop_key', 'timezone' => 'Europe/Amsterdam'],
            'ccv' => ['store_url' => 'https://shop.example.com', 'webshop_id' => '1', 'api_key' => 'SYNTHETIC_ccv_key', 'api_secret' => 'SYNTHETIC_ccv_secret'],
            'shopware6' => ['store_url' => 'https://shop.example.com', 'sales_channel_id' => str_repeat('b', 32), 'client_id' => 'SYNTHETIC_shopware_client', 'client_secret' => 'SYNTHETIC_shopware_secret'],
            'squarespace' => ['website_id' => '111111111111111111111111', 'api_key' => 'SYNTHETIC_squarespace_key'],
            'magento' => ['store_url' => 'https://shop.example.com', 'store_code' => 'default', 'store_id' => '1', 'access_token' => 'SYNTHETIC_magento_token'],
            'shopify' => ['shop_domain' => 'synthetic.myshopify.com', 'signing_secret' => 'SYNTHETIC_signing_secret', 'access_token' => 'shpat_SYNTHETIC_token'],
            'bigcommerce' => ['store_hash' => 'abc123', 'access_token' => 'SYNTHETIC_bc_token', 'client_secret' => 'SYNTHETIC_bc_secret'],
            'wix' => ['instance_id' => NativeCouponFixture::WIX_INSTANCE, 'site_id' => NativeCouponFixture::WIX_SITE,
                'access_token' => 'SYNTHETIC_wix_token', 'public_key' => file_get_contents(base_path('tests/fixtures/rsa_public.pem'))],
            default => ['store_id' => '1003', 'access_token' => 'SYNTHETIC_ecwid_token', 'client_secret' => 'SYNTHETIC_ecwid_secret'],
        };
        [$this->connection] = app(ConnectionManager::class)->create($this->shop->memberships()->sole(), EcommercePlatform::from($platform), $input);
        $offer = app(OfferService::class)->create($this->shop->memberships()->sole(), OfferAcquisition::MerchantReward,
            ['name' => 'Synthetic coupon', 'discount_type' => 'percentage', 'discount_value' => 1000, 'currency' => 'EUR', 'min_order_minor' => $platform === 'shopware6' ? 0 : 10000,
                'max_discount_minor' => null, 'redemption_validity_days' => 30, 'combinable' => false]);
        $this->code = VoucherCode::create(['webshop_id' => $this->shop->id, 'offer_id' => $offer->id, 'code' => 'SYNTHETIC-10',
            'code_hash' => VoucherCode::hashFor($this->shop->id, 'SYNTHETIC-10'), 'code_last4' => 'C-10']);
        $this->url = "/api/v1/workspaces/{$this->shop->public_id}/codes/{$this->code->public_id}/platform-validation";
        Sanctum::actingAs($this->owner);
        Http::fake(fn (Request $r) => NativeCouponFixture::response($r, $this->platform, $this->native, $this->currency, $this->authorized, $this->wrongStore, $this->connection->credential('signing_secret')));
    }

    public static function platforms(): array
    {
        return [['opencart'], ['prestashop'], ['ccv']];
    }

    #[DataProvider('platforms')]
    public function test_native_configuration_is_verified_without_checkout_or_financial_claims(string $platform): void
    {
        $this->fixture($platform);
        $this->postJson($this->url, ['mode' => 'provider_check'])->assertOk()->assertJsonPath('data.platform_validation', $platform === 'ccv' ? 'merchant_verification_required' : 'verified')
            ->assertJsonPath('verification.detail.evidence_scope', 'native_configuration_not_checkout_or_redemption');
        $this->assertDatabaseHas('voucher_events', ['voucher_code_id' => $this->code->id, 'type' => 'platform_validation_provider_check', 'to_status' => $platform === 'ccv' ? 'merchant_verification_required' : 'verified']);
        $this->assertDatabaseCount('vouchers', 0);
        Http::assertNotSent(fn (Request $r) => $r->method() !== 'GET'
            && ! str_starts_with(is_string($r['query'] ?? null) ? $r['query'] : '', 'query ')
            && ! str_ends_with($r->url(), '/stores/v2/coupons/query') && ! str_contains($r->url(), '/api/search/') && ! str_ends_with($r->url(), '/api/oauth/token'));
    }

    #[DataProvider('platforms')]
    public function test_wrong_store_and_revoked_scopes_never_verify(string $platform): void
    {
        $this->fixture($platform);
        $this->wrongStore = true;
        $this->postJson($this->url, ['mode' => 'provider_check'])->assertOk()->assertJsonPath('data.platform_validation', 'sync_error');
        $this->wrongStore = false;
        $this->authorized = false;
        $this->postJson($this->url, ['mode' => 'provider_check'])->assertOk()->assertJsonPath('data.platform_validation', 'sync_error');
    }

    #[DataProvider('platforms')]
    public function test_not_found_currency_mismatch_and_pause_are_explicit(string $platform): void
    {
        $this->fixture($platform);
        $native = $this->native;
        $this->native = null;
        $this->postJson($this->url, ['mode' => 'provider_check'])->assertOk()->assertJsonPath('data.platform_validation', 'merchant_verification_required');
        $this->native = $native;
        $this->currency = 'USD';
        $this->postJson($this->url, ['mode' => 'provider_check'])->assertOk()->assertJsonPath('data.platform_validation', 'mismatch');
        $this->connection->forceFill(['paused_at' => now()])->save();
        $this->postJson($this->url, ['mode' => 'provider_check'])->assertOk()->assertJsonPath('data.platform_validation', 'sync_error');
    }

    public function test_opencart_signed_tampered_replayed_and_wrong_context_reads_fail_closed(): void
    {
        $this->fixture('opencart');
        foreach (['nonce', 'observed_at', 'timezone', 'signature'] as $field) {
            Http::swap(new Factory);
            Http::fake(function (Request $request) use ($field) {
                $secret = $this->connection->credential('signing_secret');
                $payload = json_encode(['schema' => 'shopbonus.opencart.coupon.v1', 'nonce' => $field === 'nonce' ? str_repeat('0', 64) : $request->header('X-Shopbonus-Nonce')[0],
                    'observed_at' => now()->getTimestamp() - ($field === 'observed_at' ? 61 : 0), 'store_id' => 0, 'store_url' => 'https://shop.example.com',
                    'timezone' => $field === 'timezone' ? 'UTC' : 'Europe/Amsterdam', 'currency' => 'EUR', 'enabled_totals' => ['opencart:coupon'], 'coupon' => $this->native]);

                return Http::response(['payload' => $payload, 'signature' => $field === 'signature' ? str_repeat('0', 64) : hash_hmac('sha256', 'shopbonus.opencart.coupon.v1.response|'.$payload, $secret)]);
            });
            $this->postJson($this->url, ['mode' => 'provider_check'])->assertOk()->assertJsonPath('data.platform_validation', 'sync_error');
        }
    }

    #[DataProvider('platforms')]
    public function test_known_term_differences_and_malformed_dates_never_match(string $platform): void
    {
        $this->fixture($platform);
        $field = match ($platform) {
            'opencart' => 'discount', 'prestashop' => 'reduction_percent', default => 'discount'
        };
        $this->native[$field] = '11.00';
        $this->postJson($this->url, ['mode' => 'provider_check'])->assertOk()->assertJsonPath('data.platform_validation', 'mismatch');
        $this->native = NativeCouponFixture::coupon($platform);
        $field = match ($platform) {
            'opencart' => 'expires_at', 'prestashop' => 'date_to', default => 'enddate'
        };
        $this->native[$field] = '2026-02-30';
        $this->postJson($this->url, ['mode' => 'provider_check'])->assertOk()->assertJsonPath('data.platform_validation', 'sync_error');
    }

    public function test_prestashop_does_not_invent_original_capacity_or_usage(): void
    {
        $this->fixture('prestashop');
        Http::swap(new Factory);
        Http::fake(fn (Request $request) => str_contains($request->url(), '/api/order_cart_rules')
            ? Http::response(['order_cart_rules' => [['id' => '1', 'id_cart_rule' => '77', 'id_order' => '9']]])
            : NativeCouponFixture::response($request, 'prestashop', $this->native));
        $result = $this->postJson($this->url, ['mode' => 'provider_check'])->assertOk()
            ->assertJsonPath('data.platform_validation', 'merchant_verification_required')
            ->assertJsonPath('verification.detail.provider_coupon.remaining_uses', 1);
        $this->assertArrayNotHasKey('usage_count', $result->json('verification.detail.provider_coupon'));
        $this->assertArrayNotHasKey('usage_limit', $result->json('verification.detail.provider_coupon'));
        $this->native['quantity'] = '0';
        $this->postJson($this->url, ['mode' => 'provider_check'])->assertOk()->assertJsonPath('data.platform_validation', 'mismatch')
            ->assertJsonPath('verification.detail.differences.0', 'usage_exhausted');
    }

    public function test_opencart_scope_usage_and_per_customer_limits_require_review_or_mismatch(): void
    {
        $this->fixture('opencart');
        foreach ([['logged' => 1], ['uses_customer' => 1], ['product_ids' => [3]], ['category_ids' => [2]], ['shipping' => 1], ['uses_total' => null]] as $restriction) {
            $this->native = array_replace(NativeCouponFixture::coupon('opencart'), $restriction);
            $this->postJson($this->url, ['mode' => 'provider_check'])->assertOk()->assertJsonPath('data.platform_validation', 'merchant_verification_required');
        }
        $this->native = array_replace(NativeCouponFixture::coupon('opencart'), ['usage_count' => 1]);
        $this->postJson($this->url, ['mode' => 'provider_check'])->assertOk()->assertJsonPath('data.platform_validation', 'mismatch')
            ->assertJsonPath('verification.detail.differences.0', 'usage_exhausted');
    }

    #[DataProvider('platforms')]
    public function test_credential_rotation_during_read_invalidates_observation(string $platform): void
    {
        $this->fixture($platform);
        Http::swap(new Factory);
        $rotated = false;
        $secret = $this->connection->credential('signing_secret');
        Http::fake(function (Request $request) use ($platform, &$rotated, $secret) {
            if (! $rotated) {
                $rotated = true;
                $this->connection->forceFill(['credential_generation' => $this->connection->credential_generation + 1])->save();
            }

            return NativeCouponFixture::response($request, $platform, $this->native, 'EUR', true, false, $secret);
        });
        $this->postJson($this->url, ['mode' => 'provider_check'])->assertOk()->assertJsonPath('data.platform_validation', 'sync_error')
            ->assertJsonPath('verification.detail.reason', 'verification_context_changed');
    }

    public function test_prestashop_other_rules_multiple_shops_and_incomplete_history_cannot_verify(): void
    {
        $this->fixture('prestashop');
        foreach (['other_rule', 'multishop', 'history_page'] as $case) {
            Http::swap(new Factory);
            Http::fake(function (Request $request) use ($case) {
                if ($case === 'other_rule' && str_contains($request->url(), '/api/cart_rules')) {
                    return Http::response(['cart_rules' => [$this->native, array_replace($this->native, ['id' => '78', 'code' => 'OTHER'])]]);
                }
                if ($case === 'multishop' && str_contains($request->url(), '/api/shops')) {
                    return Http::response(['shops' => [['id' => '1'], ['id' => '2']]]);
                }
                if ($case === 'history_page' && str_contains($request->url(), '/api/order_cart_rules')) {
                    return Http::response(['order_cart_rules' => array_fill(0, 100, ['id' => '1', 'id_cart_rule' => '77'])]);
                }

                return NativeCouponFixture::response($request, 'prestashop', $this->native);
            });
            $this->postJson($this->url, ['mode' => 'provider_check'])->assertOk()->assertJsonPath('data.platform_validation', $case === 'history_page' ? 'sync_error' : 'merchant_verification_required');
        }
    }

    public function test_ccv_incomplete_list_and_conflicting_detail_fail_closed(): void
    {
        $this->fixture('ccv');
        foreach (['next', 'detail'] as $case) {
            Http::swap(new Factory);
            Http::fake(function (Request $request) use ($case) {
                if ($case === 'next' && str_contains($request->url(), '/discountcoupons/?')) {
                    return Http::response(['start' => 0, 'items' => [$this->native], 'next' => 'https://foreign.example/']);
                }
                if ($case === 'detail' && str_contains($request->url(), '/discountcoupons/77/')) {
                    return Http::response(array_replace($this->native, ['code' => 'OTHER']));
                }

                return NativeCouponFixture::response($request, 'ccv', $this->native);
            });
            $this->postJson($this->url, ['mode' => 'provider_check'])->assertOk()->assertJsonPath('data.platform_validation', 'sync_error');
        }
    }

    public function test_prestashop_ambiguous_store_time_is_not_assigned_an_invented_offset(): void
    {
        $this->fixture('prestashop');
        $this->native['date_to'] = '2027-10-31 02:30:00';
        $this->postJson($this->url, ['mode' => 'provider_check'])->assertOk()->assertJsonPath('data.platform_validation', 'sync_error');
    }

    #[DataProvider('platforms')]
    public function test_fixed_amounts_preserve_exact_minor_units(string $platform): void
    {
        $this->fixture($platform);
        $this->code->offer->currentVersion->forceFill(['discount_type' => 'fixed', 'discount_value' => 1234])->save();
        if ($platform === 'opencart') {
            $this->native['type'] = 'F';
            $this->native['discount'] = '12.3400';
        } elseif ($platform === 'prestashop') {
            $this->native['reduction_percent'] = '0.00';
            $this->native['reduction_amount'] = '12.34';
        } else {
            $this->native['type'] = 'Price';
            $this->native['discount'] = '12.34';
        }
        $this->postJson($this->url, ['mode' => 'provider_check'])->assertOk()
            ->assertJsonPath('data.platform_validation', $platform === 'ccv' ? 'merchant_verification_required' : 'verified')
            ->assertJsonPath('verification.detail.provider_coupon.amount_minor', 1234);
    }

    public function test_prestashop_malformed_capacity_and_boolean_identity_are_not_proof(): void
    {
        $this->fixture('prestashop');
        $this->native['quantity'] = null;
        $this->postJson($this->url, ['mode' => 'provider_check'])->assertOk()->assertJsonPath('data.platform_validation', 'merchant_verification_required');
        $this->native = NativeCouponFixture::coupon('prestashop');
        $this->native['id'] = true;
        $this->postJson($this->url, ['mode' => 'provider_check'])->assertOk()->assertJsonPath('data.platform_validation', 'sync_error');
    }
}
