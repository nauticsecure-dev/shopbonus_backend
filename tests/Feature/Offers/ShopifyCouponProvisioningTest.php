<?php

namespace Tests\Feature\Offers;

use App\Enums\EcommercePlatform;
use App\Enums\MembershipRole;
use App\Enums\OfferAcquisition;
use App\Enums\OfferStatus;
use App\Enums\UserStatus;
use App\Enums\VoucherCodeStatus;
use App\Enums\VoucherOrigin;
use App\Exceptions\ApiException;
use App\Integrations\ConnectionManager;
use App\Models\AuditEvent;
use App\Models\Offer;
use App\Models\ShopifyCouponOperation;
use App\Models\User;
use App\Models\VoucherCode;
use App\Models\WebshopConnection;
use App\Services\Offers\OfferService;
use App\Services\Onboarding\Network\HostResolver;
use App\Services\Vouchers\ShopifyCouponProvisioning;
use App\Services\Vouchers\VoucherInventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesActiveWebshops;
use Tests\Support\NativeCouponFixture;
use Tests\TestCase;

/** Synthetic native HTTP contracts, not genuine Shopify checkout acceptance. */
class ShopifyCouponProvisioningTest extends TestCase
{
    use CreatesActiveWebshops, RefreshDatabase;

    private User $owner;

    private Offer $offer;

    private WebshopConnection $connection;

    private ShopifyCouponProvisioning $service;

    private ?array $native = null;

    private array $scopes = ['write_discounts'];

    private bool $uncertain = false;

    private bool $uncertainDeactivation = false;

    private bool $wrongDomain = false;

    private bool $rotateOnIdentity = false;

    private int $creates = 0;

    private int $updates = 0;

    private int $deactivations = 0;

    private string $currency = 'EUR';

