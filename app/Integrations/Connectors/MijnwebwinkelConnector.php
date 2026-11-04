<?php

namespace App\Integrations\Connectors;

use App\Enums\ConnectionMethod;
use App\Enums\EcommercePlatform;
use App\Exceptions\ApiException;
use App\Integrations\Data\NormalizedOrder;
use App\Integrations\Data\VerifiedEvent;
use App\Integrations\Exceptions\InvalidWebhook;
use App\Models\WebshopConnection;
use App\Services\Onboarding\DomainName;
use Illuminate\Http\Request;

/** Documented ShopBonus partner bridge, explicitly not a guessed native MyOnlineStore API client. */
final class MijnwebwinkelConnector extends GenericSignedWebhookConnector
{
    public function __construct()
    {
        parent::__construct(EcommercePlatform::Mijnwebwinkel);
    }

    public function method(): ConnectionMethod
    {
        return ConnectionMethod::PrivateModule;
    }

    public function connectRules(): array
    {
        return ['store_domain' => ['required', 'string', 'max:255'], 'merchant_reference' => ['required', 'string', 'max:100', 'regex:/\A[A-Za-z0-9._-]+\z/D']];
    }

    public function connect(WebshopConnection $connection, array $input): array
    {
        $domain = DomainName::fromInput($input['store_domain']);
        if (! $connection->webshop->isOwnershipVerified() || $domain !== strtolower((string) $connection->webshop->verified_domain)) {
            throw ApiException::conflict('Verify the exact merchant domain before configuring the partner bridge.', 'store_identity_unverified');
        }
        $connection->external_shop_ref = $input['merchant_reference'];

        return parent::connect($connection, $input) + ['store_domain' => $domain];
    }

    public function setupInstructions(WebshopConnection $connection, string $webhookUrl): array
    {
        return ['format' => 'shopbonus-mijnwebwinkel-partner-bridge-v1', 'webhook_url' => $webhookUrl, 'native_api_implemented' => false, 'live_verified' => false,
            'merchant_reference' => $connection->external_shop_ref, 'steps' => [
                'A merchant-authorized Mijnwebwinkel partner bridge must read genuine orders using its approved partner/merchant tokens; no native partner credentials or undocumented endpoints are guessed by this backend.',
                'The bridge sends the existing ShopBonus signed webhook v1 headers and order schema. Include bridge.store_domain and bridge.merchant_reference matching this verified connection.',
                'Use the one-time generated signing secret only on the authorized partner server. Keep event IDs stable on retry and send original order/source timestamps and exact minor units. Browser events are not payment evidence.',
                'Native API lookup/backfill, independent provider payment/refund proof and real acceptance remain unavailable. Signed bridge messages cannot promote the connection to Connected.',
                'Remove the partner bridge subscription and revoke the merchant token at the provider before disconnecting. Never place partner tokens or the signing secret in storefront JavaScript.',
            ], 'documentation' => 'docs/api/README.md#shopbonus-webhook-format-v1'];
    }

    public function verifyIncomingEvent(WebshopConnection $connection, Request $request): VerifiedEvent
    {
        $event = parent::verifyIncomingEvent($connection, $request);
        $body = json_decode($this->rawBody($request), true);
        if (! is_array($body) || ($body['bridge']['merchant_reference'] ?? null) !== $connection->external_shop_ref
            || ($body['bridge']['store_domain'] ?? null) !== $connection->credential('store_domain')
            || ! $connection->webshop->isOwnershipVerified() || $connection->credential('store_domain') !== strtolower((string) $connection->webshop->verified_domain)) {
            throw new InvalidWebhook('store_mismatch');
        }

        return $event;
    }

    public function normalizeOrder(array $payload): NormalizedOrder
    {
        $source = parent::normalizeOrder($payload);

        // A bridge's claims do not establish native settlement, payment time, cash refund or consent proof.
        return new NormalizedOrder(externalId: $source->externalId, reference: $source->reference, firstName: $source->firstName, email: $source->email, phone: $source->phone,
            amountMinor: $source->amountMinor, currency: $source->currency, paymentStatus: $source->paymentStatus, orderStatus: $source->orderStatus,
            placedAt: $source->placedAt, paidAt: null, cancelledAt: $source->cancelledAt, refundedAt: null, refundedMinor: null,
            providerUpdatedAt: $source->providerUpdatedAt, couponCodes: [], contactConsent: null);
    }
}
