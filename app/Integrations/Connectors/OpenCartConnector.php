<?php

namespace App\Integrations\Connectors;

use App\Enums\EcommercePlatform;
use App\Exceptions\ApiException;
use App\Integrations\Contracts\CatalogReader;
use App\Models\CatalogSnapshotPage;
use App\Models\WebshopConnection;

/**
 * OpenCart: orders through the signed v1 module bridge (GenericSignedWebhookConnector), products and
 * coupons from the newest complete signed snapshot the ShopBonus extension worker pushed (OpenCart has
 * no core product/coupon API, and the extension exposes nothing on the merchant's shop).
 */
final class OpenCartConnector extends GenericSignedWebhookConnector implements CatalogReader
{
    public function __construct()
    {
        parent::__construct(EcommercePlatform::OpenCart);
    }

    public function catalogCurrency(WebshopConnection $c): string
    {
        $page = $this->firstPage($c, 'products') ?? $this->firstPage($c, 'coupons');

        return $page->currency ?? throw ApiException::conflict('No complete catalog snapshot has been received from the OpenCart extension yet.', 'catalog_snapshot_missing');
    }

    public function catalogPage(WebshopConnection $c, string $kind, ?string $cursor, string $currency): array
    {
        if (! in_array($kind, ['products', 'coupons'], true) || ($cursor !== null && ! preg_match('/\A[1-9][0-9]{0,3}\z/D', $cursor))) {
            throw new \InvalidArgumentException('Invalid catalog cursor.');
        }
        $snapshot = CatalogSnapshotPage::latestComplete($c->id, $c->credential_generation, $kind)
            ?? throw ApiException::conflict('No complete catalog snapshot has been received from the OpenCart extension yet.', 'catalog_snapshot_missing');
        $page = CatalogSnapshotPage::where('connection_id', $c->id)->where('kind', $kind)->where('snapshot_id', $snapshot)->where('page', $cursor === null ? 1 : (int) $cursor)->first()
            ?? throw new ApiException('The provider catalog page is invalid.', 502, 'provider_response_invalid');
        if ($page->currency !== $currency) {
            throw ApiException::conflict('The store currency changed during catalog synchronization.', 'provider_currency_changed');
        }
        // Rows were checked to be JSON objects when the signed page was accepted (CatalogSnapshotController).
        $items = array_map(fn (array $row) => $kind === 'products' ? OpenCartCatalog::product($row, $currency) : OpenCartCatalog::coupon($row, $currency), $page->items);

        return ['items' => $items, 'next' => $page->last ? null : (string) ($page->page + 1)];
    }

    private function firstPage(WebshopConnection $c, string $kind): ?CatalogSnapshotPage
    {
        $snapshot = CatalogSnapshotPage::latestComplete($c->id, $c->credential_generation, $kind);

        return $snapshot === null ? null : CatalogSnapshotPage::where('connection_id', $c->id)->where('kind', $kind)->where('snapshot_id', $snapshot)->where('page', 1)->first();
    }
}
