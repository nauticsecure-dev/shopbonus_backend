<?php

namespace App\Services\Vouchers;

use App\Enums\EcommercePlatform;
use App\Exceptions\ApiException;
use App\Integrations\Connectors\BigCommerceCatalog;
use App\Integrations\Connectors\EcwidCatalog;
use App\Integrations\Connectors\ShopifyCatalog;
use App\Models\OfferVersion;
use App\Models\Voucher;
use App\Models\VoucherCode;
use App\Support\Money;
use Carbon\CarbonImmutable;

/** Conservative native configuration comparison, never a simulated checkout or redemption. */
class NativeCouponTerms
{
    /** @param array<string,mixed> $raw
     * @return array{platform_validation:string,detail:array<string,mixed>}
     */
    public function compare(EcommercePlatform $platform, array $raw, string $currency, ?OfferVersion $terms, VoucherCode $code, ?Voucher $voucher): array
    {
        $unknown = [];
        $differences = [];
        $start = null;
        $expiration = null;
        if ($platform === EcommercePlatform::Shopify) {
            $d = $raw['discount'] ?? [];
            $mapped = ShopifyCatalog::coupon($raw, $currency);
            if (($d['__typename'] ?? null) !== 'DiscountCodeBasic') {
                $unknown[] = 'discount_kind';
            } else {
                if (($d['context']['__typename'] ?? null) !== 'DiscountBuyerSelectionAll') {
                    $unknown[] = 'customer_context';
                }
                if (($d['customerGets']['items']['__typename'] ?? null) !== 'AllDiscountItems') {
                    $unknown[] = 'product_scope';
                }
                if (($d['customerGets']['appliesOnOneTimePurchase'] ?? null) !== true || ($d['customerGets']['appliesOnSubscription'] ?? null) !== false) {
                    $unknown[] = 'purchase_type';
                }
                if (($d['appliesOncePerCustomer'] ?? null) !== false) {
                    $unknown[] = 'customer_usage';
                }
                foreach (['orderDiscounts', 'productDiscounts', 'shippingDiscounts'] as $key) {
                    if (! is_bool($d['combinesWith'][$key] ?? null)) {
                        $unknown[] = 'combination_rules';
                    } elseif ($d['combinesWith'][$key]) {
                        $differences[] = 'combinable';
                    }
                }
                if (! array_key_exists('usageLimit', $d) || ($d['usageLimit'] !== null && ! $this->count($d['usageLimit']))) {
                    $unknown[] = 'usage_limit';
                }
                if (! $this->count($d['asyncUsageCount'] ?? null)) {
                    $unknown[] = 'usage_count';
                }
                if (! array_key_exists('minimumRequirement', $d)) {
                    $unknown[] = 'minimum_spend';
                }
                $minimum = $d['minimumRequirement'] ?? null;
                if ($minimum !== null && (($minimum['__typename'] ?? null) !== 'DiscountMinimumSubtotal'
                    || ($minimum['greaterThanOrEqualToSubtotal']['currencyCode'] ?? null) !== $currency
                    || $this->minor($minimum['greaterThanOrEqualToSubtotal']['amount'] ?? null, $currency) === null)) {
                    $unknown[] = 'minimum_spend';
                }
                $start = $this->date($d['startsAt'] ?? null, false);
                if (! array_key_exists('endsAt', $d)) {
                    $unknown[] = 'expiration';
                }
                $expiration = $this->date($d['endsAt'] ?? null);
                if ($mapped !== null && $minimum !== null) {
                    $mapped['minimum_spend_minor'] = $this->minor($minimum['greaterThanOrEqualToSubtotal']['amount'] ?? null, $currency);
                }
            }
        } elseif ($platform === EcommercePlatform::BigCommerce) {
            $mapped = BigCommerceCatalog::coupon($raw, $currency);
            foreach (['restricted_to', 'shipping_methods'] as $key) {
                if (! array_key_exists($key, $raw) || $raw[$key] !== []) {
                    $unknown[] = $key;
                }
            }
            if (($raw['applies_to']['entity'] ?? null) !== 'categories' || ($raw['applies_to']['ids'] ?? null) !== [0]) {
                $unknown[] = 'product_scope';
            }
            foreach (['max_uses', 'max_uses_per_customer', 'num_uses'] as $key) {
                if (! $this->count($raw[$key] ?? null)) {
                    $unknown[] = $key;
                }
            }
            if (($raw['max_uses_per_customer'] ?? null) !== 0) {
                $unknown[] = 'customer_usage';
            }
            if (! is_bool($raw['enabled'] ?? null)) {
                $unknown[] = 'status';
            }
            if ($this->minor($raw['min_purchase'] ?? null, $currency) === null) {
                $unknown[] = 'minimum_spend';
            }
            if (! array_key_exists('expires', $raw) || ! is_string($raw['expires'])) {
                $unknown[] = 'expiration';
            } elseif ($raw['expires'] !== '') {
                $expiration = $this->date($raw['expires'], false);
            }
        } else {
            $mapped = EcwidCatalog::coupon($raw, $currency);
            if (! in_array($raw['discountType'] ?? null, ['ABS', 'PERCENT'], true)) {
                $unknown[] = 'discount_kind';
            }
            if (($raw['applicationLimit'] ?? null) !== 'UNLIMITED' || ($raw['repeatCustomerOnly'] ?? null) !== false) {
                $unknown[] = 'customer_context';
            }
            if (! in_array($raw['usesLimit'] ?? null, ['UNLIMITED', 'SINGLE'], true)) {
                $unknown[] = 'usage_limit';
            }
            if (! $this->count($raw['orderCount'] ?? null)) {
                $unknown[] = 'usage_count';
            }
            foreach (['catalogLimit', 'shippingLimit'] as $key) {
                $value = $raw[$key] ?? [];
                // Native optional empty constraints are unrestricted; malformed/nonempty constraints need manual review.
                if (! is_array($value) || array_filter($value, fn ($v) => $v !== []) !== []) {
                    $unknown[] = $key;
                }
            }
            if (array_key_exists('totalLimit', $raw) && $this->minor($raw['totalLimit'], $currency) === null) {
                $unknown[] = 'minimum_spend';
            }
            $start = $this->date($raw['launchDate'] ?? null, false);
            $expiration = $this->date($raw['expirationDate'] ?? null);
        }
        if ($mapped !== null) {
            $mapped['expires_at'] = $expiration;
        }
        if ($mapped === null) {
            $unknown[] = 'discount_kind';
        }
        if ($terms === null) {
            $unknown[] = 'offer_terms';
        }
        if ($mapped !== null && $terms !== null) {
            $type = match ($mapped['discount_type']) {
                'percent' => 'percentage', 'fixed_cart' => 'fixed', default => null
            };
            if ($type === null) {
                $unknown[] = 'discount_scope';
            } elseif ($type !== $terms->discount_type) {
                $differences[] = 'discount_type';
            }
            $value = $type === 'percentage' ? $mapped['percent_bps'] : $mapped['amount_minor'];
            if (! is_int($value)) {
                $unknown[] = 'discount_value';
            } elseif ($value !== $terms->discount_value) {
                $differences[] = 'discount_value';
            }
            if ($currency !== $terms->currency) {
                $differences[] = 'currency';
            }
            if (($mapped['minimum_spend_minor'] ?? 0) !== ($terms->min_order_minor ?? 0)) {
                $differences[] = 'min_order';
            }
            if ($terms->max_discount_minor !== null) {
                $unknown[] = 'max_discount_not_provable';
            }
            if (($terms->products ?? []) !== [] || ($terms->categories ?? []) !== []) {
                $unknown[] = 'offer_product_scope';
            }
            if ($terms->combinable) {
                $unknown[] = 'combination_rules';
            }
            if ($mapped['status'] === null) {
                $unknown[] = 'status';
            } elseif ($mapped['status'] !== 'active') {
                $differences[] = 'inactive';
            }
            if ($start !== null && $start->isFuture()) {
                $differences[] = 'not_started';
            }
            $expires = $mapped['expires_at'];
            if ($expires !== null) {
                if ($expires->lessThanOrEqualTo(now())) {
                    $differences[] = 'expired';
                }
                $required = $terms->ends_at;
                if ($voucher !== null && $voucher->expires_at !== null) {
                    $required = $voucher->expires_at;
                }
                if ($required !== null && $expires->lessThan($required)) {
                    $differences[] = 'expiration';
                }
                if ($required === null && $terms->redemption_validity_days !== null && $expires->lessThan(now()->addDays($terms->redemption_validity_days))) {
                    $differences[] = 'expiration';
                }
            }
            $limit = $mapped['usage_limit'];
            if (! $code->reusable && $limit !== 1) {
                $differences[] = 'usage_limit';
            }
            if ($code->reusable && $limit !== null) {
                $unknown[] = 'reusable_capacity';
            }
            if ($limit !== null && $mapped['usage_count'] >= $limit) {
                $differences[] = 'usage_exhausted';
            }
        }
        // Only safe normalized fields leave the verifier; never raw codes, target customers or provider payloads.
        $provider = $mapped === null ? [] : array_intersect_key($mapped, array_flip(['external_id', 'discount_type', 'amount_minor', 'percent_bps', 'currency', 'minimum_spend_minor', 'usage_limit', 'usage_count', 'status', 'expires_at']));
        if (($provider['expires_at'] ?? null) instanceof CarbonImmutable) {
            $provider['expires_at'] = $provider['expires_at']->toIso8601String();
        }
        $status = $differences !== [] ? PlatformCouponVerification::MISMATCH : ($unknown !== [] ? PlatformCouponVerification::MERCHANT_REQUIRED : PlatformCouponVerification::VERIFIED);

        return ['platform_validation' => $status, 'detail' => ['reason' => match ($status) {
            PlatformCouponVerification::VERIFIED => 'provider_match', PlatformCouponVerification::MISMATCH => 'provider_mismatch', default => 'provider_constraints_unverified'
        },
            'platform' => $platform->value, 'provider_coupon' => $provider, 'differences' => array_values(array_unique($differences)),
            'unverified_constraints' => array_values(array_unique($unknown)), 'evidence_scope' => 'native_configuration_not_checkout_or_redemption']];
    }

