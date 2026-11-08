<?php

namespace App\Integrations\Connectors;

use App\Enums\EcommercePlatform;
use App\Exceptions\ApiException;
use App\Integrations\Contracts\CatalogReader;
use App\Integrations\Contracts\CouponLookup;
use App\Integrations\Http\PinnedJsonReader;
use App\Integrations\Http\WooCommerceApi;
use App\Models\CatalogSnapshotPage;
use App\Models\WebshopConnection;
use App\Services\Onboarding\DomainName;
use App\Support\ExactJson;
use App\Support\Money;

/**
 * OpenCart: orders through the signed v1 module bridge (GenericSignedWebhookConnector), products and
 * coupons from the newest complete signed snapshot the ShopBonus extension worker pushed (OpenCart has
 * no core product/coupon API). Coupon verification uses the opt-in signed live module route, never these snapshots.
 */
final class OpenCartConnector extends GenericSignedWebhookConnector implements CatalogReader, CouponLookup
{
    public function __construct()
    {
        parent::__construct(EcommercePlatform::OpenCart);
    }

    public function setupInstructions(WebshopConnection $connection, string $webhookUrl): array
    {
        $instructions = parent::setupInstructions($connection, $webhookUrl);
        $instructions['steps'][] = 'For live coupon verification, install the current OpenCart package, enable authenticated live coupon reads, and configure the exact HTTPS store URL and timezone on this connection. Use the current connection signing secret in the module. Cached catalog snapshots cannot verify coupons.';

        return $instructions;
    }

    public function connectRules(): array
    {
        return ['store_url' => ['sometimes', 'url:https', 'max:255'], 'timezone' => ['required_with:store_url', 'timezone']];
    }

    public function connect(WebshopConnection $connection, array $input): array
    {
        $credentials = parent::connect($connection, $input);
        if (isset($input['store_url'])) {
            $url = WooCommerceApi::baseUrl($input['store_url']);
            if (! $connection->webshop->isOwnershipVerified() || DomainName::fromInput($url) !== strtolower((string) $connection->webshop->verified_domain)) {
                throw ApiException::conflict('Verify ownership of the exact store domain before using its API.', 'store_identity_unverified');
            }
            $connection->external_shop_ref = $url.'#store=0';
            $credentials += ['store_url' => $url, 'timezone' => $input['timezone']];
        }

        return $credentials;
    }

    public function lookupCoupon(WebshopConnection $connection, string $code): array
    {
        $origin = $connection->credential('store_url');
        $secret = $connection->credential('signing_secret');
        if ($origin === null || $secret === null || $connection->credential('timezone') === null) {
            throw ApiException::conflict('Configure read-only provider credentials before testing the API.', 'provider_credentials_missing');
        }
        $origin = WooCommerceApi::baseUrl($origin);
        if (! $connection->webshop->isOwnershipVerified() || DomainName::fromInput($origin) !== strtolower((string) $connection->webshop->verified_domain)
            || $connection->external_shop_ref !== $origin.'#store=0') {
            throw ApiException::conflict('Verify ownership of the exact store domain before using its API.', 'store_identity_unverified');
        }
        $nonce = bin2hex(random_bytes(32));
        $time = (string) now()->getTimestamp();
        $response = app(PinnedJsonReader::class)->read($connection, $origin.'/index.php', ['headers' => ['X-Shopbonus-Time' => $time, 'X-Shopbonus-Nonce' => $nonce,
            'X-Shopbonus-Signature' => hash_hmac('sha256', 'shopbonus.opencart.coupon.v1.request|'.$time.'|'.$nonce.'|'.$code, $secret)]], 'GET',
            ['route' => 'extension/shopbonus/module/shopbonus.coupon', 'code' => $code])['data'];
        $payload = $response['payload'] ?? null;
        $signature = $response['signature'] ?? null;
        if (! is_string($payload) || ! is_string($signature) || ! hash_equals(hash_hmac('sha256', 'shopbonus.opencart.coupon.v1.response|'.$payload, $secret), $signature)) {
            throw new ApiException('The native coupon response is invalid.', 502, 'provider_coupon_invalid');
        }
        try {
            $data = ExactJson::decode($payload);
        } catch (\JsonException) {
            throw new ApiException('The native coupon response is invalid.', 502, 'provider_coupon_invalid');
        }
        if (! is_array($data) || ($data['schema'] ?? null) !== 'shopbonus.opencart.coupon.v1' || ($data['nonce'] ?? null) !== $nonce
            || ! is_int($data['observed_at'] ?? null) || abs(now()->getTimestamp() - $data['observed_at']) > 60
            || ($data['store_id'] ?? null) !== 0 || ($data['store_url'] ?? null) !== $origin || ($data['timezone'] ?? null) !== $connection->credential('timezone')
            || ! array_key_exists('coupon', $data) || ($data['coupon'] !== null && (! is_array($data['coupon']) || ! is_string($data['coupon']['code'] ?? null)
                || mb_strtolower($data['coupon']['code']) !== mb_strtolower($code)))) {
            throw new ApiException('The native coupon response is invalid.', 502, 'provider_coupon_invalid');
        }
        if (! Money::isCurrency($data['currency'] ?? null)) {
            throw new ApiException('The provider store currency could not be read.', 502, 'provider_currency_invalid');
        }

        return ['currency' => $data['currency'], 'coupon' => $data['coupon'] === null ? null : ['coupon' => $data['coupon'], 'enabled_totals' => $data['enabled_totals'] ?? null]];
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
