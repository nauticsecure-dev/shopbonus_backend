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

class AdditionalNativeCouponVerificationTest extends TestCase
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
        Http::fake(fn (Request $r) => NativeCouponFixture::response($r, $this->platform, $this->native, $this->currency, $this->authorized, $this->wrongStore));
    }

    public static function platforms(): array
    {
        return [['magento'], ['squarespace'], ['shopware6']];
    }

    #[DataProvider('platforms')]
    public function test_native_configuration_is_verified_without_checkout_or_financial_claims(string $platform): void
    {
        $this->fixture($platform);
        $this->postJson($this->url, ['mode' => 'provider_check'])->assertOk()->assertJsonPath('data.platform_validation', 'verified')
            ->assertJsonPath('verification.detail.evidence_scope', 'native_configuration_not_checkout_or_redemption');
        $this->assertDatabaseHas('voucher_events', ['voucher_code_id' => $this->code->id, 'type' => 'platform_validation_provider_check', 'to_status' => 'verified']);
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

    #[DataProvider('platforms')]
    public function test_restrictions_and_incomplete_provider_reads_never_verify(string $platform): void
    {
        $this->fixture($platform);
        $this->native[match ($platform) {
            'magento' => 'uses_per_customer', 'shopware6' => 'customerRestriction', default => 'isOncePerCustomer'
        }] = $platform === 'magento' ? 1 : true;
        $this->postJson($this->url, ['mode' => 'provider_check'])->assertOk()->assertJsonPath('data.platform_validation', 'merchant_verification_required');
        $this->native = NativeCouponFixture::coupon($platform);
        Http::swap(new Factory);
        Http::fake(fn (Request $r) => str_contains($r->url(), match ($platform) {
            'magento' => '/coupons/search', 'shopware6' => '/api/search/promotion', default => '/commerce/discounts'
        })
            ? Http::response(match ($platform) {
                'magento' => ['items' => [], 'total_count' => 2], 'shopware6' => ['data' => [], 'total' => 2], default => ['discounts' => [$this->native], 'hasNextPage' => true]
            })
            : NativeCouponFixture::response($r, $platform, $this->native));
        $this->postJson($this->url, ['mode' => 'provider_check'])->assertOk()->assertJsonPath('data.platform_validation', 'sync_error');
    }

    #[DataProvider('platforms')]
    public function test_mutated_terms_and_usage_never_verify(string $platform): void
    {
        $this->fixture($platform);
        if ($platform === 'magento') {
            $this->native['discount_amount'] = '11.0000';
        } elseif ($platform === 'shopware6') {
            $this->native['discounts'][0]['value'] = '11';
        } else {
            $this->native['template']['percentage'] = 11;
        }
        $this->postJson($this->url, ['mode' => 'provider_check'])->assertOk()->assertJsonPath('data.platform_validation', 'mismatch');
        $this->native = NativeCouponFixture::coupon($platform);
        $this->native[match ($platform) {
            'magento' => 'to_date', 'shopware6' => 'validUntil', default => 'validTo'
        }] = $platform === 'magento' ? '2026-02-30' : 'not-a-date';
        $this->postJson($this->url, ['mode' => 'provider_check'])->assertOk()->assertJsonPath('data.platform_validation', 'sync_error');
    }

    public function test_squarespace_unknown_options_and_other_discounts_require_review(): void
    {
        $this->fixture('squarespace');
        $this->native['template']['unrecognizedRestriction'] = true;
        $this->postJson($this->url, ['mode' => 'provider_check'])->assertOk()->assertJsonPath('data.platform_validation', 'merchant_verification_required');
        $this->native = NativeCouponFixture::coupon('squarespace');
        Http::swap(new Factory);
        Http::fake(fn (Request $r) => str_contains($r->url(), '/commerce/discounts')
            ? Http::response(['discounts' => [$this->native, array_replace($this->native, ['id' => 'other', 'trigger' => ['type' => 'AUTO']])], 'hasNextPage' => false])
            : NativeCouponFixture::response($r, 'squarespace', $this->native));
        $this->postJson($this->url, ['mode' => 'provider_check'])->assertOk()->assertJsonPath('data.platform_validation', 'merchant_verification_required');
    }

    public function test_magento_inconsistent_active_rule_inventory_fails_closed(): void
    {
        $this->fixture('magento');
        Http::swap(new Factory);
        Http::fake(fn (Request $r) => str_contains($r->url(), '/salesRules/search')
            ? Http::response(['items' => [], 'total_count' => 0])
            : NativeCouponFixture::response($r, 'magento', $this->native));
        $this->postJson($this->url, ['mode' => 'provider_check'])->assertOk()->assertJsonPath('data.platform_validation', 'sync_error');
    }

    #[DataProvider('platforms')]
    public function test_fixed_cart_amounts_use_exact_minor_units(string $platform): void
    {
        $this->fixture($platform);
        $this->code->offer->currentVersion->forceFill(['discount_type' => 'fixed', 'discount_value' => 1234])->save();
        if ($platform === 'magento') {
            $this->native['simple_action'] = 'cart_fixed';
            $this->native['discount_amount'] = '12.3400';
        } elseif ($platform === 'shopware6') {
            $this->native['discounts'][0]['type'] = 'absolute';
            $this->native['discounts'][0]['value'] = '12.34';
        } else {
            $this->native['template'] = ['type' => 'FIXED_AMOUNT', 'discountAmount' => ['value' => '12.34', 'currency' => 'EUR']];
        }
        $this->postJson($this->url, ['mode' => 'provider_check'])->assertOk()->assertJsonPath('data.platform_validation', 'verified')
            ->assertJsonPath('verification.detail.provider_coupon.amount_minor', 1234);
    }

    #[DataProvider('platforms')]
    public function test_in_flight_offer_edit_invalidates_observation(string $platform): void
    {
        $this->fixture($platform);
        Http::swap(new Factory);
        $edited = false;
        Http::fake(function (Request $r) use ($platform, &$edited) {
            if (! $edited) {
                $edited = true;
                app(OfferService::class)->update($this->shop->memberships()->sole(), $this->code->offer, ['discount_value' => 2000]);
            }

            return NativeCouponFixture::response($r, $platform, $this->native);
        });
        $this->postJson($this->url, ['mode' => 'provider_check'])->assertOk()->assertJsonPath('data.platform_validation', 'sync_error')
            ->assertJsonPath('verification.detail.reason', 'verification_context_changed');
    }

    #[DataProvider('platforms')]
    public function test_exhausted_capacity_cannot_verify(string $platform): void
    {
        $this->fixture($platform);
        if ($platform === 'squarespace') {
            $this->native['numberOfUses'] = 1;
        } elseif ($platform === 'shopware6') {
            $this->native['orderCount'] = 1;
        } else {
            Http::swap(new Factory);
            Http::fake(function (Request $r) {
                if (str_ends_with($r->url(), '/coupons/17')) {
                    return Http::response(['coupon_id' => 17, 'rule_id' => 77, 'code' => 'SYNTHETIC-10', 'usage_limit' => 1, 'usage_per_customer' => 0, 'times_used' => 1, 'expiration_date' => null]);
                }

                return NativeCouponFixture::response($r, 'magento', $this->native);
            });
        }
        $this->postJson($this->url, ['mode' => 'provider_check'])->assertOk()->assertJsonPath('data.platform_validation', 'mismatch')
            ->assertJsonPath('verification.detail.differences.0', 'usage_exhausted');
    }
}