    protected function setUp(): void
    {
        parent::setUp();
        config(['integrations.shopify_coupon_writes_enabled' => true]);
        $this->travelTo(now()->startOfSecond());
        $this->owner = User::factory()->create();
        $shop = $this->activeWebshop($this->owner, ['verified_domain' => 'shop.example.com']);
        [$this->connection] = app(ConnectionManager::class)->create($shop->memberships()->sole(), EcommercePlatform::Shopify,
            ['shop_domain' => 'synthetic.myshopify.com', 'signing_secret' => 'SYNTHETIC_signing_secret', 'access_token' => 'shpat_SYNTHETIC_token']);
        $this->offer = app(OfferService::class)->create($shop->memberships()->sole(), OfferAcquisition::PromotionalCode,
            ['name' => 'Synthetic single-use offer', 'discount_type' => 'percentage', 'discount_value' => 1000, 'currency' => 'EUR',
                'min_order_minor' => 10000, 'code_mode' => 'unique', 'starts_at' => now()->subDay(), 'ends_at' => now()->addYear(),
                'claim_validity_days' => null, 'redemption_validity_days' => null, 'combinable' => false]);
        $this->service = app(ShopifyCouponProvisioning::class);
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
        Http::fake(function (Request $request, array $options) {
            $this->assertSame('https://synthetic.myshopify.com/admin/api/2026-10/graphql.json', $request->url());
            $this->assertSame('POST', $request->method());
            $this->assertFalse($options['allow_redirects']);
            $query = $request['query'];
            if (str_contains($query, 'ShopbonusIdentity')) {
                if ($this->rotateOnIdentity) {
                    $this->connection->increment('credential_generation');
                }
                $data = ['shop' => ['id' => 'gid://shopify/Shop/1', 'myshopifyDomain' => 'synthetic.myshopify.com',
                    'primaryDomain' => ['host' => $this->wrongDomain ? 'foreign.example.com' : 'shop.example.com']],
                    'currentAppInstallation' => ['accessScopes' => array_map(fn ($scope) => ['handle' => $scope], $this->scopes)]];
            } elseif (str_contains($query, 'ShopbonusCouponByCode')) {
                $data = ['shop' => ['currencyCode' => $this->currency], 'codeDiscountNodeByCode' => $this->native];
            } elseif (str_contains($query, 'ShopbonusDiscountDeactivate')) {
                $this->deactivations++;
                $this->native['codeDiscount']['status'] = 'EXPIRED';
                $this->native['codeDiscount']['endsAt'] = now()->toIso8601String();
                if ($this->uncertainDeactivation) {
                    throw new ConnectionException('SYNTHETIC deactivation timeout');
                }
                $data = ['discountCodeDeactivate' => ['codeDiscountNode' => ['id' => $this->native['id']], 'userErrors' => []]];
            } else {
                $create = str_contains($query, 'ShopbonusDiscountCreate');
                $this->assertTrue($create || str_contains($query, 'ShopbonusDiscountUpdate'));
                $create ? $this->creates++ : $this->updates++;
                $input = $request['variables']['input'];
                $this->assertSame(['all' => 'ALL'], $input['context']);
                $this->assertSame(1, $input['usageLimit']);
                $this->native = NativeCouponFixture::coupon('shopify', $input['code']);
                $discount = &$this->native['codeDiscount'];
                $discount['title'] = $input['title'];
                $discount['startsAt'] = $input['startsAt'];
                $discount['endsAt'] = $input['endsAt'];
                $discount['minimumRequirement'] = $input['minimumRequirement'] === null ? null : ['__typename' => 'DiscountMinimumSubtotal',
                    'greaterThanOrEqualToSubtotal' => ['amount' => $input['minimumRequirement']['subtotal']['greaterThanOrEqualToSubtotal'], 'currencyCode' => 'EUR']];
                $discount['customerGets']['value'] = isset($input['customerGets']['value']['percentage'])
                    ? ['__typename' => 'DiscountPercentage', 'percentage' => (string) $input['customerGets']['value']['percentage']]
                    : ['__typename' => 'DiscountAmount', 'amount' => ['amount' => $input['customerGets']['value']['discountAmount']['amount'], 'currencyCode' => 'EUR'], 'appliesOnEachItem' => false];
                if ($this->uncertain) {
                    throw new ConnectionException('SYNTHETIC response timeout');
                }
                $data = [$create ? 'discountCodeBasicCreate' : 'discountCodeBasicUpdate' => ['codeDiscountNode' => ['id' => $this->native['id']], 'userErrors' => []]];
            }

            return Http::response(['data' => $data], 200, ['X-Shopify-API-Version' => '2026-10']);
        });
    }

    private function prepare(string $key = 'synthetic-request-1'): ShopifyCouponOperation
    {
        $this->service->configure($this->offer, $this->connection, $this->owner, true);

        return $this->service->prepare($this->offer, $this->connection, $this->owner, $key);
    }

    private function failsWith(string $code, callable $call): void
    {
        try {
            $call();
            $this->fail('Expected an intentional rejection.');
        } catch (ApiException $e) {
            $this->assertSame($code, $e->errorCode);
        }
    }

    public function test_opt_in_encrypted_intent_idempotent_generation_readback_import_and_cleanup(): void
    {
        $op = $this->prepare();
        Http::assertNothingSent();
        $this->assertSame($op->id, $this->service->prepare($this->offer, $this->connection, $this->owner, 'synthetic-request-1')->id);
        $this->assertStringNotContainsString($op->code, DB::table('shopify_coupon_operations')->value('code'));
        $this->assertStringNotContainsString($op->code, json_encode($op));
        $this->assertSame('verified', $this->service->execute($op, $this->owner, 'create')->state);
        $this->service->execute($op, $this->owner, 'create');
        $this->assertSame(1, $this->creates);
        $this->assertSame(0, VoucherCode::count());
        $op = $this->service->execute($op, $this->owner, 'import');
        $this->service->execute($op, $this->owner, 'import');
        $this->assertSame(1, VoucherCode::count());
        $this->assertSame('verified', VoucherCode::sole()->platform_validation);
        $this->assertSame('deactivated', $this->service->execute($op, $this->owner, 'deactivate')->state);
        $this->service->execute($op, $this->owner, 'deactivate');
        $this->assertSame(1, $this->deactivations);
        $this->assertSame(VoucherCodeStatus::Invalid, VoucherCode::sole()->status);
        $this->assertStringNotContainsString($op->code, AuditEvent::all()->toJson());
    }

