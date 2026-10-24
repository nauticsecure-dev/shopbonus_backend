<?php

namespace App\Affiliate\Adapters;

use App\Affiliate\Data\AffiliateProgramData;
use App\Affiliate\Data\AffiliateTransactionData;
use App\Models\AffiliateConnection;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * TradeTracker affiliate web services.
 *
 * NOT VERIFIED. TradeTracker's affiliate API is SOAP; no account, WSDL access
 * or sandbox was available, so no SOAP transport is shipped. The connection
 * is therefore "limited": transactions arrive through the admin import of
 * the network's conversion export (and the tokenised postback), using the
 * field mapping below. Add the transport and verify per
 * docs/integrations/VERIFICATION.md (OPEN-PROVIDER-CAPABILITIES).
 */
class TradeTrackerAdapter extends BaseAffiliateAdapter
{
    private const STATUS = ['accepted' => 'approved', 'pending' => 'pending', 'rejected' => 'rejected', 'cancelled' => 'reversed'];

    public function network(): string
    {
        return 'tradetracker';
    }

    public function credentialRules(): array
    {
        return ['customer_id' => ['required', 'string', 'max:32', 'regex:/^\d+$/'], 'passphrase' => ['required', 'string', 'max:255'], 'site_id' => ['required', 'string', 'max:32']];
    }

    public function testConnection(AffiliateConnection $connection): array
    {
        return ['ok' => false, 'status' => 'limited', 'error' => 'soap_transport_not_verified'];
    }

    public function fetchPrograms(AffiliateConnection $connection): array
    {
        throw new RuntimeException('TradeTracker sync is not available yet (SOAP transport not verified); use the import.');
    }

    public function fetchTransactions(AffiliateConnection $connection, ?CarbonImmutable $since): array
    {
        throw new RuntimeException('TradeTracker sync is not available yet (SOAP transport not verified); use the import.');
    }

    public function normalizeProgram(array $raw): AffiliateProgramData
    {
        $info = $raw['info'] ?? [];
        $percentage = isset($info['commission']['saleCommissionVariable']) && (float) $info['commission']['saleCommissionVariable'] > 0;

        return new AffiliateProgramData(
            externalId: (string) $raw['ID'],
            name: (string) $raw['name'],
            domain: isset($raw['URL']) ? (parse_url((string) $raw['URL'], PHP_URL_HOST) ?: null) : null,
            active: ($info['assignmentStatus'] ?? 'accepted') === 'accepted',
            commissionType: $percentage ? 'percentage' : 'fixed',
            commissionValue: $percentage ? $this->percentBps($info['commission']['saleCommissionVariable']) : $this->minor($info['commission']['saleCommissionFixed'] ?? 0, 'EUR'),
            currency: 'EUR',
            categoryRestricted: (bool) ($info['commission']['categoryRestricted'] ?? false),
            cashbackAllowed: (bool) ($info['policyCashbackStatus'] ?? false),
            trackingUrlTemplate: (string) ($info['trackingURL'] ?? '').'&r={click_ref}',
        );
    }

    public function normalizeTransaction(array $raw): AffiliateTransactionData
    {
        $currency = (string) ($raw['currency'] ?? 'EUR');

        return new AffiliateTransactionData(
            externalId: (string) $raw['ID'],
            programExternalId: (string) ($raw['campaign']['ID'] ?? $raw['campaignID']),
            clickReference: isset($raw['reference']) && $raw['reference'] !== '' ? (string) $raw['reference'] : null,
            orderReference: isset($raw['orderID']) ? (string) $raw['orderID'] : null,
            orderAmountMinor: $this->minor($raw['orderAmount'] ?? 0, $currency),
            currency: $currency,
            commissionMinor: $this->minor($raw['commission'] ?? 0, $currency),
            networkStatus: self::STATUS[strtolower((string) ($raw['transactionStatus'] ?? 'pending'))] ?? 'pending',
            transactionAt: $this->date($raw['registrationDate'] ?? null) ?? CarbonImmutable::now(),
            networkUpdatedAt: $this->date($raw['assessmentDate'] ?? null),
        );
    }
}
