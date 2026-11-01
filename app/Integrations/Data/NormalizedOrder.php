<?php

namespace App\Integrations\Data;

use Carbon\CarbonImmutable;

/**
 * Provider-independent order (Trello #1 "Normalized orders"). Values are
 * taken from the provider event only; missing data stays null and is
 * reported, never fabricated.
 */
final readonly class NormalizedOrder
{
    public const MANDATORY = ['external_id', 'reference', 'first_name', 'email', 'amount_minor', 'currency', 'placed_at'];

    public function __construct(
        public ?string $externalId,
        public ?string $reference,
        public ?string $firstName,
        public ?string $email,
        public ?string $phone,
        public ?int $amountMinor,
        public ?string $currency,
        public PaymentStatus $paymentStatus,
        public ?string $orderStatus,
        public ?CarbonImmutable $placedAt,
        public ?CarbonImmutable $paidAt,
        public ?CarbonImmutable $cancelledAt,
        public ?CarbonImmutable $refundedAt,
        public ?int $refundedMinor = null,
        // Provider's own last-modified time: used to reject out-of-order events.
        public ?CarbonImmutable $providerUpdatedAt = null,
        // Discount codes the customer used (for verified voucher redemption).
        /** @var list<string> */
        public array $couponCodes = [],
        // Explicit contact consent captured at checkout, if the shop reports it (null = unknown).
        public ?bool $contactConsent = null,
        // ShopBonus banner click ID carried through the shop's checkout (Trello #24 §2), if present.
        public ?string $attributionClickId = null,
        public ?string $bannerAssignmentToken = null,
        /** @var list<array<string,mixed>>|null null means unavailable or incomplete, [] means observed empty. */
        public ?array $lineItems = null,
        /** @var array<string,mixed>|null provider tax/shipping/discount/original/current totals (AmountBreakdown); null when not reported */
        public ?array $amountBreakdown = null,
    ) {}

    /** @return list<string> */
    public function missingFields(): array
    {
        $values = [
            'external_id' => $this->externalId, 'reference' => $this->reference, 'first_name' => $this->firstName,
            'email' => $this->email, 'amount_minor' => $this->amountMinor, 'currency' => $this->currency, 'placed_at' => $this->placedAt,
        ];

        return array_keys(array_filter($values, fn ($v) => $v === null || $v === ''));
    }

    public function isComplete(): bool
    {
        return $this->missingFields() === [];
    }

    public function hasPhone(): bool
    {
        return $this->phone !== null && $this->phone !== '';
    }

    /**
     * Safe summary for event history (no PII).
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            'kind' => 'order',
            'complete' => $this->isComplete(),
            'missing_fields' => $this->missingFields(),
            'has_phone' => $this->hasPhone(),
            'payment_status' => $this->paymentStatus->value,
            'currency' => $this->currency,
            'amount_minor' => $this->amountMinor,
        ];
    }
}