    public function test_fixed_amount_preserves_minor_units(): void
    {
        $this->offer->currentVersion->update(['discount_type' => 'fixed', 'discount_value' => 475]);
        $op = $this->prepare();
        $this->assertSame('4.75', $op->input['customerGets']['value']['discountAmount']['amount']);
        $this->assertSame('verified', $this->service->execute($op, $this->owner, 'create')->state);
    }

    public function test_unsupported_relative_validity_and_discount_caps_are_not_silently_ignored(): void
    {
        foreach ([['redemption_validity_days' => 30], ['max_discount_minor' => 500], ['code_mode' => 'reusable'], ['products' => ['1']]] as $changes) {
            $v = $this->offer->currentVersion;
            $old = $v->only(array_keys($changes));
            $v->update($changes);
            $this->failsWith('shopify_coupon_terms_unsupported', fn () => $this->prepare());
            $v->update($old);
        }
        Http::assertNothingSent();
        $this->assertSame(0, ShopifyCouponOperation::count());
    }

    public function test_absent_per_offer_opt_in_and_default_off_writes_prevent_effects(): void
    {
        $this->failsWith('shopify_coupon_opt_in_required', fn () => $this->service->prepare($this->offer, $this->connection, $this->owner, 'synthetic-request-1'));
        $op = $this->prepare();
        config(['integrations.shopify_coupon_writes_enabled' => false]);
        $this->failsWith('shopify_coupon_writes_disabled', fn () => $this->service->execute($op, $this->owner, 'create'));
        $this->assertSame('prepared', $op->refresh()->state);
        Http::assertNothingSent();
    }

    public function test_foreign_suspended_and_permission_removed_actors_cannot_write(): void
    {
        $op = $this->prepare();
        $foreign = User::factory()->create();
        $this->failsWith('not_found', fn () => $this->service->execute($op, $foreign, 'create'));
        $this->owner->forceFill(['status' => UserStatus::Suspended])->save();
        $this->failsWith('forbidden', fn () => $this->service->execute($op, $this->owner, 'create'));
        $this->owner->forceFill(['status' => UserStatus::Active])->save();
        $this->connection->webshop->memberships()->update(['role' => MembershipRole::Employee->value]);
        $this->failsWith('forbidden', fn () => $this->service->execute($op, $this->owner, 'create'));
        Http::assertNothingSent();
    }

    public function test_fresh_offer_opt_in_and_grant_generation_are_required(): void
    {
        $op = $this->prepare();
        $membership = $this->connection->webshop->memberships()->sole();
        app(OfferService::class)->update($membership, $this->offer->refresh(), ['discount_value' => 2000]);
        $this->failsWith('shopify_coupon_binding_changed', fn () => $this->service->execute($op, $this->owner, 'create'));
        $this->failsWith('shopify_coupon_opt_in_required', fn () => $this->service->prepare($this->offer, $this->connection, $this->owner, 'synthetic-request-2'));
        $this->service->configure($this->offer, $this->connection, $this->owner, true);
        $next = $this->service->prepare($this->offer, $this->connection, $this->owner, 'synthetic-request-2');
        $this->connection->increment('credential_generation');
        $this->failsWith('shopify_coupon_binding_changed', fn () => $this->service->execute($next, $this->owner, 'create'));
        Http::assertNothingSent();
    }

