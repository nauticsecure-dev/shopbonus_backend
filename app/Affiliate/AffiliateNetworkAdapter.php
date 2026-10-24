<?php

namespace App\Affiliate;

use App\Affiliate\Data\AffiliateProgramData;
use App\Affiliate\Data\AffiliateTransactionData;
use App\Models\AffiliateConnection;
use App\Models\AffiliateProgram;
use Carbon\CarbonImmutable;

/**
 * One affiliate network (Trello #19 "Create a common AffiliateNetworkAdapter
 * contract"). Networks differ in rates, statuses and identifiers; adapters
 * translate them into the normalised DTOs and nothing else.
 */
interface AffiliateNetworkAdapter
{
    public function network(): string;

    /** @return array<string, list<string>> validation rules for the credentials */
    public function credentialRules(): array;

    /** @return array{ok: bool, status: string, error: string|null} */
    public function testConnection(AffiliateConnection $connection): array;

    /** @return list<AffiliateProgramData> */
    public function fetchPrograms(AffiliateConnection $connection): array;

    /** @return list<AffiliateTransactionData> */
    public function fetchTransactions(AffiliateConnection $connection, ?CarbonImmutable $since): array;

    /** @param array<string, mixed> $raw */
    public function normalizeProgram(array $raw): AffiliateProgramData;

    /** @param array<string, mixed> $raw */
    public function normalizeTransaction(array $raw): AffiliateTransactionData;

    /** Tracking link with our click reference as the network's sub ID, built from the network's own template. */
    public function trackingUrl(AffiliateProgram $program, string $clickReference): string;
}
