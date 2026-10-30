<?php

namespace App\Affiliate\Adapters;

use App\Affiliate\Data\AffiliateProgramData;
use App\Affiliate\Data\AffiliateTransactionData;
use App\Affiliate\NetworkFailure;
use App\Models\AffiliateConnection;
use Carbon\CarbonImmutable;
use SoapFault;
use Throwable;

/**
 * TradeTracker affiliate SOAP API, serialized against the pinned official HTTPS WSDL.
 * Account access/terms and real provider behavior remain deployment verification.
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
        return ['customer_id' => ['required', 'string', 'max:32', 'regex:/^\d+$/'], 'passphrase' => ['required', 'string', 'max:255'], 'site_id' => ['required', 'string', 'max:32', 'regex:/^\d+$/']];
    }

    public function testConnection(AffiliateConnection $connection): array
    {
        try {
            $client = $this->authenticated($connection);
            $this->rows($client->__soapCall('getCampaigns', [$connection->credentials['site_id'], ['limit' => 1, 'offset' => 0]]));

            return ['ok' => true, 'status' => 'connected', 'error' => null];
        } catch (Throwable $e) {
            $failure = $e instanceof NetworkFailure ? $e : new NetworkFailure('invalid_provider_response');

            return ['ok' => false, 'status' => $failure->status, 'error' => $failure->error];
        }
    }

    public function fetchPrograms(AffiliateConnection $connection): array
    {
        $client = $this->authenticated($connection);

        return array_map(fn (array $row) => $this->normalizeProgram($row), $this->pages($client, 'getCampaigns', $connection, ['excludeInfo' => false]));
    }

    public function fetchTransactions(AffiliateConnection $connection, ?CarbonImmutable $since): array
    {
        $client = $this->authenticated($connection);
        $from = ($since ?? CarbonImmutable::now()->subDays(90))->toIso8601String();
        // Registration-only polling misses approvals/rejections of older purchases.
        $rows = [];
        foreach (['registrationDateFrom', 'assessmentDateFrom'] as $dateField) {
            foreach ($this->pages($client, 'getConversionTransactions', $connection, [$dateField => $from]) as $row) {
                if (! isset($row['ID']) || ! is_scalar($row['ID'])) {
                    throw new NetworkFailure('invalid_provider_response');
                }
                $rows[(string) $row['ID']] = $row;
            }
        }

        return array_values(array_map(function (array $row): AffiliateTransactionData {
            // API records must carry authoritative dates/currency/status. Legacy imports
            // and untrusted pings retain their pre-existing optional-field defaults.
            if (validator($row, ['currency' => ['required'], 'registrationDate' => ['required'], 'commission' => ['required'], 'transactionStatus' => ['required']])->fails()) {
                throw new NetworkFailure('invalid_provider_response');
            }

            return $this->normalizeTransaction($row);
        }, $rows));
    }

    private function authenticated(AffiliateConnection $connection): TradeTrackerSoapClient
    {
        if ($connection->status === 'disabled') {
            throw new NetworkFailure('connection_disabled', 'disabled');
        }
        if (! config('shopbonus.affiliate.networks.tradetracker.enabled')) {
            throw new NetworkFailure('transport_disabled', 'limited');
        }
        if (validator($connection->credentials, $this->credentialRules())->fails()) {
            throw new NetworkFailure('credentials_invalid', 'reauthentication_required');
        }
        $client = new TradeTrackerSoapClient;
        try {
            // Explicit sandbox/demo flags; this adapter exposes only authenticated read methods.
            $client->__soapCall('authenticate', [$connection->credentials['customer_id'], $connection->credentials['passphrase'], true, 'en_GB', false]);
        } catch (SoapFault) {
            throw new NetworkFailure('authentication_failed', 'reauthentication_required');
        }

        return $client;
    }

    /** @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    private function pages(TradeTrackerSoapClient $client, string $method, AffiliateConnection $connection, array $options): array
    {
        $limit = 100;
        $result = [];
        $seen = [];
        for ($page = 0; $page < 100; $page++) {
            try {
                $rows = $this->rows($client->__soapCall($method, [$connection->credentials['site_id'], $options + [
                    'limit' => $limit, 'offset' => $page * $limit, 'sort' => 'ID', 'sortDirection' => 'asc',
                ]]));
            } catch (SoapFault) {
                throw new NetworkFailure('provider_request_rejected');
            }
            foreach ($rows as $row) {
                $id = $row['ID'] ?? null;
                if ((! is_string($id) && ! is_int($id)) || (string) $id === '' || isset($seen[(string) $id])) {
                    throw new NetworkFailure('invalid_provider_pagination');
                }
                $seen[(string) $id] = true;
                $result[] = $row;
            }
            if (count($rows) < $limit) {
                return $result;
            }
        }
        // Never advance the sync watermark after a truncated pull.
        throw new NetworkFailure('provider_page_limit_exceeded');
    }

    /** @return list<array<string, mixed>> */
    private function rows(mixed $response): array
    {
        if (! is_array($response) || ! array_is_list($response) || count($response) > 100) {
            throw new NetworkFailure('invalid_provider_response');
        }
        $rows = json_decode(json_encode($response, JSON_THROW_ON_ERROR), true, 64, JSON_THROW_ON_ERROR);
        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new NetworkFailure('invalid_provider_response');
            }
        }

        return $rows;
    }

    public function normalizeProgram(array $raw): AffiliateProgramData
    {
        if (isset($raw['ID']) && is_int($raw['ID'])) {
            $raw['ID'] = (string) $raw['ID'];
        }
        if (validator($raw, ['ID' => ['required', 'string'], 'name' => ['required', 'string', 'max:255'], 'info' => ['nullable', 'array'], 'info.commission.saleCommissionVariable' => ['nullable', 'numeric', 'min:0'], 'info.commission.saleCommissionFixed' => ['nullable', 'numeric', 'min:0']])->fails()) {
            throw new NetworkFailure('invalid_provider_response');
        }
        $info = $raw['info'] ?? [];
        $percentage = isset($info['commission']['saleCommissionVariable']) && (float) $info['commission']['saleCommissionVariable'] > 0;

        return new AffiliateProgramData(
            externalId: (string) $raw['ID'],
            name: (string) $raw['name'],
            domain: isset($raw['URL']) ? (parse_url((string) $raw['URL'], PHP_URL_HOST) ?: null) : null,
            active: ($info['assignmentStatus'] ?? null) === 'accepted' && ($info['referencesSupported'] ?? true) === true,
            commissionType: $percentage ? 'percentage' : 'fixed',
            commissionValue: $percentage ? $this->percentBps($info['commission']['saleCommissionVariable']) : $this->minor($info['commission']['saleCommissionFixed'] ?? 0, 'EUR'),
            currency: 'EUR',
            categoryRestricted: (bool) ($info['commission']['categoryRestricted'] ?? false),
            cashbackAllowed: in_array($info['policyCashbackStatus'] ?? null, ['allowed', true], true),
            trackingUrlTemplate: $this->trackingTemplate((string) ($info['trackingURL'] ?? ''), 'r'),
            merchantUrl: isset($raw['URL']) ? (string) $raw['URL'] : null,
            rawEvidence: $raw,
        );
    }

    public function normalizeTransaction(array $raw): AffiliateTransactionData
    {
        $validation = validator($raw, [
            'ID' => ['required'], 'campaign.ID' => ['required_without:campaignID'], 'campaignID' => ['required_without:campaign.ID'],
            'currency' => ['sometimes', 'string', 'size:3'], 'commission' => ['sometimes', 'numeric', 'min:0'],
            'orderAmount' => ['nullable', 'numeric', 'min:0'], 'registrationDate' => ['sometimes', 'nullable', 'date'],
            'assessmentDate' => ['nullable', 'date'], 'transactionStatus' => ['sometimes', 'in:accepted,pending,rejected,cancelled'],
        ]);
        if ($validation->fails() || (! is_string($raw['ID']) && ! is_int($raw['ID']))) {
            throw new NetworkFailure('invalid_provider_response');
        }
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
