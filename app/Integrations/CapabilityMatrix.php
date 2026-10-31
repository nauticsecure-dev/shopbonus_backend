<?php

namespace App\Integrations;

use App\Enums\EcommercePlatform;
use App\Integrations\Contracts\CatalogCapable;
use App\Integrations\Data\CapabilityState;
use App\Models\WebshopConnection;

/**
 * Honest capability reporting for the platform catalogue and connection detail.
 * Never advertises AVAILABLE without a supporting profile flag or live probe.
 */
class CapabilityMatrix
{
    public const KEYS = [
        'orders', 'payments', 'refunds', 'products', 'categories',
        'coupons_read', 'coupons_write', 'banner_embed', 'webhooks', 'backfill',
    ];

    public function __construct(private readonly ConnectorRegistry $registry) {}

    /**
     * @return array<string, array{state: string, reason: ?string, last_verified_at: ?string}>
     */
    public function forPlatform(EcommercePlatform $platform): array
    {
        $entry = $this->registry->entry($platform);
        if (($entry['connector'] ?? null) === null) {
            return $this->all(CapabilityState::Unsupported, $entry['blocked_reason'] ?? 'No connector registered.');
        }

        $profile = is_array($entry['profile'] ?? null) ? $entry['profile'] : [];
        $verifiedAt = isset($profile['last_live_verified_at']) && is_string($profile['last_live_verified_at'])
            ? $profile['last_live_verified_at'] : null;
        $native = (bool) ($profile['native_read_api_implemented'] ?? false);

        $map = [
            'orders' => $this->cap($native ? CapabilityState::Available : CapabilityState::Limited, $native ? null : 'Webhook/module orders only; native read incomplete.', $verifiedAt),
            'payments' => $this->cap($native ? CapabilityState::Available : CapabilityState::Limited, $native ? null : 'Payment evidence depends on provider mapping.', $verifiedAt),
            'refunds' => $this->cap(isset($profile['refund_api']) || $native ? CapabilityState::Available : CapabilityState::Limited, null, $verifiedAt),
            'products' => $this->cap($this->productState($profile), $this->productReason($profile), $verifiedAt),
            'categories' => $this->cap($this->productState($profile) === CapabilityState::Available ? CapabilityState::Limited : CapabilityState::Unsupported, 'Category sync is best-effort where products are available.', $verifiedAt),
            'coupons_read' => $this->cap($this->couponReadState($profile), $this->couponReason($profile), $verifiedAt),
            'coupons_write' => $this->cap(CapabilityState::Unsupported, 'Coupon writes are out of scope for this boundary.', $verifiedAt),
            'banner_embed' => $this->cap(CapabilityState::Limited, is_string($profile['banner_method'] ?? null) ? (string) $profile['banner_method'] : 'Manual embed / platform extension required.', $verifiedAt),
            'webhooks' => $this->cap(CapabilityState::Available, null, $verifiedAt),
            'backfill' => $this->cap($native ? CapabilityState::Available : CapabilityState::Unsupported, $native ? null : 'Durable discovery requires a native paged reader.', $verifiedAt),
        ];

        return $map;
    }

    /**
     * @return array<string, array{state: string, reason: ?string, last_verified_at: ?string}>
     */
    public function forConnection(WebshopConnection $connection): array
    {
        $base = $this->forPlatform($connection->platform);
        if (! $this->registry->isAvailable($connection->platform)) {
            return $base;
        }

        $connector = $this->registry->for($connection->platform);
        if ($connector instanceof CatalogCapable) {
            foreach ($connector->getCapabilities($connection) as $key => $cap) {
                if (! in_array($key, self::KEYS, true)) {
                    continue;
                }
                $base[$key] = ['state' => $cap['state']->value, 'reason' => $cap['reason'], 'last_verified_at' => $cap['last_verified_at']];
            }
        }

        return $base;
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function productState(array $profile): CapabilityState
    {
        if (! ($profile['native_read_api_implemented'] ?? false)) {
            return CapabilityState::Unsupported;
        }
        $api = $profile['product_api'] ?? null;
        if (! is_string($api) || $api === '' || str_contains(strtoupper($api), 'UNSUPPORTED')) {
            return CapabilityState::Unsupported;
        }

        return CapabilityState::Available;
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function productReason(array $profile): ?string
    {
        return $this->productState($profile) === CapabilityState::Unsupported
            ? 'Product catalogue sync is not implemented for this platform boundary.'
            : null;
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function couponReadState(array $profile): CapabilityState
    {
        $api = $profile['coupon_api'] ?? null;
        if (! is_string($api) || $api === '' || str_contains(strtoupper($api), 'UNSUPPORTED')) {
            return CapabilityState::Unsupported;
        }

        return CapabilityState::Available;
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function couponReason(array $profile): ?string
    {
        $api = $profile['coupon_api'] ?? null;
        if (is_string($api) && str_contains(strtoupper($api), 'UNSUPPORTED')) {
            return $api;
        }

        return $this->couponReadState($profile) === CapabilityState::Unsupported
            ? 'Coupon read is not available for this platform boundary.'
            : null;
    }

    /**
     * @return array{state: string, reason: ?string, last_verified_at: ?string}
     */
    private function cap(CapabilityState $state, ?string $reason, ?string $verifiedAt): array
    {
        return ['state' => $state->value, 'reason' => $reason, 'last_verified_at' => $verifiedAt];
    }

    /**
     * @return array<string, array{state: string, reason: ?string, last_verified_at: ?string}>
     */
    private function all(CapabilityState $state, ?string $reason): array
    {
        $out = [];
        foreach (self::KEYS as $key) {
            $out[$key] = $this->cap($state, $reason, null);
        }

        return $out;
    }
}
