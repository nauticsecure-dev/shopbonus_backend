<?php

namespace App\Services\Vouchers;

use App\Enums\EcommercePlatform;
use App\Exceptions\ApiException;
use App\Integrations\Connectors\BigCommerceCatalog;
use App\Integrations\Connectors\EcwidCatalog;
use App\Integrations\Connectors\MagentoCatalog;
use App\Integrations\Connectors\OpenCartCatalog;
use App\Integrations\Connectors\PrestaShopCatalog;
use App\Integrations\Connectors\ShopifyCatalog;
use App\Integrations\Connectors\ShopwareCatalog;
use App\Integrations\Connectors\WixCatalog;
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
        $capacityKnown = true;
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
        } elseif ($platform === EcommercePlatform::Wix) {
            $spec = $raw['specification'];
            $mapped = WixCatalog::coupon($raw, $currency);
            if (array_diff(array_keys($spec), ['name', 'code', 'active', 'startTime', 'expirationTime', 'scope',
                'percentOffRate', 'moneyOffAmount', 'minimumSubtotal', 'usageLimit', 'limitPerCustomer',
                'limitedToOneItem', 'appliesToSubscriptions', 'discountedCycleCount']) !== []) {
                $unknown[] = 'coupon_options';
            }
            if (isset($spec['percentOffRate'], $spec['moneyOffAmount'])) {
                $unknown[] = 'discount_kind';
            }
            // In Wix, the minimum-subtotal mode applies the fixed discount once to the cart.
            if (($mapped['discount_type'] ?? null) === 'fixed_cart' && ($mapped['minimum_spend_minor'] ?? 0) <= 0) {
                $unknown[] = 'discount_scope';
            }
            $scope = $spec['scope'] ?? null;
            if (! is_array($scope) || ($scope['namespace'] ?? null) !== 'stores'
                || ($scope['group'] ?? null) !== null || array_diff(array_keys($scope), ['namespace', 'group']) !== []
                || ($spec['limitedToOneItem'] ?? false) !== false) {
                $unknown[] = 'product_scope';
            }
            if (($spec['appliesToSubscriptions'] ?? false) !== false || isset($spec['discountedCycleCount'])) {
                $unknown[] = 'purchase_type';
            }
            if (! in_array($spec['limitPerCustomer'] ?? null, [null, 0], true)) {
                $unknown[] = 'customer_usage';
            }
            if (isset($spec['usageLimit']) && (! $this->count($spec['usageLimit']) || $spec['usageLimit'] === 0)) {
                $unknown[] = 'usage_limit';
            }
            if (! $this->count($raw['numberOfUsages'] ?? null)) {
                $unknown[] = 'usage_count';
            }
            if (! is_bool($spec['active'] ?? null) || ! is_bool($raw['expired'] ?? null)) {
                $unknown[] = 'status';
            }
            if (isset($spec['minimumSubtotal']) && $this->minor($spec['minimumSubtotal'], $currency) === null) {
                $unknown[] = 'minimum_spend';
            }
            $start = $this->wixDate($spec['startTime'] ?? null);
            $expiration = $this->wixDate($spec['expirationTime'] ?? null, nullable: true);
        } elseif (in_array($platform, [EcommercePlatform::Ecwid, EcommercePlatform::LightspeedE], true)) {
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
        } elseif ($platform === EcommercePlatform::OpenCart) {
            $coupon = $raw['coupon'] ?? [];
            $mapped = OpenCartCatalog::coupon($coupon, $currency);
            foreach (['product_ids', 'category_ids'] as $field) {
                if (($coupon[$field] ?? null) !== []) {
                    $unknown[] = $field;
                }
            }
            foreach (['logged', 'shipping', 'uses_customer'] as $field) {
                if (($coupon[$field] ?? null) !== 0) {
                    $unknown[] = $field;
                }
            }
            foreach (['usage_count', 'uses_total'] as $field) {
                if (! $this->count($coupon[$field] ?? null)) {
                    $unknown[] = $field;
                    $capacityKnown = false;
                }
            }
            $totals = $raw['enabled_totals'] ?? null;
            if (! is_array($totals) || ! array_is_list($totals) || array_filter($totals, fn ($item) => ! is_string($item)) !== [] || ! in_array('opencart:coupon', $totals, true)
                || array_diff($totals, ['opencart:coupon', 'opencart:shipping', 'opencart:sub_total', 'opencart:tax', 'opencart:total']) !== []) {
                $unknown[] = 'combination_rules';
            }
            if (! array_key_exists('starts_at', $coupon) || ! array_key_exists('expires_at', $coupon)) {
                $unknown[] = 'rule_dates';
            }
            $start = $this->date($coupon['starts_at'] ?? null);
            $expiration = $this->date($coupon['expires_at'] ?? null);
            $mapped['minimum_spend_minor'] = $this->minor($coupon['total'] ?? null, $currency);
            if ($mapped['minimum_spend_minor'] === null) {
                $unknown[] = 'minimum_spend';
            }
            $mapped['status'] = match ($coupon['status'] ?? null) {
                1 => 'active', 0 => 'inactive', default => null
            };
        } elseif ($platform === EcommercePlatform::PrestaShop) {
            $rule = $raw['rule'] ?? [];
            $zone = $raw['timezone'] ?? '';
            if (! is_string($zone) || ! in_array($zone, timezone_identifiers_list(), true)) {
                throw new ApiException('The native coupon response is invalid.', 502, 'provider_coupon_invalid');
            }
            $mapped = PrestaShopCatalog::coupon($rule, $currency, $raw['currency_id'], $zone);
            // Only a complete empty order-rule history in a single shop establishes unused capacity.
            // With any prior history, remaining quantity cannot reconstruct the original limit safely.
            $capacityKnown = ($raw['recorded_uses'] ?? null) === 0 && ($raw['single_shop'] ?? null) === true;
            if (! $capacityKnown) {
                $unknown[] = 'original_usage_capacity';
            }
            foreach (['country_restriction', 'carrier_restriction', 'group_restriction', 'product_restriction', 'shop_restriction', 'free_shipping', 'reduction_exclude_special', 'partial_use', 'minimum_amount_shipping'] as $field) {
                if (! in_array($rule[$field] ?? null, [0, '0', false], true)) {
                    $unknown[] = $field;
                }
            }
            if (! in_array($rule['id_customer'] ?? null, [0, '0'], true) || ! in_array($rule['gift_product'] ?? null, [0, '0'], true)
                || ! in_array($rule['reduction_product'] ?? null, [0, '0'], true)) {
                $unknown[] = 'discount_scope';
            }
            // A restriction flag does not expose its combination allowlist. Only the absence of other active rules proves this narrow configuration.
            if (($raw['other_active_rules'] ?? null) !== 0) {
                $unknown[] = 'combination_rules';
            }
            $perUser = $this->nativeCount($rule['quantity_per_user'] ?? null);
            if ($perUser === null || $perUser === 0) {
                $unknown[] = 'customer_usage';
            }
            $start = $this->localMoment($rule['date_from'] ?? null, $zone);
            $expiration = $this->localMoment($rule['date_to'] ?? null, $zone);
            $remaining = $this->nativeCount($rule['quantity'] ?? null);
            if ($remaining === null) {
                $capacityKnown = false;
                $unknown[] = 'remaining_capacity';
            } elseif ($remaining === 0) {
                $differences[] = 'usage_exhausted';
            }
            if ($mapped !== null) {
                $mapped['minimum_spend_minor'] = (string) ($rule['minimum_amount_currency'] ?? '') === $raw['currency_id'] ? $this->minor($rule['minimum_amount'] ?? null, $currency) : null;
                if ($mapped['minimum_spend_minor'] === null) {
                    $unknown[] = 'minimum_spend';
                }
                $mapped['usage_count'] = $capacityKnown ? 0 : null;
                $mapped['usage_limit'] = $capacityKnown ? $remaining : null;
                $mapped['remaining_uses'] = $remaining;
                if (! in_array($rule['active'] ?? null, [0, 1, '0', '1', true, false], true)) {
                    $mapped['status'] = null;
                }
            }
        } elseif ($platform === EcommercePlatform::Ccv) {
            // CCV does not expose coupon combination policy or historical usage in this resource.
            $unknown[] = 'combination_rules';
            $capacityKnown = false;
            $unknown[] = 'historical_usage';
            foreach (['products', 'brands', 'suppliers', 'categories'] as $field) {
                if (($raw[$field] ?? null) !== []) {
                    $unknown[] = $field;
                }
            }
            if (($raw['productwhitelist'] ?? null) !== false || ($raw['givesfreeshipping'] ?? null) !== false) {
                $unknown[] = 'discount_scope';
            }
            if (! is_bool($raw['onetimeuse'] ?? null)) {
                $unknown[] = 'usage_limit';
            } elseif (! $code->reusable && ! $raw['onetimeuse']) {
                $differences[] = 'usage_limit';
            }
            foreach (['begindate', 'enddate'] as $field) {
                if (! array_key_exists($field, $raw)) {
                    $unknown[] = 'rule_dates';
                }
            }
            $start = $this->localDay($raw['begindate'] ?? null, 'UTC');
            // Inclusive native end day, per CCV's coupon documentation.
            $expiration = $this->localDay($raw['enddate'] ?? null, 'UTC', end: true);
            $kind = match ($raw['type'] ?? null) {
                'Procent' => 'percent', 'Price' => 'fixed_cart', default => null
            };
            $minimum = $this->minor($raw['minimumprice'] ?? null, $currency);
            if ($minimum === null) {
                $unknown[] = 'minimum_spend';
            }
            $mapped = ['external_id' => (string) $raw['id'], 'discount_type' => $kind, 'currency' => $currency,
                'amount_minor' => $kind === 'fixed_cart' ? $this->minor($raw['discount'] ?? null, $currency) : null,
                'percent_bps' => $kind === 'percent' ? $this->basisPoints($raw['discount'] ?? null) : null,
                'minimum_spend_minor' => $minimum, 'status' => 'active', 'usage_limit' => null, 'usage_count' => null, 'expires_at' => $expiration];
        } elseif ($platform === EcommercePlatform::Shopware6) {
            $mapped = ShopwareCatalog::coupon($raw, $currency);
            foreach (['personaRules', 'personaCustomers', 'orderRules', 'cartRules', 'setgroups'] as $field) {
                if (($raw[$field] ?? null) !== []) {
                    $unknown[] = $field;
                }
            }
            foreach (['useIndividualCodes', 'useSetGroups', 'customerRestriction'] as $field) {
                if (($raw[$field] ?? null) !== false) {
                    $unknown[] = $field;
                }
            }
            if (($raw['useCodes'] ?? null) !== true || ($raw['preventCombination'] ?? null) !== true || ($raw['exclusive'] ?? null) !== true) {
                $unknown[] = 'combination_rules';
            }
            foreach (['maxRedemptionsGlobal', 'maxRedemptionsPerCustomer'] as $field) {
                if (! array_key_exists($field, $raw) || ($raw[$field] !== null && ! $this->count($raw[$field]))) {
                    $unknown[] = 'usage_limit';
                }
            }
            if (! in_array($raw['maxRedemptionsPerCustomer'] ?? null, [null, 0], true)) {
                $unknown[] = 'customer_usage';
            }
            if (! $this->count($raw['orderCount'] ?? null)) {
                $unknown[] = 'usage_count';
            }
            if (($raw['customFields'] ?? []) !== [] || ($raw['extensions'] ?? []) !== []) {
                $unknown[] = 'extension_constraints';
            }
            $discount = $raw['discounts'][0] ?? [];
            if (($discount['considerAdvancedRules'] ?? null) !== false || ($discount['discountRules'] ?? null) !== []
                || ($discount['promotionDiscountPrices'] ?? null) !== []) {
                $unknown[] = 'discount_scope';
            }
            foreach (['maxValue', 'sorterKey', 'applierKey', 'usageKey', 'pickerKey'] as $field) {
                if (! array_key_exists($field, $discount) || ! in_array($discount[$field], [null, ''], true)) {
                    $unknown[] = 'discount_options';
                }
            }
            if (! array_key_exists('validFrom', $raw) || ! array_key_exists('validUntil', $raw)) {
                $unknown[] = 'rule_dates';
            }
            $start = $this->date($raw['validFrom'] ?? null);
            $expiration = $this->date($raw['validUntil'] ?? null);
            if ($mapped !== null) {
                $mapped['minimum_spend_minor'] = 0;
            }
        } elseif ($platform === EcommercePlatform::Magento) {
            $rule = $raw['rule'] ?? [];
            $coupon = $raw['coupon'] ?? [];
            $store = $raw['store'] ?? [];
            $zone = $store['timezone'] ?? null;
            if (! is_string($zone) || ! in_array($zone, timezone_identifiers_list(), true)) {
                $unknown[] = 'store_timezone';
                $zone = 'UTC';
            }
            $mapped = MagentoCatalog::coupon($rule, $coupon, $currency, $zone);
            $groups = $raw['customer_groups'] ?? [];
            $allowed = $rule['customer_group_ids'] ?? [];
            sort($groups);
            if (! is_array($allowed) || array_filter($allowed, fn ($id) => ! is_int($id)) !== []) {
                $unknown[] = 'customer_groups';
            } else {
                sort($allowed);
                if ($groups !== $allowed) {
                    $unknown[] = 'customer_groups';
                }
            }
            if (! is_int($store['website_id'] ?? null) || ($rule['website_ids'] ?? null) !== [$store['website_id']]) {
                $unknown[] = 'website_scope';
            }
            if (($store['default_display_currency_code'] ?? null) !== $currency) {
                $unknown[] = 'display_currency';
            }
            foreach (['usage_limit', 'usage_per_customer', 'times_used'] as $field) {
                if (! $this->count($coupon[$field] ?? null)) {
                    $unknown[] = $field;
                }
            }
            if (($coupon['usage_per_customer'] ?? null) !== 0 || ($rule['uses_per_customer'] ?? null) !== 0) {
                $unknown[] = 'customer_usage';
            }
            if (! $this->count($rule['uses_per_coupon'] ?? null)) {
                $unknown[] = 'usage_limit';
            }
            if (($rule['use_auto_generation'] ?? null) !== false || ($rule['apply_to_shipping'] ?? null) !== false
                || ! in_array($rule['simple_free_shipping'] ?? null, ['NONE', 0, '0'], true)) {
                $unknown[] = 'discount_options';
            }
            if (($rule['stop_rules_processing'] ?? null) !== true || ($raw['other_rules'] ?? null) !== 0) {
                $unknown[] = 'combination_rules';
            }
            if (! in_array($rule['discount_qty'] ?? null, [null, 0, '0', '0.0000'], true) || ($rule['discount_step'] ?? null) !== 0 || ($rule['product_ids'] ?? []) !== []) {
                $unknown[] = 'discount_scope';
            }
            foreach ([$coupon['extension_attributes'] ?? [], $rule['extension_attributes'] ?? []] as $extensions) {
                if ($extensions !== []) {
                    $unknown[] = 'extension_constraints';
                }
            }
            if (! array_key_exists('expiration_date', $coupon) || ! in_array($coupon['expiration_date'], [null, ''], true)) {
                $unknown[] = 'coupon_expiration';
            }
            foreach (['condition', 'action_condition'] as $field) {
                if (! array_key_exists($field, $rule)) {
                    $unknown[] = $field;
                }
            }
            $minimum = $this->magentoMinimum($rule['condition'] ?? null);
            $actions = $rule['action_condition'] ?? null;
            if ($minimum === null || ! $this->magentoActions($actions)) {
                $unknown[] = 'rule_conditions';
            }
            $start = $this->localDay($rule['from_date'] ?? null, $zone);
            $expiration = $this->localDay($rule['to_date'] ?? null, $zone, end: true);
            if (! array_key_exists('from_date', $rule) || ! array_key_exists('to_date', $rule)) {
                $unknown[] = 'rule_dates';
            }
            if ($mapped !== null) {
                $mapped['minimum_spend_minor'] = $minimum === null ? null : $this->minor($minimum, $currency);
                $limits = array_filter([$coupon['usage_limit'] ?? null, $rule['uses_per_coupon'] ?? null], fn ($v) => is_int($v) && $v > 0);
                $mapped['usage_limit'] = $limits === [] ? null : min($limits);
            }
        } elseif ($platform === EcommercePlatform::Squarespace) {
            $d = $raw['discount'] ?? [];
            if (array_diff(array_keys($d), ['id', 'websiteId', 'name', 'criteria', 'template', 'trigger', 'status', 'validFrom', 'validTo', 'isLimitedUses', 'isOncePerCustomer', 'maxUsesAllowed', 'numberOfUses', 'lastRedeemedAt', 'paymentPlanOptions', 'subscriptionOptions']) !== []) {
                $unknown[] = 'discount_options';
            }
            foreach (['criteria', 'template', 'trigger'] as $field) {
                if (! is_array($d[$field] ?? null)) {
                    throw new ApiException('The native coupon response is invalid.', 502, 'provider_coupon_invalid');
                }
            }
            $allowedCriteria = ($d['criteria']['type'] ?? null) === 'CART_TOTAL' ? ['type', 'minimumCartTotal'] : ['type'];
            $allowedTemplate = ($d['template']['type'] ?? null) === 'PERCENTAGE' ? ['type', 'percentage'] : ['type', 'discountAmount'];
            if (array_diff(array_keys($d['criteria']), $allowedCriteria) !== [] || array_diff(array_keys($d['template']), $allowedTemplate) !== []
                || array_diff(array_keys($d['trigger']), ['type', 'promoCode']) !== []) {
                $unknown[] = 'discount_options';
            }
            $type = $d['template']['type'] ?? null;
            $criteria = $d['criteria']['type'] ?? null;
            if (! in_array($type, ['PERCENTAGE', 'FIXED_AMOUNT'], true)) {
                $unknown[] = 'discount_kind';
            }
            if (! in_array($criteria, ['ANY_ORDER', 'CART_TOTAL'], true)) {
                $unknown[] = 'product_scope';
            }
            if (($d['isOncePerCustomer'] ?? null) !== false) {
                $unknown[] = 'customer_usage';
            }
            if (($raw['other_promotions'] ?? null) !== 0) {
                $unknown[] = 'combination_rules';
            }
            foreach (['subscriptionOptions', 'paymentPlanOptions'] as $field) {
                if (array_key_exists($field, $d) && (! is_array($d[$field]) || array_diff(array_keys($d[$field]), ['type']) !== [] || ! isset($d[$field]['type']))) {
                    $unknown[] = 'purchase_type';
                }
            }
            if (($d['subscriptionOptions']['type'] ?? 'EXCLUDED') !== 'EXCLUDED' || ($d['paymentPlanOptions']['type'] ?? 'NONE') !== 'NONE') {
                $unknown[] = 'purchase_type';
            }
            $limited = $d['isLimitedUses'] ?? null;
            if (! is_bool($limited) || ($limited && (! $this->count($d['maxUsesAllowed'] ?? null) || $d['maxUsesAllowed'] === 0))) {
                $unknown[] = 'usage_limit';
            }
            if (! $this->count($d['numberOfUses'] ?? null)) {
                $unknown[] = 'usage_count';
            }
            if (! array_key_exists('validTo', $d)) {
                $unknown[] = 'expiration';
            }
            $start = $this->date($d['validFrom'] ?? null, false);
            $expiration = $this->date($d['validTo'] ?? null);
            $minimum = $criteria === 'CART_TOTAL' ? $this->minor($d['criteria']['minimumCartTotal']['value'] ?? null, $currency) : 0;
            if ($minimum === null || (isset($d['criteria']['minimumCartTotal']['currency']) && $d['criteria']['minimumCartTotal']['currency'] !== $currency)) {
                $unknown[] = 'minimum_spend';
            }
            $amount = $type === 'FIXED_AMOUNT' ? $this->minor($d['template']['discountAmount']['value'] ?? null, $currency) : null;
            if (isset($d['template']['discountAmount']['currency']) && $d['template']['discountAmount']['currency'] !== $currency) {
                $unknown[] = 'discount_currency';
            }
            $percent = $type === 'PERCENTAGE' ? $this->basisPoints($d['template']['percentage'] ?? null) : null;
            $mapped = ['external_id' => $d['id'], 'discount_type' => $type === 'PERCENTAGE' ? 'percent' : ($type === 'FIXED_AMOUNT' ? 'fixed_cart' : null),
                'amount_minor' => $amount, 'percent_bps' => $percent, 'minimum_spend_minor' => $minimum, 'currency' => $currency,
                'usage_limit' => $limited ? ($d['maxUsesAllowed'] ?? null) : null, 'usage_count' => $d['numberOfUses'] ?? null,
                'status' => ($d['status'] ?? null) === 'ACTIVE' ? 'active' : 'inactive', 'expires_at' => $expiration];
        } else {
            throw ApiException::conflict('The configured adapter has no native coupon comparison.', 'coupon_api_unsupported');
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
            if (! in_array('minimum_spend', $unknown, true) && ($mapped['minimum_spend_minor'] ?? 0) !== ($terms->min_order_minor ?? 0)) {
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
            if ($capacityKnown && ! $code->reusable && $limit !== 1) {
                $differences[] = 'usage_limit';
            }
            if ($capacityKnown && $code->reusable && $limit !== null) {
                $unknown[] = 'reusable_capacity';
            }
            if ($capacityKnown && $limit !== null && $mapped['usage_count'] >= $limit) {
                $differences[] = 'usage_exhausted';
            }
        }
        if (! $capacityKnown && $mapped !== null) {
            unset($mapped['usage_limit'], $mapped['usage_count']);
        }
        // Only safe normalized fields leave the verifier; never raw codes, target customers or provider payloads.
        $provider = $mapped === null ? [] : array_intersect_key($mapped, array_flip(['external_id', 'discount_type', 'amount_minor', 'percent_bps', 'currency', 'minimum_spend_minor', 'usage_limit', 'usage_count', 'remaining_uses', 'status', 'expires_at']));
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

    private function nativeCount(mixed $value): ?int
    {
        if (is_string($value) && preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D', $value)) {
            $value = (int) $value;
        }

        return $this->count($value) ? $value : null;
    }

    private function localMoment(mixed $value, string $zone): CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/D', $value)) {
            throw new ApiException('The native coupon date is invalid.', 502, 'provider_coupon_invalid');
        }
        $date = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $value, $zone);
        if ($date->format('Y-m-d H:i:s') !== $value) {
            throw new ApiException('The native coupon date is invalid.', 502, 'provider_coupon_invalid');
        }

        $wall = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $value, 'UTC')->getTimestamp();
        $transitions = (new \DateTimeZone($zone))->getTransitions($date->getTimestamp() - 86400, $date->getTimestamp() + 86400);
        for ($index = 1; $index < count($transitions); $index++) {
            if ($transitions[$index]['offset'] < $transitions[$index - 1]['offset']
                && $wall >= $transitions[$index]['ts'] + $transitions[$index]['offset'] && $wall < $transitions[$index]['ts'] + $transitions[$index - 1]['offset']) {
                throw new ApiException('The native coupon date is invalid.', 502, 'provider_coupon_invalid');
            }
        }

        return $date->utc();
    }

    private function magentoActions(mixed $condition): bool
    {
        return $condition === null || (is_array($condition) && ($condition['condition_type'] ?? null) === 'Magento\\SalesRule\\Model\\Rule\\Condition\\Product\\Combine'
            && ($condition['aggregator_type'] ?? null) === 'all' && (string) ($condition['value'] ?? '') === '1' && ($condition['conditions'] ?? null) === []
            && ($condition['extension_attributes'] ?? []) === []);
    }

    /** Only a plain all-of subtotal threshold is currently comparable. */
    private function magentoMinimum(mixed $condition): ?string
    {
        if ($condition === null) {
            return '0';
        }
        if (! is_array($condition) || ($condition['condition_type'] ?? null) !== 'Magento\\SalesRule\\Model\\Rule\\Condition\\Combine'
            || ($condition['extension_attributes'] ?? []) !== [] || ($condition['aggregator_type'] ?? null) !== 'all' || (string) ($condition['value'] ?? '') !== '1' || ! is_array($condition['conditions'] ?? null)) {
            return null;
        }
        $children = $condition['conditions'];
        if ($children === []) {
            return '0';
        }
        if (count($children) !== 1 || ! is_array($children[0]) || ($children[0]['condition_type'] ?? null) !== 'Magento\\SalesRule\\Model\\Rule\\Condition\\Address'
            || ($children[0]['attribute_name'] ?? null) !== 'base_subtotal' || ($children[0]['operator'] ?? null) !== '>=' || ! is_string($children[0]['value'] ?? null)
            || ($children[0]['conditions'] ?? []) !== [] || ($children[0]['extension_attributes'] ?? []) !== []) {
            return null;
        }

        return $children[0]['value'];
    }

    private function localDay(mixed $value, string $zone, bool $end = false): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || ! preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/D', $value, $parts) || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            throw new ApiException('The native coupon date is invalid.', 502, 'provider_coupon_invalid');
        }
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, $zone);

        return ($end ? $date->addDay() : $date)->utc();
    }

    private function basisPoints(mixed $value): ?int
    {
        if ((! is_string($value) && ! is_int($value)) || ! preg_match('/\A(?:0|[1-9][0-9]{0,2})(?:\.[0-9]{1,2})?\z/D', (string) $value)) {
            return null;
        }
        $parts = explode('.', (string) $value);
        $points = (int) $parts[0] * 100 + (int) str_pad($parts[1] ?? '', 2, '0');

        return $points > 0 && $points <= 10000 ? $points : null;
    }

    private function count(mixed $v): bool
    {
        return is_int($v) && $v >= 0 && $v < 4_000_000_000;
    }

    private function wixDate(mixed $value, bool $nullable = false): ?CarbonImmutable
    {
        if ($value === null && $nullable) {
            return null;
        }
        if ((! is_int($value) && ! is_string($value)) || ! preg_match('/\A[1-9][0-9]{12}\z/D', (string) $value)) {
            throw new ApiException('The native coupon date is invalid.', 502, 'provider_coupon_invalid');
        }

        return CarbonImmutable::createFromTimestampMs((int) $value, 'UTC');
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
