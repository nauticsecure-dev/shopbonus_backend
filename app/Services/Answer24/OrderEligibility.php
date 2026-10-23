<?php

namespace App\Services\Answer24;

use App\Enums\OfferAcquisition;
use App\Enums\OfferStatus;
use App\Integrations\Data\PaymentStatus;
use App\Models\ContactSuppression;
use App\Models\Offer;
use App\Models\Webshop;
use App\Models\WebshopOrder;
use App\Services\Agreements\AgreementService;
use App\Support\PhoneNumber;

/**
 * Decides whether an order may enter the Answer24 contact workflow (Trello #1
 * "Eligibility"). Every failed check is recorded as a reason. An order
 * webhook alone never authorises contact: the webshop must have attested a
 * lawful basis and, for consent-based contact, the order must carry consent.
 *
 * Results: eligibility_status eligible|ineligible; contact_status
 * not_callable (valid order, no usable phone) or blocked; the planner then
 * sets grouped/queued.
 */
class OrderEligibility
{
    public function __construct(private readonly AgreementService $agreements) {}

    /**
     * @return array{eligible: bool, callable: bool, reasons: list<string>, offer: Offer|null, phone: string|null, basis: string|null}
     */
    public function evaluate(WebshopOrder $order, Webshop $webshop): array
    {
        $reasons = [];
        $settings = $webshop->answer24_settings ?? [];
        $basis = is_string($settings['lawful_basis'] ?? null) ? $settings['lawful_basis'] : null;

        if (! $webshop->status->isCommerciallyActive() || ! $this->agreements->hasAcceptedCurrent($webshop->loadMissing('businessProfile'))) {
            $reasons[] = 'webshop_not_active';
        }

        $offer = Offer::with('currentVersion')->where('webshop_id', $webshop->id)->where('acquisition', OfferAcquisition::MerchantReward)
            ->where('status', OfferStatus::Active)->latest('activated_at')->first();
        if ($offer === null) {
            $reasons[] = 'no_active_reward_offer';
        } elseif (($min = $offer->currentVersion?->min_order_minor) !== null && ($order->amount_minor ?? 0) < $min) {
            $reasons[] = 'below_minimum_order_value';
        }

        // Payment first: only a provider-reported paid order, never pending/unknown.
        if ($order->payment_status !== PaymentStatus::Paid) {
            $reasons[] = match ($order->payment_status) {
                PaymentStatus::Cancelled => 'cancelled',
                PaymentStatus::Refunded, PaymentStatus::PartiallyRefunded => 'refunded',
                default => 'not_paid',
            };
        }

        if ($order->ingestion_status !== 'complete') {
            $reasons[] = 'incomplete_order';
        }

        $phone = PhoneNumber::normalize($order->customer_phone);

        if (($settings['enabled'] ?? false) !== true) {
            $reasons[] = 'answer24_disabled';
        }

        if ($basis === null) {
            $reasons[] = 'no_lawful_basis';
        } elseif ($basis === 'legitimate_interest' && ! config('shopbonus.answer24.allow_legitimate_interest')) {
            $reasons[] = 'lawful_basis_not_permitted';
        } elseif ($basis === 'consent_at_checkout' && $order->contact_consent !== true) {
            $reasons[] = 'no_contact_consent';
        }

        if ($this->isSuppressed($webshop->id, $order->customer_email, $phone)) {
            $reasons[] = 'suppressed';
        }

        // A missing/invalid phone keeps the order valid but not callable (Trello #1 E2E 4).
        $callable = $phone !== null;

        return ['eligible' => $reasons === [], 'callable' => $callable, 'reasons' => $reasons, 'offer' => $offer, 'phone' => $phone, 'basis' => $basis];
    }

    public function isSuppressed(int $webshopId, ?string $email, ?string $phone): bool
    {
        $subjects = array_filter([
            $email !== null ? ['email', ContactSuppression::emailHash($email)] : null,
            $phone !== null ? ['phone', PhoneNumber::hash($phone)] : null,
        ]);

        foreach ($subjects as [$kind, $hash]) {
            if (ContactSuppression::where('kind', $kind)->where('subject_hash', $hash)
                ->where(fn ($q) => $q->whereNull('webshop_id')->orWhere('webshop_id', $webshopId))->exists()) {
                return true;
            }
        }

        return false;
    }
}