    public function test_missing_write_scope_and_foreign_live_domain_never_send_mutations(): void
    {
        $op = $this->prepare();
        $this->scopes = ['read_discounts'];
        $this->failsWith('provider_scope_missing', fn () => $this->service->execute($op, $this->owner, 'create'));
        $this->assertSame(0, $this->creates);
        // A new independent intent exercises the domain guard before any write intent.
        $this->scopes = ['write_discounts'];
        $this->wrongDomain = true;
        $next = $this->service->prepare($this->offer, $this->connection, $this->owner, 'synthetic-request-2');
        $this->failsWith('store_identity_unverified', fn () => $this->service->execute($next, $this->owner, 'create'));
        $this->assertSame(0, $this->creates);
    }

    public function test_timeout_is_durable_and_reconciles_without_duplicate_create(): void
    {
        $op = $this->prepare();
        $this->uncertain = true;
        try {
            $this->service->execute($op, $this->owner, 'create');
            $this->fail('Expected provider timeout.');
        } catch (ApiException $e) {
            $this->assertSame(502, $e->status);
        }
        $this->assertSame('create_unknown', $op->refresh()->state);
        $this->service->execute($op, $this->owner, 'create');
        $this->assertSame(1, $this->creates);
        $this->assertSame('verified', $this->service->execute($op, $this->owner, 'reconcile')->state);
        $this->assertSame(0, VoucherCode::count());
    }

    public function test_mismatches_require_owned_unpublished_repair_and_exact_readback(): void
    {
        $op = $this->prepare();
        $this->service->execute($op, $this->owner, 'create');
        $this->native['codeDiscount']['customerGets']['value']['percentage'] = '0.2';
        $this->failsWith('shopify_coupon_readback_required', fn () => $this->service->execute($op, $this->owner, 'import'));
        $this->assertSame('mismatch', $op->refresh()->state);
        $this->assertSame('verified', $this->service->execute($op, $this->owner, 'repair')->state);
        $this->assertSame(1, $this->updates);
        $this->native['codeDiscount']['endsAt'] = now()->addYears(2)->toIso8601String();
        $this->failsWith('shopify_coupon_readback_required', fn () => $this->service->execute($op, $this->owner, 'import'));
        $this->native['codeDiscount']['title'] = 'Merchant-created discount';
        $this->failsWith('shopify_coupon_ownership_unconfirmed', fn () => $this->service->execute($op, $this->owner, 'repair'));
        $this->assertSame(1, $this->updates);
        $this->assertSame(0, VoucherCode::count());
    }

    public function test_imported_codes_cannot_be_repaired_and_issued_codes_cannot_be_deactivated(): void
    {
        $op = $this->prepare();
        $this->service->execute($op, $this->owner, 'create');
        $this->service->execute($op, $this->owner, 'import');
        $this->failsWith('shopify_coupon_transition_invalid', fn () => $this->service->execute($op, $this->owner, 'repair'));
        VoucherCode::sole()->forceFill(['status' => VoucherCodeStatus::Issued, 'issued_at' => now()])->save();
        $this->failsWith('shopify_coupon_in_use', fn () => $this->service->execute($op, $this->owner, 'deactivate'));
        $this->assertSame(0, $this->deactivations);
        $this->assertSame(VoucherCodeStatus::Issued, VoucherCode::sole()->status);
    }

    public function test_domain_credential_pause_and_used_coupon_readback_fail_closed(): void
    {
        $op = $this->prepare();
        $this->service->execute($op, $this->owner, 'create');
        $this->native['codeDiscount']['asyncUsageCount'] = 1;
        $this->failsWith('shopify_coupon_readback_required', fn () => $this->service->execute($op, $this->owner, 'import'));
        $this->connection->forceFill(['paused_at' => now()])->save();
        $this->failsWith('shopify_coupon_connection_unavailable', fn () => $this->service->execute($op, $this->owner, 'reconcile'));
        $this->assertSame(0, VoucherCode::count());
    }

    public function test_rotation_during_preflight_prevents_native_write(): void
    {
        $op = $this->prepare();
        $this->rotateOnIdentity = true;
        $this->failsWith('shopify_coupon_binding_changed', fn () => $this->service->execute($op, $this->owner, 'create'));
        $this->assertSame(0, $this->creates);
    }

