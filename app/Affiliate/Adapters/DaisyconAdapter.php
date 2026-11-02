<?php

namespace App\Affiliate\Adapters;

use App\Affiliate\Data\AffiliateProgramData;
use App\Affiliate\Data\AffiliateTransactionData;
use App\Affiliate\NetworkFailure;
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
        $rows = $this->pages($connection, 'programs', ['media_id' => $connection->credentials['media_id']]);

        return array_map(fn (array $row) => $this->normalizeProgram($row), $rows);
    }

    public function fetchTransactions(AffiliateConnection $connection, ?CarbonImmutable $since): array
    {
        $start = ($since ?? CarbonImmutable::now()->subDays(90))->format('Y-m-d');
        $end = CarbonImmutable::now()->format('Y-m-d');
        $rows = $this->pages($connection, 'transactions', ['start' => $start, 'end' => $end]);

        return array_map(fn (array $row) => $this->normalizeTransaction($row), $rows);
    }

    /**
     * Fetch an entire bounded snapshot before ingestion. A failed/incomplete pull
     * leaves AffiliateSync's durable last_synced_at checkpoint untouched; retry
     * replays that window with a seven-day overlap and idempotent conversion IDs.
     *
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    private function pages(AffiliateConnection $connection, string $resource, array $query, bool $plural = false): array
    {
        $rows = [];
        $seen = [];
        $expectedTotal = null;
        $bytes = 0;
        $pageSize = 1000;
        $maxPages = max(1, min(100, (int) config('shopbonus.affiliate.networks.daisycon.max_pages', 100)));
        for ($page = 1; $page <= $maxPages; $page++) {
            $response = $this->client($connection)->get(($plural ? '/publishers/'.$connection->credentials['publisher_id'].'/'.$resource : $this->publisherPath($connection, $resource)), $query + ['page' => $page, 'per_page' => $pageSize])->throw();
            $bytes += strlen($response->body());
            if ($bytes > 32 * 1024 * 1024 || strlen($response->body()) > 4 * 1024 * 1024) {
                throw new NetworkFailure('provider_snapshot_too_large');
            }
            $batch = $response->json();
            if (! is_array($batch) || ! array_is_list($batch) || count($batch) > $pageSize) {
                throw new NetworkFailure('invalid_provider_response');
            }
            $total = $response->header('X-Total-Count');
            if ($total !== '') {
                if (! ctype_digit($total) || ($expectedTotal !== null && $expectedTotal !== (int) $total)) {
                    throw new NetworkFailure('inconsistent_pagination');
                }
                $expectedTotal = (int) $total;
            }
            foreach ($batch as $row) {
                $id = is_array($row) ? ($row['affiliatemarketing_id'] ?? $row['id'] ?? null) : null;
                if ((! is_string($id) && ! is_int($id)) || (string) $id === '' || isset($seen[(string) $id])) {
                    throw new NetworkFailure('inconsistent_pagination');
                }
                $seen[(string) $id] = true;
                $rows[] = $row;
            }
            if ($expectedTotal !== null && count($rows) > $expectedTotal) {
                throw new NetworkFailure('inconsistent_pagination');
            }
            // Daisycon documents fetching until an empty page; servers may cap per_page.
            if ($batch === [] || ($expectedTotal !== null && count($rows) === $expectedTotal)) {
                if ($expectedTotal !== null && count($rows) !== $expectedTotal) {
                    throw new NetworkFailure('incomplete_pagination');
                }

                return $rows;
            }
        }

        throw new NetworkFailure('pagination_limit_reached');
    }

    /** Separate resource retrieval; retained for review, never inferred cashback permission.
     * @return list<array<string, mixed>>
     */
    public function fetchEvidence(AffiliateConnection $connection, string $resource, ?string $programId = null): array
    {
        if ($resource === 'promotions' && $programId === null) {
            return $this->pages($connection, 'material/promotions', [], true);
        }
        if (! in_array($resource, ['commissions', 'access-rules'], true) || $programId === null || $programId === '' || strlen($programId) > 100) {
            throw new NetworkFailure('invalid_resource');
        }
        $response = $this->client($connection)->get('/publishers/'.$connection->credentials['publisher_id'].'/programs/'.rawurlencode($programId).'/'.$resource)->throw();
        $data = $response->json();
        if (strlen($response->body()) > 4 * 1024 * 1024 || ! is_array($data)) {
            throw new NetworkFailure('invalid_provider_response');
        }

        return $data;
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
