<?php

namespace App\Services\Catalog;

use App\Enums\ConnectionStatus;
use App\Integrations\ConnectorRegistry;
use App\Integrations\Contracts\CatalogReader;
use App\Integrations\Contracts\PagedOrderReader;
use App\Models\CatalogSync;
use App\Models\WebshopConnection;

/**
 * Per-connection capability report (Trello #1 §3). Each capability is
 * AVAILABLE only on current evidence for this connection, LIMITED when the
 * code exists but has not been proven here, UNSUPPORTED with the reason
 * when no implementation exists. Nothing is inferred from saved credentials.
 */
class ConnectionCapabilities
{
    public const NAMES = ['orders', 'payments', 'refunds', 'products', 'categories', 'coupons_read', 'coupons_write', 'banner_embed', 'webhooks', 'backfill'];

    public function __construct(private readonly ConnectorRegistry $registry) {}

    /** @return list<array{capability: string, status: string, reason: string|null, last_verified_at: string|null}> */
    public function report(WebshopConnection $connection): array
    {
        $connector = $this->registry->isAvailable($connection->platform) ? $this->registry->for($connection->platform) : null;
        $connected = $connection->status === ConnectionStatus::Connected;
        $verified = $connected ? $connection->connected_at?->toIso8601String() : null;
        $gated = fn () => $connected ? $this->row('AVAILABLE', null, $verified)
            : $this->row('LIMITED', 'The connection has not passed all native verification checks (status '.$connection->status->value.').');
        $catalog = function (string $kind) use ($connector, $connection) {
            if (! $connector instanceof CatalogReader) {
                return $this->row('UNSUPPORTED', 'No native read for this platform is implemented.');
            }
            $done = CatalogSync::where('connection_id', $connection->id)->where('kind', $kind)->where('status', 'completed')
                ->where('credential_generation', $connection->credential_generation)->latest('id')->first();

            return $done === null ? $this->row('LIMITED', 'Implemented; no complete sync with the current credentials yet.')
                : $this->row('AVAILABLE', null, $done->finished_at?->toIso8601String());
        };

        $rows = [
            'orders' => $gated(),
            'payments' => $gated(),
            'refunds' => $gated(),
            'products' => $catalog('products'),
            'categories' => ($p = $catalog('products'))['status'] === 'AVAILABLE' ? $this->row('AVAILABLE', 'Categories as assigned to synced products.', $p['last_verified_at']) : $p,
            'coupons_read' => $catalog('coupons'),
            'coupons_write' => $this->row('UNSUPPORTED', 'ShopBonus integrations are read-only; native coupons are never created or changed.'),
            'banner_embed' => $this->row('LIMITED', 'Manual ShopBonus embed only; native storefront publication is not implemented for this platform.'),
            'webhooks' => $connection->last_event_at === null ? $this->row('LIMITED', 'No authenticated provider event received yet.')
                : $this->row('AVAILABLE', null, $connection->last_event_at->toIso8601String()),
            'backfill' => $connector instanceof PagedOrderReader ? $this->row('LIMITED', 'Bounded native order discovery is implemented; not proven for this connection.')
                : $this->row('UNSUPPORTED', 'No native order paging for this platform is implemented.'),
        ];
        if ($connector === null) {
            $rows = array_map(fn () => $this->row('UNSUPPORTED', 'This platform has no implemented connector.'), $rows);
        }

        return array_map(fn (string $name) => ['capability' => $name] + $rows[$name], self::NAMES);
    }

    /** @return array{status: string, reason: string|null, last_verified_at: string|null} */
    private function row(string $status, ?string $reason, ?string $at = null): array
    {
        return ['status' => $status, 'reason' => $reason, 'last_verified_at' => $at];
    }
}
