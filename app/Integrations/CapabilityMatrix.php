<?php

namespace App\Integrations;

use App\Enums\EcommercePlatform;
use App\Integrations\Contracts\PagedOrderReader;
use App\Integrations\Data\CapabilityState;
use App\Models\WebshopConnection;
use App\Services\Catalog\ConnectionCapabilities;

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
     * @return array<string, array{state: string, reason: ?string, last_verified_at: ?string, evidence_id: ?string, source_url: ?string, error_code: ?string, evidence_scope: string}>
     */
    public function forPlatform(EcommercePlatform $platform): array
    {
        $entry = $this->registry->entry($platform);
        if (($entry['connector'] ?? null) === null) {
            return $this->all(CapabilityState::Unsupported, $entry['blocked_reason'] ?? 'No connector registered.');
        }

        $profile = is_array($entry['profile'] ?? null) ? $entry['profile'] : [];
        // Platform-wide timestamps cannot prove any individual feature.
        $verifiedAt = null;
        $native = (bool) ($profile['native_read_api_implemented'] ?? false);
        // Each capability from what the mapping fills, never from an API merely being documented.
        $payments = $native && ($profile['payment_time_mapped'] ?? false) === true;
        $refunds = ($profile['refund_amounts_mapped'] ?? false) === true;
        $backfill = $native && is_subclass_of((string) $entry['connector'], PagedOrderReader::class);
        $refundNote = is_string($profile['refund_api'] ?? null) ? (string) $profile['refund_api'] : null;

        $map = [
            'orders' => $this->cap($native ? CapabilityState::Available : CapabilityState::Limited, $native ? null : 'Webhook/module orders only; native read incomplete.', $verifiedAt),
            'payments' => $this->cap($payments ? CapabilityState::Available : CapabilityState::Limited, $payments ? null : 'No settled payment time is mapped; payment status only.', $verifiedAt),
            'refunds' => $this->cap($refunds ? CapabilityState::Available : CapabilityState::Limited,
                $refunds ? null : (($profile['reported_refund_total_mapped'] ?? false)
                    ? 'Reported refund totals are mapped; processor cash settlement remains unverified.'
                    : ($refundNote === null ? 'Refund amounts are not mapped.' : __('Refund amounts are not mapped: :note', ['note' => __($refundNote)]))), $verifiedAt),
            'products' => $this->cap($this->productState($profile), $this->productReason($profile), $verifiedAt),
            'categories' => $this->cap(($profile['catalog_categories_implemented'] ?? true) && $this->productState($profile) === CapabilityState::Available ? CapabilityState::Limited : CapabilityState::Unsupported, 'Category sync is best-effort where implemented with products.', $verifiedAt),
            'coupons_read' => $this->cap($this->couponReadState($profile), $this->couponReason($profile), $verifiedAt),
            'coupons_write' => $this->cap(CapabilityState::Unsupported, 'Coupon writes are out of scope for this boundary.', $verifiedAt),
            'banner_embed' => $this->cap(CapabilityState::Limited, is_string($profile['banner_method'] ?? null) ? (string) $profile['banner_method'] : 'Manual embed / platform extension required.', $verifiedAt),
            'webhooks' => $this->cap(($entry['method'] ?? null) === 'polling' ? CapabilityState::Unsupported : CapabilityState::Available,
                ($entry['method'] ?? null) === 'polling' ? 'API-key connection uses polling; webhook authentication is not implemented.' : null, $verifiedAt),
            'backfill' => $this->cap($backfill ? (($profile['discovery_window'] ?? null) === 'created_at' ? CapabilityState::Limited : CapabilityState::Available) : CapabilityState::Unsupported, $backfill ? (($profile['discovery_window'] ?? null) === 'created_at' ? 'Creation-window discovery only; historical changes require explicit order recovery.' : null) : 'Durable discovery requires a native paged reader.', $verifiedAt),
        ];

        foreach ($map as $key => &$capability) {
            $sources = $profile['sources'] ?? [];
            foreach ($sources as $source) {
                if (is_string($source) && filter_var($source, FILTER_VALIDATE_URL) && str_starts_with($source, 'https://')
                    && parse_url($source, PHP_URL_USER) === null && parse_url($source, PHP_URL_PASS) === null && parse_url($source, PHP_URL_QUERY) === null) {
                    $capability['source_url'] = $source;
                    break;
                }
            }
        }
        unset($capability);

        return $map;
    }

    /**
     * @return array<string, array{state: string, reason: ?string, last_verified_at: ?string, evidence_id: ?string, source_url: ?string, error_code: ?string, evidence_scope: string}>
     */
    public function forConnection(WebshopConnection $connection): array
    {
        $map = [];
        foreach (app(ConnectionCapabilities::class)->report($connection) as $row) {
            $key = $row['capability'];
            unset($row['capability']);
            $row['state'] = $row['status'];
            unset($row['status']);
            $map[$key] = $row;
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function productState(array $profile): CapabilityState
    {
        // A worker-pushed signed snapshot (OpenCart) is a read source too; it is not a native order API.
        if (! ($profile['native_read_api_implemented'] ?? false) && ($profile['catalog_snapshot_push'] ?? false) !== true) {
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
     * @return array{state: string, reason: ?string, last_verified_at: ?string, evidence_id: ?string, source_url: ?string, error_code: ?string, evidence_scope: string}
     */
    private function cap(CapabilityState $state, ?string $reason, ?string $verifiedAt): array
    {
        // Reasons are English source text; the request locale decides what the client sees (lang/nl.json).
        return ['state' => $state->value, 'reason' => $reason === null ? null : __($reason), 'last_verified_at' => $verifiedAt,
            'evidence_id' => null, 'source_url' => null, 'error_code' => $state === CapabilityState::Unsupported ? 'capability_unsupported' : 'feature_not_live_verified',
            'evidence_scope' => 'implementation_declaration_not_provider_acceptance'];
    }

    /**
     * @return array<string, array{state: string, reason: ?string, last_verified_at: ?string, evidence_id: ?string, source_url: ?string, error_code: ?string, evidence_scope: string}>
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
