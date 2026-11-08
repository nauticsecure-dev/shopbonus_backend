<?php

namespace App\Services\Vouchers;

use App\Enums\AuditCategory;
use App\Enums\EcommercePlatform;
use App\Enums\OfferStatus;
use App\Enums\VoucherCodeStatus;
use App\Enums\WorkspacePermission;
use App\Exceptions\ApiException;
use App\Integrations\Http\ShopifyApi;
use App\Models\Offer;
use App\Models\OfferVersion;
use App\Models\ShopifyCouponOperation;
use App\Models\User;
use App\Models\VoucherCode;
use App\Models\VoucherEvent;
use App\Models\WebshopConnection;
use App\Models\WebshopMembership;
use App\Services\Audit\AuditLogger;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Operator entry point acting for a currently authorized merchant. No checkout/redemption claims. */
class ShopifyCouponProvisioning
{
    public function __construct(private ShopifyApi $api, private AuditLogger $audit) {}

    public function configure(Offer $offer, WebshopConnection $connection, User $actor, bool $enabled): void
    {
        Cache::lock('shopify-coupons:'.$connection->id, 120)->block(0, function () use ($offer, $connection, $actor, $enabled): void {
            DB::transaction(function () use ($offer, $connection, $actor, $enabled): void {
                $offer = Offer::whereKey($offer->id)->lockForUpdate()->firstOrFail();
                $connection = WebshopConnection::findOrFail($connection->id);
                $this->authorize($offer, $connection, $actor);
                $terms = OfferVersion::whereKey($offer->current_version_id)->lockForUpdate()->firstOrFail();
                if ($enabled) {
                    $this->input($terms, 'SB-VALIDATION', 'ShopBonus validation');
                    // Freeze the reviewed version; later merchant changes must create a new version/opt-in.
                    if ($terms->locked_at === null) {
                        $terms->forceFill(['locked_at' => now()])->save();
                    }
                }
                DB::table('shopify_coupon_programs')->updateOrInsert(['offer_id' => $offer->id], [
                    'connection_id' => $connection->id, 'offer_version_id' => $terms->id,
                    'credential_generation' => $connection->credential_generation, 'verified_domain' => $connection->webshop->verified_domain,
                    'enabled' => $enabled, 'approved_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $this->audit->record('shopify_coupon.opt_in_changed', category: AuditCategory::Voucher, resource: $offer,
                    webshopId: $offer->webshop_id, actorUserId: $actor->id, metadata: ['enabled' => $enabled, 'version_id' => $terms->id, 'connection' => $connection->public_id]);
            });
        });
    }

    /** Persist before contacting Shopify. The same request key always identifies the same intent. */
    public function prepare(Offer $offer, WebshopConnection $connection, User $actor, string $requestKey): ShopifyCouponOperation
    {
        if (! preg_match('/\A[A-Za-z0-9_-]{8,100}\z/D', $requestKey)) {
            throw new \InvalidArgumentException('Use an 8–100 character request key.');
        }

        return Cache::lock('shopify-coupons:'.$connection->id, 120)->block(0, function () use ($offer, $connection, $actor, $requestKey): ShopifyCouponOperation {
            return DB::transaction(function () use ($offer, $connection, $actor, $requestKey): ShopifyCouponOperation {
                $offer = Offer::whereKey($offer->id)->lockForUpdate()->firstOrFail();
                $connection = WebshopConnection::findOrFail($connection->id);
                $this->authorize($offer, $connection, $actor);
                $this->program($offer, $connection);
                $existing = ShopifyCouponOperation::where('offer_id', $offer->id)->where('request_key', $requestKey)->first();
                if ($existing !== null) {
                    $this->binding($existing, $offer, $connection, true);

                    return $existing;
                }
                $public = strtolower((string) Str::ulid());
                $code = 'SB-'.strtoupper(bin2hex(random_bytes(16)));
                $terms = OfferVersion::findOrFail($offer->current_version_id);
                $input = $this->input($terms, $code, 'ShopBonus '.$public);
                $operation = ShopifyCouponOperation::create([
                    'public_id' => $public, 'offer_id' => $offer->id, 'connection_id' => $connection->id, 'offer_version_id' => $terms->id,
                    'credential_generation' => $connection->credential_generation, 'verified_domain' => $connection->webshop->verified_domain,
                    'request_key' => $requestKey, 'code' => $code, 'input' => $input, 'state' => 'prepared', 'created_by' => $actor->id,
                ]);
                $this->auditOperation($operation, $offer, $actor);

                return $operation;
            });
        });
    }

    /** create is single attempt; reconcile never sends a mutation; repair reapplies frozen terms before import only. */
    public function execute(ShopifyCouponOperation $operation, User $actor, string $action): ShopifyCouponOperation
    {
        if (! in_array($action, ['create', 'reconcile', 'repair', 'import', 'deactivate'], true)) {
            throw new \InvalidArgumentException('Unknown Shopify coupon action.');
        }

        return Cache::lock('shopify-coupons:'.$operation->connection_id, 120)->block(0, function () use ($operation, $actor, $action): ShopifyCouponOperation {
            $operation = ShopifyCouponOperation::findOrFail($operation->id);
            $offer = Offer::findOrFail($operation->offer_id);
            $connection = WebshopConnection::findOrFail($operation->connection_id);
            $this->authorize($offer, $connection, $actor);
            $this->binding($operation, $offer, $connection, ! in_array($action, ['reconcile', 'deactivate'], true));
            if (in_array($action, ['create', 'repair', 'import'], true)) {
                $this->program($offer, $connection);
            }
            if (in_array($action, ['create', 'repair', 'deactivate'], true) && config('integrations.shopify_coupon_writes_enabled') !== true) {
                throw ApiException::conflict('Shopify coupon writes are disabled.', 'shopify_coupon_writes_disabled');
            }
            if ($action === 'create') {
                // A thrown timeout/error leaves create_unknown durable. Never automatically replay.
                if ($operation->state !== 'prepared') {
                    return $operation;
                }
                $lookup = $this->api->couponByCode($connection, $operation->code);
                if ($lookup['currency'] !== OfferVersion::findOrFail($operation->offer_version_id)->currency) {
                    throw ApiException::conflict('The Shopify store currency differs from the frozen offer.', 'shopify_coupon_currency_mismatch');
                }
                if ($lookup['coupon'] !== null) {
                    throw ApiException::conflict('Generated code already exists; no write was sent.', 'shopify_coupon_collision');
                }
                $this->intent($operation, 'create_unknown', $offer, $actor);
                $remote = $this->api->createDiscount($connection, $operation->input);
                $operation->forceFill(['remote_id' => $remote])->save();
            } elseif ($action === 'repair') {
                if ($operation->voucher_code_id !== null || ! in_array($operation->state, ['mismatch', 'verified', 'create_unknown', 'update_unknown'], true)) {
                    throw ApiException::conflict('Only owned unpublished codes may be repaired.', 'shopify_coupon_transition_invalid');
                }
                $lookup = $this->owned($operation, $connection);
                if ($lookup['currency'] !== OfferVersion::findOrFail($operation->offer_version_id)->currency) {
                    throw ApiException::conflict('The Shopify store currency differs from the frozen offer.', 'shopify_coupon_currency_mismatch');
                }
                // An uncertain update must be reconciled, never replayed automatically.
                if ($operation->state === 'update_unknown') {
                    throw ApiException::conflict('Reconcile the uncertain update first.', 'shopify_coupon_reconcile_required');
                }
                $this->intent($operation, 'update_unknown', $offer, $actor);
                $this->api->updateDiscount($connection, (string) $operation->remote_id, $operation->input);
            } elseif ($action === 'deactivate') {
                if ($operation->state === 'deactivated') {
                    return $operation;
                }
                if ($operation->state === 'deactivate_unknown') {
                    throw ApiException::conflict('Reconcile the uncertain deactivation first.', 'shopify_coupon_reconcile_required');
                }
                $this->owned($operation, $connection);
                // Stop local allocation under its own row lock BEFORE sending the external mutation.
                DB::transaction(function () use ($operation, $offer, $actor): void {
                    if ($operation->voucher_code_id !== null) {
                        $code = VoucherCode::whereKey($operation->voucher_code_id)->lockForUpdate()->firstOrFail();
                        if (! in_array($code->status, [VoucherCodeStatus::Available, VoucherCodeStatus::Invalid], true) || $code->uses_count !== 0 || $code->currentVoucher()->exists()) {
                            throw ApiException::conflict('Issued or reserved codes cannot be deactivated.', 'shopify_coupon_in_use');
                        }
                        $code->forceFill(['status' => VoucherCodeStatus::Invalid, 'invalidated_at' => now(), 'invalid_reason' => 'native_deactivation_requested'])->save();
                        VoucherEvent::create(['webshop_id' => $offer->webshop_id, 'type' => 'native_code_deactivation_requested', 'actor_user_id' => $actor->id,
                            'summary' => ['code_id' => $code->public_id, 'operation_id' => $operation->public_id]]);
                    }
                    $this->intent($operation, 'deactivate_unknown', $offer, $actor);
                });
                $this->api->deactivateDiscount($connection, (string) $operation->remote_id);
            }
            if ($operation->state === 'prepared') {
                return $operation;
            }
            $this->reconcile($operation, $offer, $connection, $actor);
            if ($action === 'import') {
                $this->import($operation, $offer, $connection, $actor);
            }

            return $operation->refresh();
        });
    }

    private function authorize(Offer $offer, WebshopConnection $connection, User $actor): void
    {
        $actor = User::findOrFail($actor->id);
        $membership = WebshopMembership::where('user_id', $actor->id)->where('webshop_id', $offer->webshop_id)->first();
        if ($membership === null || $connection->webshop_id !== $offer->webshop_id) {
            throw new ApiException('Not found.', 404, 'not_found');
        }
        if (! $actor->isActive() || ! $membership->allows(WorkspacePermission::OffersManage)
            || ! $membership->allows(WorkspacePermission::VouchersManage) || ! $membership->allows(WorkspacePermission::IntegrationsManage)) {
            throw new ApiException('Insufficient workspace permission.', 403, 'forbidden');
        }
        if ($connection->platform !== EcommercePlatform::Shopify || ! $connection->acceptsData() || ! $connection->webshop->isOwnershipVerified()) {
            throw ApiException::conflict('An unpaused Shopify connection and verified domain are required.', 'shopify_coupon_connection_unavailable');
        }
    }

    private function program(Offer $offer, WebshopConnection $connection): void
    {
        $p = DB::table('shopify_coupon_programs')->where('offer_id', $offer->id)->first();
        if ($p === null || ! $p->enabled || $p->connection_id !== $connection->id || $p->offer_version_id !== $offer->current_version_id
            || $p->credential_generation !== $connection->credential_generation || $p->verified_domain !== $connection->webshop->verified_domain
            || in_array($offer->status, [OfferStatus::Ended, OfferStatus::Archived], true)) {
            throw ApiException::conflict('Explicit opt-in for the current offer version and credentials is required.', 'shopify_coupon_opt_in_required');
        }
    }

    private function binding(ShopifyCouponOperation $op, Offer $offer, WebshopConnection $c, bool $current): void
    {
        if ($op->connection_id !== $c->id || $op->credential_generation !== $c->credential_generation || $op->verified_domain !== $c->webshop->verified_domain
            || ($current && $op->offer_version_id !== $offer->current_version_id)) {
            throw ApiException::conflict('The frozen offer or connection grant has changed.', 'shopify_coupon_binding_changed');
        }
    }

    /** @return array<string,mixed> */
    private function input(OfferVersion $v, #[\SensitiveParameter] string $code, string $title): array
    {
        if (! in_array($v->discount_type, ['percentage', 'fixed'], true) || $v->discount_value <= 0
            || ($v->discount_type === 'percentage' && $v->discount_value > 10000) || ! Money::isCurrency($v->currency)
            || Money::exponent($v->currency) !== 2 || $v->code_mode !== 'unique' || $v->combinable
            || ($v->products ?? []) !== [] || ($v->categories ?? []) !== [] || $v->max_discount_minor !== null
            || $v->claim_validity_days !== null || $v->redemption_validity_days !== null || ($v->min_order_minor ?? 0) < 0
            || $v->starts_at === null || $v->ends_at === null || $v->ends_at->isPast() || $v->starts_at->greaterThanOrEqualTo($v->ends_at)) {
            throw ApiException::conflict('These offer terms cannot be represented exactly by this Shopify adapter. Use unique store-wide fixed/percentage codes with absolute dates and no relative validity, caps or combination rules.', 'shopify_coupon_terms_unsupported');
        }
        $decimal = fn (int $minor): string => intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
        $value = $v->discount_type === 'percentage'
            ? ['percentage' => $v->discount_value / 10000]
            : ['discountAmount' => ['amount' => $decimal($v->discount_value), 'appliesOnEachItem' => false]];

        return ['title' => $title, 'code' => $code, 'context' => ['all' => 'ALL'], 'startsAt' => $v->starts_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'endsAt' => $v->ends_at->utc()->format('Y-m-d\TH:i:s\Z'), 'usageLimit' => 1, 'appliesOncePerCustomer' => false,
            'combinesWith' => ['orderDiscounts' => false, 'productDiscounts' => false, 'shippingDiscounts' => false],
            'minimumRequirement' => ($v->min_order_minor ?? 0) > 0 ? ['subtotal' => ['greaterThanOrEqualToSubtotal' => $decimal($v->min_order_minor)]] : null,
            'customerGets' => ['items' => ['all' => true], 'value' => $value, 'appliesOnOneTimePurchase' => true, 'appliesOnSubscription' => false]];
    }

    /** @return array{coupon:array<string,mixed>,currency:string} */
    private function owned(ShopifyCouponOperation $op, WebshopConnection $c): array
    {
        $lookup = $this->api->couponByCode($c, $op->code);
        $coupon = $lookup['coupon'];
        if ($coupon === null || ($coupon['discount']['title'] ?? null) !== 'ShopBonus '.$op->public_id
            || ($op->remote_id !== null && $coupon['id'] !== $op->remote_id)) {
            throw ApiException::conflict('Owned native coupon evidence is missing or differs. No mutation was sent.', 'shopify_coupon_ownership_unconfirmed');
        }
        if ($op->remote_id === null) {
            $op->forceFill(['remote_id' => $coupon['id']])->save();
        }

        return ['coupon' => $coupon, 'currency' => $lookup['currency']];
    }

    private function reconcile(ShopifyCouponOperation $op, Offer $offer, WebshopConnection $c, User $actor): void
    {
        $lookup = $this->owned($op, $c);
        if (in_array($op->state, ['deactivate_unknown', 'deactivated'], true)) {
            // A missing/deleted coupon is not confirmation of this exact requested mutation.
            if (($lookup['coupon']['discount']['status'] ?? null) === 'EXPIRED') {
                $op->forceFill(['state' => 'deactivated'])->save();
                $this->auditOperation($op, $offer, $actor);
            }

            return;
        }
        $terms = OfferVersion::findOrFail($op->offer_version_id);
        $code = new VoucherCode(['code' => $op->code, 'reusable' => false]);
        $result = app(NativeCouponTerms::class)->compare(EcommercePlatform::Shopify, $lookup['coupon'], $lookup['currency'], $terms, $code, null);
        $d = $lookup['coupon']['discount'];
        $sameDates = ($d['startsAt'] ?? null) !== null && ($d['endsAt'] ?? null) !== null
            && CarbonImmutable::parse($d['startsAt'])->equalTo(CarbonImmutable::parse($op->input['startsAt']))
            && CarbonImmutable::parse($d['endsAt'])->equalTo(CarbonImmutable::parse($op->input['endsAt']));
        $verified = $sameDates && ($d['asyncUsageCount'] ?? null) === 0 && $result['platform_validation'] === PlatformCouponVerification::VERIFIED;
        $op->forceFill(['state' => $op->voucher_code_id !== null ? ($verified ? 'imported' : 'imported_mismatch') : ($verified ? 'verified' : 'mismatch')])->save();
        if (! $verified && $op->voucher_code_id !== null) {
            DB::transaction(function () use ($op, $offer, $actor): void {
                $code = VoucherCode::whereKey($op->voucher_code_id)->lockForUpdate()->firstOrFail();
                $code->platform_validation = PlatformCouponVerification::MISMATCH;
                if ($code->status === VoucherCodeStatus::Available) {
                    $code->forceFill(['status' => VoucherCodeStatus::Invalid, 'invalidated_at' => now(), 'invalid_reason' => 'native_configuration_changed']);
                }
                $code->save();
                VoucherEvent::create(['webshop_id' => $offer->webshop_id, 'voucher_code_id' => $code->id, 'type' => 'native_configuration_changed',
                    'actor_user_id' => $actor->id, 'summary' => ['operation_id' => $op->public_id]]);
            });
        }
        $this->auditOperation($op, $offer, $actor);
    }

    private function import(ShopifyCouponOperation $op, Offer $offer, WebshopConnection $c, User $actor): void
    {
        DB::transaction(function () use ($op, $offer, $c, $actor): void {
            $offer = Offer::whereKey($offer->id)->lockForUpdate()->firstOrFail();
            $c = WebshopConnection::whereKey($c->id)->lockForUpdate()->firstOrFail();
            $this->authorize($offer, $c, $actor);
            $this->binding($op, $offer, $c, true);
            $this->program($offer, $c);
            if ($op->voucher_code_id !== null) {
                return;
            }
            if ($op->state !== 'verified') {
                throw ApiException::conflict('Exact unused native configuration readback is required before import.', 'shopify_coupon_readback_required');
            }
            $code = VoucherCode::create(['webshop_id' => $offer->webshop_id, 'offer_id' => $offer->id,
                'code' => $op->code, 'code_hash' => VoucherCode::hashFor($offer->webshop_id, $op->code), 'code_last4' => substr($op->code, -4),
                'platform_validation' => PlatformCouponVerification::VERIFIED]);
            $op->forceFill(['voucher_code_id' => $code->id, 'state' => 'imported'])->save();
            VoucherEvent::create(['webshop_id' => $offer->webshop_id, 'type' => 'native_code_imported', 'actor_user_id' => $actor->id,
                'summary' => ['code_id' => $code->public_id, 'operation_id' => $op->public_id, 'offer_version_id' => $op->offer_version_id,
                    'evidence_scope' => 'native_configuration_not_checkout_or_redemption']]);
            $this->auditOperation($op, $offer, $actor);
        });
    }

    private function intent(ShopifyCouponOperation $op, string $state, Offer $offer, User $actor): void
    {
        DB::transaction(function () use ($op, $state, $offer, $actor): void {
            $op->forceFill(['state' => $state])->save();
            $this->auditOperation($op, $offer, $actor);
        });
    }

    private function auditOperation(ShopifyCouponOperation $op, Offer $offer, User $actor): void
    {
        $this->audit->record('shopify_coupon.'.$op->state, category: AuditCategory::Voucher, resource: $op,
            webshopId: $offer->webshop_id, actorUserId: $actor->id, metadata: ['operation' => $op->public_id, 'state' => $op->state,
                'remote_id' => $op->remote_id, 'offer_version_id' => $op->offer_version_id]);
    }
}
