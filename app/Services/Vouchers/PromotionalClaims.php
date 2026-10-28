<?php

namespace App\Services\Vouchers;

use App\Enums\OfferAcquisition;
use App\Enums\VoucherOrigin;
use App\Enums\WebshopStatus;
use App\Exceptions\ApiException;
use App\Models\Offer;
use App\Models\User;
use App\Models\Voucher;
use App\Models\Webshop;
use App\Models\WebshopPublicProfile;
use Illuminate\Support\Facades\DB;

class PromotionalClaims
{
    public function __construct(private readonly VoucherInventory $inventory) {}

    /** One promotional voucher per account and offer, including concurrent retries. */
    public function claim(string $slug, User $consumer): Voucher
    {
        if ($consumer->email_verified_at === null || ! $consumer->isActive()) {
            throw new ApiException('Only verified, active accounts can receive vouchers.', 403, 'consumer_not_verified');
        }

        return DB::transaction(function () use ($slug, $consumer) {
            $offer = Offer::where('slug', $slug)->lockForUpdate()->first();
            if ($offer === null || ! Webshop::whereKey($offer->webshop_id)->where('status', WebshopStatus::Approved)->lockForUpdate()->first()
                || ! WebshopPublicProfile::where('webshop_id', $offer->webshop_id)->where('published', true)->lockForUpdate()->first()) {
                throw new ApiException('Offer not found.', 404, 'not_found');
            }
            if ($offer->acquisition !== OfferAcquisition::PromotionalCode) {
                throw ApiException::conflict('This offer cannot be claimed for free.', 'offer_not_claimable');
            }
            $key = 'promotional:'.$offer->public_id.':'.$consumer->public_id;
            $existing = Voucher::where('webshop_id', $offer->webshop_id)->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                return $existing;
            }

            return $this->inventory->issue($this->inventory->reserve($offer, VoucherOrigin::Promotional, $key, actorUserId: $consumer->id), $consumer);
        }, 3);
    }
}
