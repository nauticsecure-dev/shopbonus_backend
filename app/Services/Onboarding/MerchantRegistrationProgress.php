<?php

namespace App\Services\Onboarding;

use App\Enums\ConnectionStatus;
use App\Enums\OfferAcquisition;
use App\Enums\OfferStatus;
use App\Enums\VoucherCodeStatus;
use App\Enums\WebshopStatus;
use App\Models\Offer;
use App\Models\VoucherCode;
use App\Models\Webshop;
use App\Models\WebshopConnection;
use App\Services\Funding\MerchantFunding;
use App\Services\Offers\BranchOfferDefaults;
use App\Services\Offers\OfferPresentation;
use App\Services\Offers\OfferService;

/**
 * Merchant-facing 3-step registration progress for Trello #9
 * (Details → Voucher setup → Activate). Derived server-side; cannot be
 * written by clients. Completing Step 2 never activates the offer.
 */
class MerchantRegistrationProgress
{
    public function __construct(
        private readonly OfferService $offers,
        private readonly OfferPresentation $presentation,
        private readonly BranchOfferDefaults $defaults,
        private readonly MerchantFunding $funding,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(Webshop $webshop): array
    {
        $webshop->loadMissing(['businessProfile', 'publicProfile']);
        $profile = $webshop->businessProfile;
        $public = $webshop->publicProfile;
        $offer = Offer::with('currentVersion')
            ->where('webshop_id', $webshop->id)
            ->where('status', '!=', OfferStatus::Ended)
            ->latest('id')
            ->first();

        $detailsComplete = $profile?->confirmed_at !== null
            && trim((string) ($public->display_name ?? $webshop->name)) !== '';

        $offerReady = $offer !== null && $this->offers->missingForActivation($offer, $offer->currentVersion) === [];
        $codeCount = $offer === null ? 0
            : VoucherCode::where('offer_id', $offer->id)->where('status', VoucherCodeStatus::Available)->count();
        $hasCodes = $codeCount > 0;
        $voucherComplete = $offerReady && $hasCodes;

        $blockers = $this->blockers($webshop, $offer, $offerReady, $hasCodes);
        $activateStatus = match (true) {
            $offer?->status === OfferStatus::Active => 'complete',
            $voucherComplete && $blockers === [] => 'ready',
            $voucherComplete => 'blocked',
            default => 'not_started',
        };

        $current = match (true) {
            ! $detailsComplete => 'details',
            ! $voucherComplete => 'voucher_setup',
            default => 'activate',
        };

        $categories = $public?->categories() ?? [];
        if ($categories === [] && $profile?->business_category) {
            $categories = [$this->mapBusinessCategory((string) $profile->business_category)];
        }

        return [
            'steps' => [
                [
                    'key' => 'details',
                    'status' => $detailsComplete ? 'complete' : ($profile !== null ? 'in_progress' : 'not_started'),
                    'label' => ['nl' => 'Gegevens', 'en' => 'Details'],
                ],
                [
                    'key' => 'voucher_setup',
                    'status' => match (true) {
                        $voucherComplete => 'complete',
                        $current === 'voucher_setup' => 'current',
                        $detailsComplete => 'not_started',
                        default => 'pending',
                    },
                    'label' => ['nl' => 'Voucher instellen', 'en' => 'Voucher setup'],
                ],
                [
                    'key' => 'activate',
                    'status' => match ($activateStatus) {
                        'complete' => 'complete',
                        'ready', 'blocked' => 'current',
                        default => 'pending',
                    },
                    'label' => ['nl' => 'Activeren', 'en' => 'Activate'],
                ],
            ],
            'current_step' => $current,
            'first_offer_id' => $offer?->public_id,
            'available_codes' => $codeCount,
            'activation_blockers' => $blockers,
            'branch_defaults' => $this->defaults->forCategories($categories),
            'preview' => $offer === null ? null : $this->presentation->forMerchant($offer, $webshop, $public),
            'webshop_status' => $webshop->status->value,
        ];
    }

    /**
     * @return list<array{code: string, message: string}>
     */
    private function blockers(Webshop $webshop, ?Offer $offer, bool $offerReady, bool $hasCodes): array
    {
        $blockers = [];

        if ($webshop->status !== WebshopStatus::Approved) {
            $blockers[] = ['code' => 'merchant_not_approved', 'message' => 'Webshop approval is still required before activation.'];
        }
        if (! $webshop->isOwnershipVerified()) {
            $blockers[] = ['code' => 'webshop_unverified', 'message' => 'Domain ownership verification is required.'];
        }
        if ($offer === null) {
            $blockers[] = ['code' => 'offer_missing', 'message' => 'Create and save a draft offer first.'];
        } elseif (! $offerReady) {
            $missing = $this->offers->missingForActivation($offer, $offer->currentVersion);
            $blockers[] = ['code' => 'offer_incomplete', 'message' => 'Offer configuration is incomplete.', 'missing_fields' => $missing];
        }
        if (! $hasCodes) {
            $blockers[] = ['code' => 'codes_missing', 'message' => 'Import unique discount codes or use a supported code-generation route.'];
        }
        if ($offer !== null && $offer->currentVersion !== null
            && $offer->acquisition === OfferAcquisition::MerchantReward
            && ! $this->funding->canFund($webshop->id, $offer->currentVersion)) {
            $blockers[] = [
                'code' => 'funding_insufficient',
                'message' => 'Top up the merchant wallet before activating.',
                'required_minor' => $this->funding->costOf($offer->currentVersion)['total_minor'],
            ];
        }

        $connected = WebshopConnection::where('webshop_id', $webshop->id)
            ->where('status', ConnectionStatus::Connected)
            ->exists();
        if (! $connected) {
            // Soft signal for Step 3; does not publish the offer.
            $blockers[] = ['code' => 'connection_unverified', 'message' => 'Connect and verify the webshop integration for automatic redemption.'];
        }

        return $blockers;
    }

    private function mapBusinessCategory(string $raw): string
    {
        $n = mb_strtolower($raw);
        $keys = array_keys(config('shopbonus.discovery.categories', []));
        foreach ($keys as $key) {
            if ($key === $n || str_contains($n, str_replace('_', ' ', $key))) {
                return $key;
            }
        }
        if (str_contains($n, 'fashion') || str_contains($n, 'mode')) {
            return 'fashion';
        }
        if (str_contains($n, 'electron')) {
            return 'electronics';
        }
        if (str_contains($n, 'woon') || str_contains($n, 'home') || str_contains($n, 'interior')) {
            return 'home_living';
        }

        return 'other';
    }
}