    private function count(mixed $v): bool
    {
        return is_int($v) && $v >= 0 && $v < 4_000_000_000;
    }

    private function minor(mixed $v, string $currency): ?int
    {
        if ((! is_int($v) && ! is_string($v)) || ! preg_match('/\A[0-9]{1,12}(?:\.[0-9]{1,6})?\z/D', (string) $v)) {
            return null;
        }
        try {
            return Money::toMinor((string) $v, $currency);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private function date(mixed $v, bool $nullable = true): ?CarbonImmutable
    {
        if ($v === null && $nullable) {
            return null;
        }
        if (! is_string($v) || strlen($v) > 64 || $v === '') {
            throw new ApiException('The native coupon date is invalid.', 502, 'provider_coupon_invalid');
        }
        if (! preg_match('/\A(?:[0-9]{4}-[0-9]{2}-[0-9]{2}[T ][0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,9})? ?(?:Z|[+-][0-9]{2}:?[0-9]{2})|[A-Za-z]{3}, [0-9]{2} [A-Za-z]{3} [0-9]{4} [0-9]{2}:[0-9]{2}:[0-9]{2} [+-][0-9]{4})\z/D', $v)) {
            throw new ApiException('The native coupon date is invalid.', 502, 'provider_coupon_invalid');
        }
        try {
            $date = new \DateTimeImmutable($v);
            $errors = \DateTimeImmutable::getLastErrors();
            if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                throw new \RuntimeException;
            }

            return CarbonImmutable::instance($date)->utc();
        } catch (\Throwable) {
            throw new ApiException('The native coupon date is invalid.', 502, 'provider_coupon_invalid');
        }
    }
}