    public function test_native_code_cannot_be_allocated_under_different_offer_terms(): void
    {
        $op = $this->prepare();
        $this->service->execute($op, $this->owner, 'create');
        $this->service->execute($op, $this->owner, 'import');
        $member = $this->connection->webshop->memberships()->sole();
        app(OfferService::class)->transition($this->offer->refresh(), OfferStatus::Active);
        app(OfferService::class)->update($member, $this->offer->refresh(), ['discount_value' => 2000]);
        $this->failsWith('offer_out_of_stock', fn () => app(VoucherInventory::class)->reserve(
            $this->offer->refresh(), VoucherOrigin::Promotional, 'synthetic-reserve', actorUserId: $this->owner->id));
        $this->assertSame(VoucherCodeStatus::Available, VoucherCode::sole()->status);
    }

    public function test_deactivation_timeout_keeps_local_inventory_closed_and_never_replays(): void
    {
        $op = $this->prepare();
        $this->service->execute($op, $this->owner, 'create');
        $this->service->execute($op, $this->owner, 'import');
        $this->uncertainDeactivation = true;
        try {
            $this->service->execute($op, $this->owner, 'deactivate');
            $this->fail('Expected timeout.');
        } catch (ApiException $e) {
            $this->assertSame(502, $e->status);
        }
        $this->assertSame('deactivate_unknown', $op->refresh()->state);
        $this->assertSame(VoucherCodeStatus::Invalid, VoucherCode::sole()->status);
        $this->failsWith('shopify_coupon_reconcile_required', fn () => $this->service->execute($op, $this->owner, 'deactivate'));
        $this->assertSame('deactivated', $this->service->execute($op, $this->owner, 'reconcile')->state);
        $this->assertSame(1, $this->deactivations);
    }

    public function test_command_uses_current_merchant_authority_and_never_prints_secret_code(): void
    {
        $op = $this->prepare();
        $status = Artisan::call('shopbonus:shopify-coupon', [
            'action' => 'create', 'id' => $op->public_id, '--actor' => $this->owner->public_id,
        ]);
        $this->assertSame(0, $status);
        $output = Artisan::output();
        $this->assertStringNotContainsString($op->code, $output);
        $this->assertStringContainsString('verified', $output);
        $foreign = User::factory()->create();
        $this->assertSame(1, Artisan::call('shopbonus:shopify-coupon', [
            'action' => 'deactivate', 'id' => $op->public_id, '--actor' => $foreign->public_id,
        ]));
        $this->assertSame(0, $this->deactivations);
    }

    public function test_store_currency_must_match_before_sending_any_write(): void
    {
        $op = $this->prepare();
        $this->currency = 'USD';
        $this->failsWith('shopify_coupon_currency_mismatch', fn () => $this->service->execute($op, $this->owner, 'create'));
        $this->assertSame('prepared', $op->refresh()->state);
        $this->assertSame(0, $this->creates);
    }

    public function test_reconciled_native_drift_closes_unused_inventory_and_allows_owned_cleanup(): void
    {
        $op = $this->prepare();
        $this->service->execute($op, $this->owner, 'create');
        $this->service->execute($op, $this->owner, 'import');
        $this->native['codeDiscount']['customerGets']['value']['percentage'] = '0.2';
        $this->assertSame('imported_mismatch', $this->service->execute($op, $this->owner, 'reconcile')->state);
        $this->assertSame(VoucherCodeStatus::Invalid, VoucherCode::sole()->status);
        $this->assertSame('mismatch', VoucherCode::sole()->platform_validation);
        $this->assertSame('deactivated', $this->service->execute($op, $this->owner, 'deactivate')->state);
    }

    public function test_frozen_intent_cannot_be_rewritten_to_a_different_native_discount(): void
    {
        $op = $this->prepare();
        $this->service->execute($op, $this->owner, 'create');
        $op->refresh();
        $this->expectException(\LogicException::class);
        $op->update(['remote_id' => 'gid://shopify/DiscountCodeNode/999']);
    }
}
