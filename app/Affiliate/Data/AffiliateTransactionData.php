<?php

namespace App\Affiliate\Data;

use Carbon\CarbonImmutable;

/**
 * A network conversion in Shopbonus terms. Amounts in integer minor units.
 */
final class AffiliateTransactionData
{
    public function __construct(
        public readonly string $externalId,
        public readonly string $programExternalId,
        public readonly ?string $clickReference,
        public readonly ?string $orderReference,
        public readonly int $orderAmountMinor,
        public readonly string $currency,
        public readonly int $commissionMinor,
        /** pending | approved | rejected | reversed */
        public readonly string $networkStatus,
        public readonly CarbonImmutable $transactionAt,
        public readonly ?CarbonImmutable $networkUpdatedAt,
    ) {}
}
