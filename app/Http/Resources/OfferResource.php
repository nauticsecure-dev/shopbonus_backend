<?php

namespace App\Http\Resources;

use App\Models\Offer;
use App\Models\OfferVersion;
use App\Services\Finance\FeeCalculator;
use App\Services\Offers\OfferService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Offer
 */
class OfferResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Offer $offer */
        $offer = $this->resource;
        /** @var OfferVersion $v */
        $v = $offer->currentVersion;

        return [
            'id' => $offer->public_id,
            'acquisition' => $offer->acquisition->value,
            'status' => $offer->status->value,
            'terms' => self::terms($v),
            'missing_for_activation' => app(OfferService::class)->missingForActivation($offer, $v),
            'cost_preview' => app(FeeCalculator::class)->offerPreview($offer->acquisition, $v),
            'activated_at' => $offer->activated_at?->toIso8601String(),
            'paused_at' => $offer->paused_at?->toIso8601String(),
            'ended_at' => $offer->ended_at?->toIso8601String(),
            'created_at' => $offer->created_at->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function terms(OfferVersion $v): array
    {
        return [
            'version' => $v->version,
            'locked' => $v->isLocked(),
            'summary' => $v->summary(),
            'name' => $v->name,
            'public_description' => $v->public_description,
            'discount_type' => $v->discount_type,
            'discount_value' => $v->discount_value,
            'currency' => $v->currency,
            'min_order_minor' => $v->min_order_minor,
            'max_discount_minor' => $v->max_discount_minor,
            'price_minor' => $v->price_minor,
            'starts_at' => $v->starts_at?->toIso8601String(),
            'ends_at' => $v->ends_at?->toIso8601String(),
            'claim_validity_days' => $v->claim_validity_days,
            'redemption_validity_days' => $v->redemption_validity_days,
            'categories' => $v->categories ?? [],
            'products' => $v->products ?? [],
            'combinable' => $v->combinable,
            'code_mode' => $v->code_mode,
            'allocation_limit' => $v->allocation_limit,
        ];
    }
}
