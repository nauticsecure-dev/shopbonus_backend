<?php

namespace App\Services\Offers;

use App\Enums\AuditCategory;
use App\Enums\OfferAcquisition;
use App\Enums\OfferStatus;
use App\Exceptions\ApiException;
use App\Models\Offer;
use App\Models\OfferVersion;
use App\Models\WebshopMembership;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Offers with versioned terms (Trello #7 §4 "Changes to an active offer must
 * not silently alter the contractual conditions of already-issued vouchers").
 *
 * While the current version is unlocked (draft, never used) it is edited in
 * place. Once locked (activation or first voucher), any change to terms
 * creates a new version; existing vouchers keep pointing at theirs.
 */
class OfferService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $terms
     */
    public function create(WebshopMembership $actor, OfferAcquisition $acquisition, array $terms): Offer
    {
        return DB::transaction(function () use ($actor, $acquisition, $terms) {
            $offer = new Offer(['acquisition' => $acquisition, 'status' => OfferStatus::Draft]);
            $offer->webshop_id = $actor->webshop_id;
            $offer->created_by = $actor->user_id;
            $offer->save();

            $defaults = $acquisition === OfferAcquisition::MerchantReward
                ? ['claim_validity_days' => (int) config('shopbonus.offers.default_claim_validity_days', 90)] : [];

            $version = $offer->versions()->create([
                ...$defaults, ...Arr::only($terms, OfferVersion::TERM_FIELDS),
                'version' => 1, 'created_by' => $actor->user_id,
            ]);
            $offer->forceFill(['current_version_id' => $version->id])->save();

            $this->audit->record('offer.created', category: AuditCategory::Voucher, resource: $offer, webshopId: $offer->webshop_id,
                metadata: ['acquisition' => $acquisition->value, 'version' => 1]);

            return $offer->setRelation('currentVersion', $version);
        });
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public function update(WebshopMembership $actor, Offer $offer, array $changes): Offer
    {
        if ($offer->status === OfferStatus::Ended) {
            throw ApiException::conflict('Ended offers cannot be edited.', 'offer_ended');
        }

        return DB::transaction(function () use ($actor, $offer, $changes) {
            $current = OfferVersion::whereKey($offer->current_version_id)->lockForUpdate()->firstOrFail();
            $terms = Arr::only($changes, OfferVersion::TERM_FIELDS);

            if (! $current->isLocked()) {
                $current->update($terms);
                $this->audit->record('offer.updated', category: AuditCategory::Voucher, resource: $offer,
                    webshopId: $offer->webshop_id, metadata: ['version' => $current->version, 'fields' => array_keys($terms)]);

                return $offer->setRelation('currentVersion', $current->refresh());
            }

            $next = $offer->versions()->create([
                ...$current->only(OfferVersion::TERM_FIELDS), // cast values: raw attributes would double-encode JSON
                ...$terms,
                'version' => $current->version + 1,
                'created_by' => $actor->user_id,
                // An active offer's new version is used for new issuance at once, so it is locked too.
                'locked_at' => $offer->status === OfferStatus::Draft ? null : now(),
            ]);
            $offer->forceFill(['current_version_id' => $next->id])->save();

            $this->audit->record('offer.version_created', category: AuditCategory::Voucher, resource: $offer, webshopId: $offer->webshop_id,
                metadata: ['from_version' => $current->version, 'version' => $next->version, 'fields' => array_keys($terms)]);

            return $offer->setRelation('currentVersion', $next);
        });
    }

    public function transition(Offer $offer, OfferStatus $to): Offer
    {
        if (! $offer->status->canBecome($to)) {
            throw ApiException::conflict("An offer cannot go from {$offer->status->value} to {$to->value}.", 'invalid_status_transition');
        }

        return DB::transaction(function () use ($offer, $to) {
            $version = OfferVersion::whereKey($offer->current_version_id)->lockForUpdate()->firstOrFail();

            if ($to === OfferStatus::Active) {
                $this->assertActivatable($offer, $version);
                if (! $version->isLocked()) {
                    $version->forceFill(['locked_at' => now()])->save();
                }
            }

            $from = $offer->status;
            $offer->forceFill([
                'status' => $to,
                'activated_at' => $to === OfferStatus::Active ? ($offer->activated_at ?? now()) : $offer->activated_at,
                'paused_at' => $to === OfferStatus::Paused ? now() : null,
                'ended_at' => $to === OfferStatus::Ended ? now() : null,
            ])->save();

            $this->audit->record('offer.status_changed', category: AuditCategory::Voucher, resource: $offer, webshopId: $offer->webshop_id,
                metadata: ['from' => $from->value, 'to' => $to->value, 'version' => $version->version]);

            return $offer->setRelation('currentVersion', $version);
        });
    }

    /** @return list<string> */
    public function missingForActivation(Offer $offer, OfferVersion $v): array
    {
        $missing = [];

        if ($v->discount_type === 'percentage' && $offer->acquisition === OfferAcquisition::MerchantReward && $v->max_discount_minor === null) {
            $missing[] = 'max_discount_minor';
        }
        if ($offer->acquisition === OfferAcquisition::WalletPurchase && $v->price_minor === null) {
            $missing[] = 'price_minor';
        }
        if ($offer->acquisition === OfferAcquisition::MerchantReward && $v->claim_validity_days === null) {
            $missing[] = 'claim_validity_days';
        }
        if ($v->redemption_validity_days === null && $v->ends_at === null) {
            $missing[] = 'redemption_validity_days';
        }

        return $missing;
    }

    private function assertActivatable(Offer $offer, OfferVersion $v): void
    {
        $missing = $this->missingForActivation($offer, $v);

        if ($missing !== []) {
            throw new ApiException('The offer is incomplete.', 422, 'offer_incomplete', ['missing_fields' => $missing]);
        }

        if ($v->ends_at !== null && $v->ends_at->isPast()) {
            throw new ApiException('The offer end date is in the past.', 422, 'offer_expired');
        }
    }
}
