<?php

namespace App\Affiliate\Data;

final class AffiliateProgramData
{
    public function __construct(
        public readonly string $externalId,
        public readonly string $name,
        public readonly ?string $domain,
        public readonly bool $active,
        /** percentage | fixed */
        public readonly string $commissionType,
        /** Basis points for percentage, minor units for fixed. */
        public readonly int $commissionValue,
        public readonly string $currency,
        public readonly bool $categoryRestricted,
        public readonly bool $cashbackAllowed,
        public readonly string $trackingUrlTemplate,
        public readonly ?string $merchantUrl = null,
        /** @var array<string, mixed>|null */
        public readonly ?array $rawEvidence = null,
    ) {}
}
