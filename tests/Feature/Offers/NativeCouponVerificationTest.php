<?php

namespace Tests\Feature\Offers;

use App\Enums\EcommercePlatform;
use App\Enums\OfferAcquisition;
use App\Enums\OfferStatus;
use App\Enums\VoucherOrigin;
use App\Integrations\ConnectionManager;
use App\Models\User;
use App\Models\VoucherCode;
use App\Models\Webshop;
use App\Models\WebshopConnection;
use App\Services\Offers\OfferService;
use App\Services\Onboarding\Network\HostResolver;
use App\Services\Vouchers\VoucherInventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesActiveWebshops;
use Tests\Support\NativeCouponFixture;
use Tests\TestCase;

class NativeCouponVerificationTest extends TestCase
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
            'shopify' => ['shop_domain' => 'synthetic.myshopify.com', 'signing_secret' => 'SYNTHETIC_signing_secret', 'access_token' => 'shpat_SYNTHETIC_token'],
            'bigcommerce' => ['store_hash' => 'abc123', 'access_token' => 'SYNTHETIC_bc_token', 'client_secret' => 'SYNTHETIC_bc_secret'],
            default => ['store_id' => '1003', 'access_token' => 'SYNTHETIC_ecwid_token', 'client_secret' => 'SYNTHETIC_ecwid_secret'],
        };
        [$this->connection] = app(ConnectionManager::class)->create($this->shop->memberships()->sole(), EcommercePlatform::from($platform), $input);
        $offer = app(OfferService::class)->create($this->shop->memberships()->sole(), OfferAcquisition::MerchantReward,
            ['name' => 'Synthetic coupon', 'discount_type' => 'percentage', 'discount_value' => 1000, 'currency' => 'EUR', 'min_order_minor' => 10000,
                'max_discount_minor' => null, 'redemption_validity_days' => 30, 'combinable' => false]);
        $this->code = VoucherCode::create(['webshop_id' => $this->shop->id, 'offer_id' => $offer->id, 'code' => 'SYNTHETIC-10',
            'code_hash' => VoucherCode::hashFor($this->shop->id, 'SYNTHETIC-10'), 'code_last4' => 'C-10']);
        $this->url = "/api/v1/workspaces/{$this->shop->public_id}/codes/{$this->code->public_id}/platform-validation";
        Sanctum::actingAs($this->owner);
        Http::fake(fn (Request $r) => NativeCouponFixture::response($r, $this->platform, $this->native, $this->currency, $this->authorized, $this->wrongStore));
    }

    public static function platforms(): array
    {
        return [['shopify'], ['bigcommerce'], ['ecwid'], ['lightspeed_e']];
    }

    #[DataProvider('platforms')]
    public function test_native_configuration_is_verified_without_checkout_or_financial_claims(string $platform): void
    {
        $this->fixture($platform);
        $this->postJson($this->url, ['mode' => 'provider_check'])->assertOk()->assertJsonPath('data.platform_validation', 'verified')
            ->assertJsonPath('verification.detail.evidence_scope', 'native_configuration_not_checkout_or_redemption');
        $this->assertDatabaseHas('voucher_events', ['voucher_code_id' => $this->code->id, 'type' => 'platform_validation_provider_check', 'to_status' => 'verified']);
        $this->assertDatabaseCount('vouchers', 0);
        Http::assertNotSent(fn (Request $r) => $r->method() !== 'GET' && ! str_starts_with($r['query'] ?? '', 'query '));
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

    public function test_shopify_restricted_unknown_and_ambiguous_types_are_not_verified(): void
    {
        $this->fixture('shopify');
        foreach (['DiscountCustomers', 'DiscountContextUnknown', 'DiscountMarkets'] as $context) {
            $this->native['codeDiscount']['context']['__typename'] = $context;
            $this->postJson($this->url, ['mode' => 'provider_check'])->assertOk()->assertJsonPath('data.platform_validation', 'merchant_verification_required');
        }
        $this->native['codeDiscount']['codesCount']['count'] = 2;
        $this->postJson($this->url, ['mode' => 'provider_check'])->assertOk()->assertJsonPath('data.platform_validation', 'sync_error');
        $this->native = ['id' => 'gid://shopify/DiscountCodeNode/77', 'codeDiscount' => ['__typename' => 'DiscountCodeApp']];
        $this->postJson($this->url, ['mode' => 'provider_check'])->assertOk()->assertJsonPath('data.platform_validation', 'merchant_verification_required');
    }

    public function test_expiration_exhaustion_and_unprovable_caps_never_match(): void
    {
        $this->fixture('bigcommerce');
        $original = $this->native;
        foreach (['expires' => now()->subDay()->format(DATE_RFC2822), 'num_uses' => 1, 'amount' => '11.00', 'min_purchase' => '200.00'] as $key => $value) {
            $this->native = array_replace($original, [$key => $value]);
            $this->postJson($this->url, ['mode' => 'provider_check'])->assertOk()->assertJsonPath('data.platform_validation', 'mismatch');
        }
        $this->native = $original;
        $this->code->offer->currentVersion->forceFill(['max_discount_minor' => 1000])->save();
        $this->postJson($this->url, ['mode' => 'provider_check'])->assertOk()->assertJsonPath('data.platform_validation', 'merchant_verification_required');
    }

    public function test_scope_malformed_money_and_ambiguous_native_lists_fail_closed(): void
    {
        $this->fixture('ecwid');
        $original = $this->native;
        $this->native['catalogLimit'] = ['products' => [123]];
        $this->postJson($this->url, ['mode' => 'provider_check'])->assertOk()->assertJsonPath('data.platform_validation', 'merchant_verification_required');
        $this->native = array_replace($original, ['discount' => 'not-money']);
        $this->postJson($this->url, ['mode' => 'provider_check'])->assertOk()->assertJsonPath('data.platform_validation', 'merchant_verification_required');
        Http::swap(new Factory);
        Http::fake(fn (Request $r) => str_contains($r->url(), '/discount_coupons') ? Http::response(['items' => [$original, $original], 'total' => 2, 'count' => 2, 'offset' => 0]) : NativeCouponFixture::response($r, 'ecwid', $original));
        $this->postJson($this->url, ['mode' => 'provider_check'])->assertOk()->assertJsonPath('data.platform_validation', 'sync_error');
    }

    public function test_foreign_workspace_and_non_manager_cannot_trigger_native_reads(): void
    {
        $this->fixture('shopify');
        Sanctum::actingAs(User::factory()->create());
        $this->postJson($this->url, ['mode' => 'provider_check'])->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_historical_verification_uses_frozen_terms_without_overwriting_inventory(): void
    {
        $this->fixture('shopify');
        $this->native['codeDiscount']['customerGets']['value'] = ['__typename' => 'DiscountAmount', 'amount' => ['amount' => '10.00', 'currencyCode' => 'EUR'], 'appliesOnEachItem' => false];
        $offer = app(OfferService::class)->update($this->shop->memberships()->sole(), $this->code->offer, ['discount_type' => 'fixed', 'discount_value' => 1000]);
        $offer = app(OfferService::class)->transition($offer, OfferStatus::Active);
        $voucher = app(VoucherInventory::class)->reserve($offer, VoucherOrigin::Answer24Claim, 'synthetic-history');
        app(OfferService::class)->update($this->shop->memberships()->sole(), $offer, ['discount_value' => 2000]);
        $this->postJson($this->url, ['mode' => 'provider_check'])->assertOk()->assertJsonPath('data.platform_validation', 'mismatch');
        $this->postJson($this->url, ['mode' => 'provider_check', 'voucher_id' => $voucher->public_id])->assertOk()
            ->assertJsonPath('verification.platform_validation', 'verified')->assertJsonPath('verification.detail.scope', 'historical_voucher')
            ->assertJsonPath('data.platform_validation', 'mismatch');
    }

    public function test_member_without_manage_permission_cannot_check_or_manually_confirm(): void
    {
        $this->fixture('ecwid');
        $employee = User::factory()->create();
        $this->shop->memberships()->create(['user_id' => $employee->id, 'role' => 'employee', 'status' => 'active']);
        Sanctum::actingAs($employee);
        $this->postJson($this->url, ['mode' => 'provider_check'])->assertForbidden();
        $this->postJson($this->url, ['mode' => 'manual_confirm', 'note' => 'Synthetic denied'])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_incomplete_page_invalid_date_and_missing_counters_never_verify(): void
    {
        $this->fixture('bigcommerce');
        $this->native['id'] = 'not-a-native-id';
        $this->postJson($this->url, ['mode' => 'provider_check'])->assertOk()->assertJsonPath('data.platform_validation', 'sync_error');
        $this->native = NativeCouponFixture::coupon('bigcommerce');
        $this->native['expires'] = 'tomorrow';
        $this->postJson($this->url, ['mode' => 'provider_check'])->assertOk()->assertJsonPath('data.platform_validation', 'sync_error');
        $this->native = NativeCouponFixture::coupon('bigcommerce');
        unset($this->native['num_uses']);
        $this->postJson($this->url, ['mode' => 'provider_check'])->assertOk()->assertJsonPath('data.platform_validation', 'merchant_verification_required');
        $native = NativeCouponFixture::coupon('bigcommerce');
        Http::swap(new Factory);
        Http::fake(fn (Request $r) => str_contains($r->url(), '/v2/coupons') ? Http::response(array_fill(0, 100, $native)) : NativeCouponFixture::response($r, 'bigcommerce', $native));
        $this->postJson($this->url, ['mode' => 'provider_check'])->assertOk()->assertJsonPath('data.platform_validation', 'sync_error');
    }

    #[DataProvider('platforms')]
    public function test_active_fixed_value_offer_is_automatically_verifiable(string $platform): void
    {
        $this->fixture($platform);
        if ($platform === 'shopify') {
            $this->native['codeDiscount']['customerGets']['value'] = ['__typename' => 'DiscountAmount', 'amount' => ['amount' => '10.00', 'currencyCode' => 'EUR'], 'appliesOnEachItem' => false];
        } elseif ($platform === 'bigcommerce') {
            $this->native['type'] = 'per_total_discount';
            $this->native['amount'] = '10.00';
        } else {
            $this->native['discountType'] = 'ABS';
            $this->native['discount'] = '10.00';
        }
        $offer = app(OfferService::class)->update($this->shop->memberships()->sole(), $this->code->offer, ['discount_type' => 'fixed', 'discount_value' => 1000]);
        app(OfferService::class)->transition($offer, OfferStatus::Active);
        $this->postJson($this->url, ['mode' => 'provider_check'])->assertOk()->assertJsonPath('data.platform_validation', 'verified');
    }

    public function test_in_flight_offer_edit_or_credential_rotation_cannot_persist_verified(): void
    {
        $this->fixture('shopify');
        foreach (['terms', 'credentials'] as $change) {
            $offer = $this->code->offer;
            $offer->currentVersion->forceFill(['discount_value' => 1000])->save();
            Http::swap(new Factory);
            Http::fake(function (Request $r) use ($change, $offer) {
                if (str_contains($r['query'], 'ShopbonusCouponByCode')) {
                    if ($change === 'terms') {
                        $offer->currentVersion->forceFill(['discount_value' => 2000])->save();
                    } else {
                        $this->connection->increment('credential_generation');
                    }
                }

                return NativeCouponFixture::response($r, $this->platform, $this->native);
            });
            $this->postJson($this->url, ['mode' => 'provider_check'])->assertOk()->assertJsonPath('data.platform_validation', 'sync_error')
                ->assertJsonPath('verification.detail.reason', 'verification_context_changed');
        }
    }

    public function test_stable_explicit_offer_dates_survive_final_context_check(): void
    {
        $this->fixture('shopify');
        app(OfferService::class)->update($this->shop->memberships()->sole(), $this->code->offer,
            ['starts_at' => now()->subDay(), 'ends_at' => now()->addDays(31)]);
        $this->postJson($this->url, ['mode' => 'provider_check'])->assertOk()->assertJsonPath('data.platform_validation', 'verified');
    }
}
