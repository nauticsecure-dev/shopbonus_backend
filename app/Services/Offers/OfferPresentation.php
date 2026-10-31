<?php

namespace App\Services\Offers;

use App\Enums\OfferAcquisition;
use App\Models\Offer;
use App\Models\OfferVersion;
use App\Models\Webshop;
use App\Models\WebshopPublicProfile;
use App\Services\Discovery\PublicCatalog;

/**
 * Shared voucher-card presentation for merchant draft previews and the
 * public discovery card (Trello #9 §7). Draft previews are never published.
 */
class OfferPresentation
{
    /**
     * @return array<string, mixed>
     */
    public function forMerchant(Offer $offer, Webshop $webshop, ?WebshopPublicProfile $profile = null): array
    {
        $v = $offer->currentVersion;
        $profile ??= $webshop->publicProfile;
        $reward = $this->applicableRewardMinor($v);

        return [
            'published' => false,
            'offer_id' => $offer->public_id,
            'status' => $offer->status->value,
            'how_to_obtain' => match ($offer->acquisition) {
                OfferAcquisition::MerchantReward => 'earn_with_purchase',
                OfferAcquisition::WalletPurchase => 'buy_with_wallet',
                OfferAcquisition::PromotionalCode => 'claim',
            },
            'title' => $v?->name,
            'summary' => $v?->summary(),
            'badge_label' => $this->badgeLabel($v, $reward),
            'offer_line' => $this->offerLine($v, $reward),
            'journey' => $this->journey($offer->acquisition, $v, $reward),
            'discount_type' => $v?->discount_type,
            'discount_value' => $v?->discount_value,
            'currency' => $v->currency ?? 'EUR',
            'price_points' => $offer->acquisition === OfferAcquisition::WalletPurchase ? $v?->price_points : null,
            'min_order_minor' => $v?->min_order_minor,
            'max_discount_minor' => $v?->max_discount_minor,
            'claim_validity_days' => $v?->claim_validity_days,
            'redemption_validity_days' => $v?->redemption_validity_days,
            'starts_at' => $v?->starts_at?->toIso8601String(),
            'ends_at' => $v?->ends_at?->toIso8601String(),
            'conditions' => $v?->public_description,
            'combinable' => (bool) ($v?->combinable),
            'categories' => $this->categories($v, $profile),
            'webshop' => [
                'name' => $profile->display_name ?? $webshop->name,
                'short_description' => $profile?->short_description,
                'description' => $profile?->description,
                'logo_url' => $this->merchantLogoUrl($webshop, $profile),
                'cover_url' => $profile?->cover_url,
                'website_url' => $profile->website_url ?? $webshop->businessProfile?->website_url,
                'verified_domain' => $webshop->verified_domain,
                'ownership_verified' => $webshop->isOwnershipVerified(),
            ],
        ];
    }

    /**
     * Applicable consumer reward in minor units for a typical eligible order
     * (min order amount), capped by max discount for percentage offers.
     */
    public function applicableRewardMinor(?OfferVersion $v): ?int
    {
        if ($v === null) {
            return null;
        }
        if ($v->discount_type === 'fixed') {
            return $v->discount_value;
        }
        if ($v->min_order_minor === null) {
            return $v->max_discount_minor;
        }
        $raw = intdiv($v->min_order_minor * $v->discount_value + 5000, 10000);
        if ($v->max_discount_minor !== null) {
            $raw = min($raw, $v->max_discount_minor);
        }

        return $raw;
    }

