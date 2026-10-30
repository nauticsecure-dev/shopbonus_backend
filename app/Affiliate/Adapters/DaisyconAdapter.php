<?php

namespace App\Affiliate\Adapters;

use App\Affiliate\Data\AffiliateProgramData;
use App\Affiliate\Data\AffiliateTransactionData;
use App\Models\AffiliateConnection;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Daisycon publisher API (REST + OAuth 2.1 bearer token).
 *
 * Paths follow Daisycon's publisher FAQ examples (2025–2026):
 *   GET /publisher/{publisher-id}/programs
 *   GET /publisher/{publisher-id}/transactions?start=&end=&page=&per_page=
 * Live: https://services.daisycon.com — Sandbox: https://services-sandbox.daisycon.com
 *
 * NOT VERIFIED against a live publisher account. Store a valid OAuth access
 * token (refresh handled outside this adapter until OPEN-PROVIDER-CAPABILITIES).
 * Verify per docs/integrations/VERIFICATION.md before production sync.
 */
class DaisyconAdapter extends BaseAffiliateAdapter
{
    private const STATUS = ['approved' => 'approved', 'pending' => 'pending', 'open' => 'pending', 'disapproved' => 'rejected', 'rejected' => 'rejected', 'reversed' => 'reversed'];

    public function network(): string
    {
        return 'daisycon';
    }

    public function credentialRules(): array
    {
        return [
            'publisher_id' => ['required', 'string', 'max:32', 'regex:/^\d+$/'],
            'access_token' => ['required', 'string', 'max:4000'],
            'media_id' => ['required', 'string', 'max:32'],
            'refresh_token' => ['sometimes', 'nullable', 'string', 'max:4000'],
        ];
    }

    public function testConnection(AffiliateConnection $connection): array
    {
        try {
            $response = $this->client($connection)->get($this->publisherPath($connection, 'programs'), ['per_page' => 1]);
        } catch (ConnectionException) {
            return ['ok' => false, 'status' => 'error', 'error' => 'connection_failed'];
        }

        return match (true) {
            $response->successful() => ['ok' => true, 'status' => 'connected', 'error' => null],
            in_array($response->status(), [401, 403], true) => ['ok' => false, 'status' => 'reauthentication_required', 'error' => 'credentials_rejected'],
            default => ['ok' => false, 'status' => 'error', 'error' => 'http_'.$response->status()],
        };
    }

    public function fetchPrograms(AffiliateConnection $connection): array
    {
        $rows = $this->client($connection)->get($this->publisherPath($connection, 'programs'), [
            'media_id' => $connection->credentials['media_id'],
            'per_page' => 1000,
        ])->throw()->json() ?? [];

        return array_map(fn (array $row) => $this->normalizeProgram($row), is_array($rows) ? $rows : []);
    }

    public function fetchTransactions(AffiliateConnection $connection, ?CarbonImmutable $since): array
    {
        $start = ($since ?? CarbonImmutable::now()->subDays(90))->format('Y-m-d');
        $end = CarbonImmutable::now()->format('Y-m-d');
        $rows = $this->client($connection)->get($this->publisherPath($connection, 'transactions'), [
            'start' => $start,
            'end' => $end,
            'page' => 1,
            'per_page' => 1000,
        ])->throw()->json() ?? [];

        return array_map(fn (array $row) => $this->normalizeTransaction($row), is_array($rows) ? $rows : []);
    }

    private function publisherPath(AffiliateConnection $connection, string $resource): string
    {
        return '/publisher/'.$connection->credentials['publisher_id'].'/'.$resource;
    }

    public function normalizeProgram(array $raw): AffiliateProgramData
    {
        $commission = $raw['commission'] ?? [];
        $percentage = ($commission['type'] ?? 'percentage') === 'percentage';

        return new AffiliateProgramData(
            externalId: (string) $raw['id'],
            name: (string) $raw['name'],
            domain: isset($raw['url']) ? (parse_url((string) $raw['url'], PHP_URL_HOST) ?: null) : null,
            active: ($raw['status'] ?? 'active') === 'active',
            commissionType: $percentage ? 'percentage' : 'fixed',
            commissionValue: $percentage ? $this->percentBps($commission['value'] ?? 0) : $this->minor($commission['value'] ?? 0, (string) ($raw['currency_code'] ?? 'EUR')),
            currency: (string) ($raw['currency_code'] ?? 'EUR'),
            categoryRestricted: (bool) ($commission['restricted'] ?? false),
            cashbackAllowed: (bool) ($raw['cashback_allowed'] ?? false),
            trackingUrlTemplate: (string) ($raw['tracking_url'] ?? '').'&ws={click_ref}',
            merchantUrl: isset($raw['url']) ? (string) $raw['url'] : null,
            rawEvidence: $raw,
        );
    }

    public function normalizeTransaction(array $raw): AffiliateTransactionData
    {
        $part = $raw['parts'][0] ?? $raw;
        $currency = (string) ($part['currency_code'] ?? $raw['currency_code'] ?? 'EUR');

        return new AffiliateTransactionData(
            externalId: (string) ($raw['affiliatemarketing_id'] ?? $raw['id']),
            programExternalId: (string) $raw['program_id'],
            clickReference: isset($part['subid']) && $part['subid'] !== '' ? (string) $part['subid'] : null,
            orderReference: isset($part['referencenumber']) ? (string) $part['referencenumber'] : null,
            orderAmountMinor: $this->minor($part['revenue'] ?? 0, $currency),
            currency: $currency,
            commissionMinor: $this->minor($part['commission'] ?? 0, $currency),
            networkStatus: self::STATUS[strtolower((string) ($part['status'] ?? 'pending'))] ?? 'pending',
            transactionAt: $this->date($raw['date'] ?? null) ?? CarbonImmutable::now(),
            networkUpdatedAt: $this->date($part['approval_date'] ?? $raw['last_modified'] ?? null),
        );
    }

    private function client(AffiliateConnection $connection): PendingRequest
    {
        return Http::baseUrl((string) config('shopbonus.affiliate.networks.daisycon.base_url'))
            ->withToken((string) $connection->credentials['access_token'])->acceptJson()->timeout(20)->connectTimeout(5);
    }
}
