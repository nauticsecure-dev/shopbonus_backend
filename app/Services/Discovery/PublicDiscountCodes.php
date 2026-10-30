<?php

namespace App\Services\Discovery;

use App\Enums\OfferStatus;
use App\Enums\VoucherCodeStatus;
use App\Enums\WebshopStatus;
use App\Models\Offer;
use App\Models\VoucherCode;

/**
 * Public reusable promotional codes for the homepage discount-codes section.
 * Unique-code offers never expose plaintext here.
 */
class PublicDiscountCodes
{
    /**
     * @return list<array<string, mixed>>
     */
    public function list(int $limit = 10, ?string $search = null): array
    {
        $limit = max(1, min(50, $limit));
        $like = $search !== null && trim($search) !== '' ? '%'.addcslashes(trim($search), '%_\\').'%' : null;

        $query = Offer::query()
            ->where('status', OfferStatus::Active)
            ->whereNotNull('slug')
            ->whereIn('webshop_id', function ($q) {
                $q->select('webshop_public_profiles.webshop_id')
                    ->from('webshop_public_profiles')
                    ->join('webshops', 'webshops.id', '=', 'webshop_public_profiles.webshop_id')
                    ->where('webshop_public_profiles.published', true)
                    ->where('webshops.status', WebshopStatus::Approved->value);
            })
            ->whereHas('currentVersion', function ($v) use ($like) {
                $v->where('code_mode', 'reusable')
                    ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
                    ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()));
                if ($like !== null) {
                    $v->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('public_description', 'like', $like));
                }
            })
            ->whereHas('codes', fn ($c) => $c->where('reusable', true)
                ->whereIn('status', [VoucherCodeStatus::Available->value, VoucherCodeStatus::Issued->value]))
            ->with(['currentVersion', 'webshop.publicProfile'])
            ->orderByDesc('activated_at')
            ->limit($limit);

        return $query->get()->map(function (Offer $offer) {
            $v = $offer->currentVersion;
            $profile = $offer->webshop?->publicProfile;
            if ($v === null || $profile === null) {
                return null;
            }
            /** @var VoucherCode|null $code */
            $code = VoucherCode::query()
                ->where('offer_id', $offer->id)
                ->where('reusable', true)
                ->whereIn('status', [VoucherCodeStatus::Available, VoucherCodeStatus::Issued])
                ->orderBy('id')
                ->first();
            if ($code === null || $code->code === '') {
                return null;
            }

            $logo = $profile->logo_path === null ? null : route('api.v1.public.webshops.logo', ['slug' => $profile->slug, 'v' => $profile->updated_at->getTimestamp()]);

            return [
                'id' => $code->public_id,
                'slug' => $offer->slug,
                'offer_slug' => $offer->slug,
                'title' => $v->name,
                'summary' => $v->summary(),
                'terms' => $v->public_description,
                'ends_at' => $v->ends_at?->toIso8601String(),
                'code' => $code->code,
                'webshop' => [
                    'slug' => $profile->slug,
                    'name' => $profile->display_name,
                    'image_url' => $profile->cover_url ?? $logo,
                    'logo_url' => $logo,
                    'cover_url' => $profile->cover_url,
                ],
                'href' => '/webshops/'.$profile->slug,
            ];
        })->filter()->values()->all();
    }
}