    /**
     * @return array{headline: string, steps: list<string>, claim_window: string|null, redemption_window: string|null, campaign: string|null}
     */
    public function journey(OfferAcquisition $acquisition, ?OfferVersion $v, ?int $rewardMinor): array
    {
        $money = fn (?int $minor) => $minor === null ? null : '€'.number_format($minor / 100, 2, '.', '');
        $min = $money($v?->min_order_minor);
        $reward = $money($rewardMinor);

        if ($acquisition !== OfferAcquisition::MerchantReward) {
            return [
                'headline' => $v?->summary() ?? '',
                'steps' => [],
                'claim_window' => $v?->claim_validity_days ? "Claim within {$v->claim_validity_days} days after issue." : null,
                'redemption_window' => $v?->redemption_validity_days ? "Redeem within {$v->redemption_validity_days} days after claim." : null,
                'campaign' => $this->campaignWindow($v),
            ];
        }

        $headline = match (true) {
            $min !== null && $reward !== null => "Spend at least {$min} and earn {$reward} for your next eligible purchase.",
            $reward !== null => "Earn {$reward} for your next eligible purchase.",
            default => 'Earn a voucher for your next eligible purchase.',
        };

        return [
            'headline' => $headline,
            'steps' => [
                'Customer places an eligible order.',
                'ShopBonus verifies the order via the Universal Order Adapter.',
                'Eligibility determines the reward; a voucher is issued or reserved.',
                'Customer claims it with a verified ShopBonus account.',
                'Customer redeems it on a later eligible order.',
            ],
            'claim_window' => $v?->claim_validity_days
                ? "Customer has {$v->claim_validity_days} days to claim an issued voucher."
                : null,
            'redemption_window' => $v?->redemption_validity_days
                ? "A claimed voucher remains usable for {$v->redemption_validity_days} days."
                : ($v?->ends_at ? 'Usable until the campaign end date.' : null),
            'campaign' => $this->campaignWindow($v),
        ];
    }

    private function badgeLabel(?OfferVersion $v, ?int $rewardMinor): string
    {
        if ($v === null) {
            return '';
        }
        if ($v->discount_type === 'percentage') {
            $pct = rtrim(rtrim(number_format($v->discount_value / 100, 2, '.', ''), '0'), '.');

            return $pct.'% korting';
        }

        return '€'.number_format(($rewardMinor ?? $v->discount_value) / 100, 0, '.', '').' voucher';
    }

    private function offerLine(?OfferVersion $v, ?int $rewardMinor): string
    {
        if ($v === null) {
            return '';
        }
        $parts = [];
        if ($v->min_order_minor !== null) {
            $parts[] = 'Vanaf €'.number_format($v->min_order_minor / 100, 0, '.', '');
        }
        if ($v->discount_type === 'percentage' && $v->max_discount_minor !== null) {
            $parts[] = 'Maximaal €'.number_format($v->max_discount_minor / 100, 0, '.', '');
        } elseif ($rewardMinor !== null && $v->discount_type === 'fixed') {
            $parts[] = '€'.number_format($rewardMinor / 100, 0, '.', '').' voucher';
        }

        return implode(' · ', $parts);
    }

    private function campaignWindow(?OfferVersion $v): ?string
    {
        if ($v === null || ($v->starts_at === null && $v->ends_at === null)) {
            return null;
        }
        $from = $v->starts_at?->toDateString() ?? 'open';
        $to = $v->ends_at?->toDateString() ?? 'open';

        return "Campaign window: {$from} → {$to}";
    }

    /**
     * @return list<array{key: string, label: array{nl: string, en: string}}>
     */
    private function categories(?OfferVersion $v, ?WebshopPublicProfile $profile): array
    {
        $keys = $profile?->categories() ?: ($v->categories ?? []);

        return array_map(
            fn (string $key) => ['key' => $key, 'label' => PublicCatalog::categoryLabels($key)],
            $keys
        );
    }

    private function merchantLogoUrl(Webshop $webshop, ?WebshopPublicProfile $profile): ?string
    {
        if ($profile === null || $profile->logo_path === null) {
            return null;
        }

        // Merchant preview uses the private logo path marker; public route only works when published.
        return $profile->published
            ? route('api.v1.public.webshops.logo', ['slug' => $profile->slug, 'v' => $profile->updated_at->getTimestamp()])
            : null;
    }
}
