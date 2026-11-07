<?php

namespace App\Services\Vouchers;

use App\Enums\AuditCategory;
use App\Enums\ConnectionStatus;
use App\Enums\EcommercePlatform;
use App\Exceptions\ApiException;
use App\Http\Resources\OfferResource;
use App\Integrations\ConnectorRegistry;
use App\Integrations\Contracts\CouponLookup;
use App\Integrations\Http\WooCommerceApi;
use App\Models\Offer;
use App\Models\OfferVersion;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherCode;
use App\Models\VoucherEvent;
use App\Models\Webshop;
use App\Models\WebshopConnection;
use App\Services\Audit\AuditLogger;
use App\Support\Money;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Distinguishes "code exists in ShopBonus" from "webshop checkout accepts it"
 * (Trello #7 §8). Native APIs establish configuration matches only; actual
 * checkout acceptance and redemption need separate provider-backed evidence.
 */
class PlatformCouponVerification
{
    public const VERIFIED = 'verified';

    public const MANUALLY_CONFIRMED = 'manually_confirmed';

    public const PENDING = 'verification_pending';

    public const MERCHANT_REQUIRED = 'merchant_verification_required';

    public const UNSUPPORTED = 'unsupported_integration';

    public const SYNC_ERROR = 'sync_error';

    public const MISMATCH = 'mismatch';

    public function __construct(
        private readonly WooCommerceApi $woo,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{platform_validation: string, detail: array<string, mixed>}
     */
    public function verifyAgainstProvider(VoucherCode $code, ?OfferVersion $terms = null, ?Voucher $voucher = null): array
    {
        $code->loadMissing('offer.currentVersion');
        $terms ??= $code->offer?->currentVersion;
        $connection = $this->usableConnection($code->webshop_id);

        if ($connection === null) {
            return $this->persist($code, self::MERCHANT_REQUIRED, [
                'reason' => 'no_usable_connection',
                'message' => 'Connect and verify a webshop integration before automatic coupon checks.',
            ], 'provider_check', voucher: $voucher);
        }

        $registry = app(ConnectorRegistry::class);
        $adapter = $registry->isAvailable($connection->platform) ? $registry->for($connection->platform) : null;
        if ($connection->platform !== EcommercePlatform::WooCommerce && ! $adapter instanceof CouponLookup) {
            return $this->persist($code, self::UNSUPPORTED, [
                'reason' => 'coupon_api_unsupported',
                'platform' => $connection->platform->value,
                'message' => 'This platform has no ShopBonus coupon verification adapter. Confirm the code in your webshop admin, then mark it manually.',
            ], 'provider_check', voucher: $voucher);
        }

        try {
            if ($adapter instanceof CouponLookup) {
                $context = ['domain' => $connection->webshop->verified_domain, 'ownership_verified_at' => $connection->webshop->ownership_verified_at?->toIso8601String()];
                $lookup = $adapter->lookupCoupon($connection, $code->code);
                if ($lookup['coupon'] === null) {
                    return $this->persist($code, self::MERCHANT_REQUIRED, ['reason' => 'not_found_on_platform', 'platform' => $connection->platform->value,
                        'evidence_scope' => 'native_configuration_not_checkout_or_redemption'], 'provider_check', voucher: $voucher);
                }
                $result = app(NativeCouponTerms::class)->compare($connection->platform, $lookup['coupon'], $lookup['currency'], $terms, $code, $voucher);

                return $this->persistNative($code, $connection, $context, $terms, $voucher, $result);
            }
            $coupon = $this->woo->findCouponByCode($connection, $code->code);
            if ($coupon === null) {
                return $this->persist($code, self::MERCHANT_REQUIRED, [
                    'reason' => 'not_found_on_platform',
                    'platform' => $connection->platform->value,
                    'message' => 'The code was not found in the webshop coupon list. Create it on the platform or mark merchant verification.',
                ], 'provider_check', voucher: $voucher);
            }

            $comparison = $this->compareTerms($coupon, $terms);
            $status = $comparison['matches'] ? self::VERIFIED : self::MISMATCH;

            return $this->persist($code, $status, [
                'reason' => $status === self::VERIFIED ? 'provider_match' : 'provider_mismatch',
                'platform' => $connection->platform->value,
                'provider_coupon' => $comparison['provider'],
                'differences' => $comparison['differences'],
            ], 'provider_check', voucher: $voucher);
        } catch (ApiException $e) {
            return $this->persist($code, self::SYNC_ERROR, [
                'reason' => $e->errorCode,
                'platform' => $connection->platform->value,
                'message' => 'Platform coupon check failed. Retry after fixing the integration, or confirm manually.',
            ], 'provider_check', voucher: $voucher);
        }
    }

    /** A slow native read must not certify newly edited terms or a rotated/disconnected source.
     * @param  array<string,mixed>  $context
     * @param  array{platform_validation:string,detail:array<string,mixed>}  $result
     * @return array{platform_validation:string,detail:array<string,mixed>}
     */
    private function persistNative(VoucherCode $code, WebshopConnection $connection, array $context, ?OfferVersion $terms, ?Voucher $voucher, array $result): array
    {
        return DB::transaction(function () use ($code, $connection, $context, $terms, $voucher, $result) {
            $shop = Webshop::whereKey($code->webshop_id)->lockForUpdate()->firstOrFail();
            $source = WebshopConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
            // Match OfferService's version-before-offer lock order for concurrent draft edits.
            $currentTerms = $voucher === null && $terms !== null ? OfferVersion::whereKey($terms->id)->lockForUpdate()->first() : null;
            $offer = Offer::whereKey($code->offer_id)->lockForUpdate()->first();
            $current = VoucherCode::whereKey($code->id)->lockForUpdate()->firstOrFail();
            $termsChanged = $voucher === null && (($terms === null && $offer?->current_version_id !== null)
                || ($terms !== null && ($offer === null || $offer->current_version_id !== $terms->id || $currentTerms === null
                    || Arr::only(OfferResource::terms($currentTerms), OfferVersion::TERM_FIELDS) !== Arr::only(OfferResource::terms($terms), OfferVersion::TERM_FIELDS))));
            if ($current->webshop_id !== $code->webshop_id || $current->offer_id !== $code->offer_id || $current->code_hash !== $code->code_hash
                || $source->webshop_id !== $code->webshop_id || $source->credential_generation !== $connection->credential_generation
                || $source->external_shop_ref !== $connection->external_shop_ref || ! $source->status->receivesEvents() || $source->paused_at !== null
                || ! $shop->isOwnershipVerified() || $shop->verified_domain !== $context['domain']
                || $shop->ownership_verified_at?->toIso8601String() !== $context['ownership_verified_at']
                || $termsChanged) {
                return $this->persist($current, self::SYNC_ERROR, ['reason' => 'verification_context_changed', 'platform' => $connection->platform->value,
                    'message' => 'The integration or offer changed during verification. Retry against the current configuration.'], 'provider_check', voucher: $voucher);
            }

            return $this->persist($current, $result['platform_validation'], $result['detail'], 'provider_check', voucher: $voucher);
        });
    }

    /**
     * Controlled manual confirmation when the provider cannot prove the coupon.
     *
     * @return array{platform_validation: string, detail: array<string, mixed>}
     */
    public function confirmManually(VoucherCode $code, User $actor, string $note, ?Voucher $voucher = null): array
    {
        return $this->persist($code, self::MANUALLY_CONFIRMED, [
            'reason' => 'merchant_confirmed',
            'note' => $note,
            'confirmed_by' => $actor->public_id,
        ], 'manual_confirm', $actor->id, $voucher);
    }

    /** @return array{platform_validation: string, detail: array<string, mixed>} */
    public function verifyHistoricalVoucher(Voucher $voucher): array
    {
        // Frozen snapshot is authoritative, even after later offer-version edits.
        $terms = (new OfferVersion)->forceFill($voucher->terms_snapshot);

        return $this->verifyAgainstProvider($voucher->code, $terms, $voucher);
    }

    private function usableConnection(int $webshopId): ?WebshopConnection
    {
        return WebshopConnection::query()
            ->where('webshop_id', $webshopId)
            ->where('status', '!=', ConnectionStatus::Disconnected)
            ->latest('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $coupon
     * @return array{matches: bool, differences: list<string>, provider: array<string, mixed>}
     */
    private function compareTerms(array $coupon, ?OfferVersion $terms): array
    {
        $providerType = match ((string) ($coupon['discount_type'] ?? '')) {
            'percent', 'percent_product' => 'percentage',
            'fixed_cart', 'fixed_product' => 'fixed',
            default => (string) ($coupon['discount_type'] ?? 'unknown'),
        };
        $amount = isset($coupon['amount']) ? (string) $coupon['amount'] : null;
        $min = isset($coupon['minimum_amount']) && $coupon['minimum_amount'] !== '' ? (string) $coupon['minimum_amount'] : null;
        $max = isset($coupon['maximum_amount']) && $coupon['maximum_amount'] !== '' ? (string) $coupon['maximum_amount'] : null;

        $provider = [
            'discount_type' => $providerType,
            'amount' => $amount,
            'minimum_amount' => $min,
            'maximum_amount' => $max,
            'date_expires' => $coupon['date_expires'] ?? $coupon['date_expires_gmt'] ?? null,
            'usage_limit' => $coupon['usage_limit'] ?? null,
        ];

        if ($terms === null) {
            return ['matches' => true, 'differences' => [], 'provider' => $provider];
        }

        $differences = [];
        if ($terms->discount_type !== $providerType && $providerType !== 'unknown') {
            $differences[] = 'discount_type';
        }

        try {
            if ($amount !== null) {
                // Percentage offers store basis points (1000 = 10%); Woo percent is "10.00".
                // Interpreting the percent string as EUR minor units yields the same integer.
                $providerValue = Money::toMinor($amount, $terms->currency ?? 'EUR');
                if ($providerValue !== (int) $terms->discount_value) {
                    $differences[] = 'discount_value';
                }
            }
            if ($terms->min_order_minor !== null && $min !== null) {
                if (Money::toMinor($min, $terms->currency ?? 'EUR') !== (int) $terms->min_order_minor) {
                    $differences[] = 'min_order';
                }
            }
            if ($terms->max_discount_minor !== null && $max !== null) {
                if (Money::toMinor($max, $terms->currency ?? 'EUR') !== (int) $terms->max_discount_minor) {
                    $differences[] = 'max_discount';
                }
            }
        } catch (\InvalidArgumentException) {
            $differences[] = 'amount_parse';
        }

        return ['matches' => $differences === [], 'differences' => $differences, 'provider' => $provider];
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array{platform_validation: string, detail: array<string, mixed>}
     */
    private function persist(VoucherCode $code, string $status, array $detail, string $mode, ?int $actorUserId = null, ?Voucher $voucher = null): array
    {
        return DB::transaction(function () use ($code, $status, $detail, $mode, $actorUserId, $voucher) {
            $fresh = VoucherCode::whereKey($code->id)->lockForUpdate()->firstOrFail();
            $from = $fresh->platform_validation;
            $detail['scope'] = $voucher === null ? 'inventory' : 'historical_voucher';
            $detail['voucher_id'] = $voucher?->public_id;
            if ($voucher === null) {
                $fresh->platform_validation = $status;
                $fresh->save();
            }

            VoucherEvent::create([
                'webshop_id' => $fresh->webshop_id,
                'voucher_code_id' => $fresh->id,
                'voucher_id' => $voucher?->id,
                'type' => 'platform_validation_'.$mode,
                'from_status' => $voucher === null ? $from : null,
                'to_status' => $status,
                'actor_user_id' => $actorUserId,
                'summary' => $detail,
            ]);

            $this->audit->record('codes.platform_validated', category: AuditCategory::Voucher, resource: $fresh, webshopId: $fresh->webshop_id, metadata: [
                'mode' => $mode,
                'platform_validation' => $status,
                'reason' => $detail['reason'] ?? null,
                'scope' => $detail['scope'],
                'voucher_id' => $voucher?->public_id,
            ]);

            return ['platform_validation' => $status, 'detail' => $detail];
        });
    }
}
