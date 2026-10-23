<?php

namespace App\Services\Discovery;

use App\Enums\OfferAcquisition;
use App\Enums\OfferStatus;
use App\Enums\VoucherCodeStatus;
use App\Enums\VoucherStatus;
use App\Enums\WebshopStatus;
use App\Models\Offer;
use App\Models\OfferVersion;
use App\Models\Voucher;
use App\Models\VoucherCode;
use App\Models\WebshopPublicProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Public, read-only view of participating webshops and their offers
 * (Trello #18). Only approved webshops with a published profile are
 * listed, and only active offers of those; every field returned is
 * explicitly public. Nothing here reserves or allocates anything.
 */
class PublicCatalog
{
    /**
     * @param  array{search?: string|null, category?: string|null, has_offers?: bool|null, sort?: string|null, page?: int, per_page?: int}  $filters
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function webshops(array $filters): array
    {
        return DiscoveryCache::remember('webshops', $filters, function () use ($filters) {
            $query = $this->listedProfiles();
            $search = trim((string) ($filters['search'] ?? ''));

            if ($search !== '') {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $query->where(fn (Builder $q) => $q->where('display_name', 'like', $like)->orWhere('short_description', 'like', $like));
            }
            if (! empty($filters['category'])) {
                $query->whereIn('webshop_id', DB::table('webshop_public_categories')->select('webshop_id')->where('category', $filters['category']));
            }
            if (! empty($filters['has_offers'])) {
                $query->whereIn('webshop_id', $this->whereObtainable($this->listedOffers())->select('webshop_id'));
            }

            match ($filters['sort'] ?? 'relevance') {
                'name' => $query->orderBy('display_name'),
                'newest' => $query->orderByDesc('first_published_at'),
                default => $search !== ''
                    ? $query->orderByRaw('CASE WHEN display_name LIKE ? THEN 0 ELSE 1 END', [addcslashes($search, '%_\\').'%'])->orderBy('display_name')
                    : $query->orderByDesc(DB::raw('(SELECT COUNT(*) FROM offers WHERE offers.webshop_id = webshop_public_profiles.webshop_id AND offers.status = \'active\')'))->orderBy('display_name'),
            };

            $page = $query->orderBy('id')->paginate($filters['per_page'] ?? 20, ['*'], 'page', $filters['page'] ?? 1);

            return [
                'data' => array_values(array_map(fn (WebshopPublicProfile $p) => $this->webshopCard($p), $page->items())),
                'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
            ];
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    public function webshop(string $slug): ?array
    {
        return DiscoveryCache::remember('webshop', ['slug' => $slug], function () use ($slug) {
            $profile = $this->listedProfiles()->where('slug', $slug)->first();
            if ($profile === null) {
                return null;
            }

            $offers = $this->listedOffers()->where('webshop_id', $profile->webshop_id)->with('currentVersion')->orderByDesc('activated_at')->get();

            return $this->webshopCard($profile, withOffers: false) + [
                'description' => $profile->description,
                'website_url' => $profile->website_url,
                'offers' => $offers->map(fn (Offer $o) => $this->offerSummary($o, $profile))->filter()->values()->all(),
            ];
        });
    }

    /**
     * @param  array{search?: string|null, category?: string|null, webshop?: string|null, available?: bool|null, sort?: string|null, page?: int, per_page?: int}  $filters
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function offers(array $filters): array
    {
        return DiscoveryCache::remember('offers', $filters, function () use ($filters) {
            $query = $this->listedOffers()->with('currentVersion')
                ->whereHas('currentVersion', fn (Builder $v) => $v->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now())));
            $search = trim((string) ($filters['search'] ?? ''));

            if ($search !== '') {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $query->whereHas('currentVersion', fn (Builder $v) => $v->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('public_description', 'like', $like)));
            }
            if (! empty($filters['category'])) {
                $query->whereIn('webshop_id', DB::table('webshop_public_categories')->select('webshop_id')->where('category', $filters['category']));
            }
            if (! empty($filters['webshop'])) {
                $query->whereIn('webshop_id', WebshopPublicProfile::select('webshop_id')->where('slug', $filters['webshop']));
            }

            if (($filters['sort'] ?? 'newest') === 'ending_soon') {
                $endsAt = OfferVersion::select('ends_at')->whereColumn('offer_versions.id', 'offers.current_version_id')->limit(1);
                $query->orderByRaw('CASE WHEN ('.$endsAt->toSql().') IS NULL THEN 1 ELSE 0 END')->orderBy($endsAt);
            } else {
                $query->orderByDesc('activated_at');
            }

            if (! empty($filters['available'])) {
                $this->whereObtainable($query);
            }

            $page = $query->orderBy('id')->paginate($filters['per_page'] ?? 20, ['*'], 'page', $filters['page'] ?? 1);
            $profiles = WebshopPublicProfile::whereIn('webshop_id', collect($page->items())->pluck('webshop_id')->unique())->get()->keyBy('webshop_id');

            return [
                'data' => array_values(array_filter(array_map(fn (Offer $o) => $this->offerSummary($o, $profiles[$o->webshop_id]), $page->items()))),
                'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
            ];
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    public function offer(string $slug): ?array
    {
        return DiscoveryCache::remember('offer', ['slug' => $slug], function () use ($slug) {
            $offer = $this->listedOffers()->with('currentVersion')->where('slug', $slug)->first();
            if ($offer === null) {
                return null;
            }

            $profile = WebshopPublicProfile::where('webshop_id', $offer->webshop_id)->firstOrFail();

            return $this->offerSummary($offer, $profile, detailed: true);
        });
    }

    /** The webshop ID behind a listed slug, or null when it is not (or no longer) listed. */
    public function listedWebshopId(string $slug): ?int
    {
        $id = $this->listedProfiles()->where('slug', $slug)->value('webshop_id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Public cards of the listed webshops among the given IDs.
     *
     * @param  list<int>  $webshopIds
     * @return list<array<string, mixed>>
     */
    public function cardsFor(array $webshopIds): array
    {
        return $this->listedProfiles()->whereIn('webshop_id', $webshopIds)->orderBy('display_name')->get()
            ->map(fn (WebshopPublicProfile $p) => $this->webshopCard($p, withOffers: false))->values()->all();
    }

    public function logo(string $slug): ?WebshopPublicProfile
    {
        return $this->listedProfiles()->where('slug', $slug)->whereNotNull('logo_path')->first();
    }

    // ------------------------------------------------------------------ internals

    /** @return Builder<WebshopPublicProfile> */
    private function listedProfiles(): Builder
    {
        return WebshopPublicProfile::query()->where('published', true)
            ->whereIn('webshop_id', DB::table('webshops')->select('id')->where('status', WebshopStatus::Approved->value));
    }

    /** @return Builder<Offer> */
    private function listedOffers(): Builder
    {
        return Offer::query()->where('status', OfferStatus::Active)->whereNotNull('slug')
            ->whereIn('webshop_id', $this->listedProfiles()->select('webshop_id'));
    }

    /**
     * SQL form of availability() === 'available': started, not ended, a code
     * left, allocation limit not reached.
     *
     * @param  Builder<Offer>  $query
     * @return Builder<Offer>
     */
    private function whereObtainable(Builder $query): Builder
    {
        return $query
            ->whereHas('currentVersion', fn (Builder $v) => $v
                ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
                ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
                ->where(fn ($q) => $q->whereNull('allocation_limit')->orWhereRaw(
                    "allocation_limit > (SELECT COUNT(*) FROM vouchers WHERE vouchers.offer_id = offer_versions.offer_id AND vouchers.status <> 'cancelled')",
                )))
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('voucher_codes')
                ->whereColumn('voucher_codes.offer_id', 'offers.id')->where('voucher_codes.status', VoucherCodeStatus::Available->value));
    }

    /** @return array<string, mixed> */
    private function webshopCard(WebshopPublicProfile $p, bool $withOffers = true): array
    {
        $card = [
            'slug' => $p->slug,
            'name' => $p->display_name,
            'short_description' => $p->short_description,
            'logo_url' => $p->logo_path === null ? null : route('api.v1.public.webshops.logo', ['slug' => $p->slug, 'v' => $p->updated_at->getTimestamp()]),
            'categories' => array_map(fn (string $key) => ['key' => $key, 'label' => self::categoryLabels($key)], $p->categories()),
        ];

        if ($withOffers) {
            $offers = $this->listedOffers()->where('webshop_id', $p->webshop_id)->with('currentVersion')->orderByDesc('activated_at')->get()
                ->map(fn (Offer $o) => $this->offerSummary($o, $p))->filter();
            $card['offer_count'] = $offers->count();
            $card['featured_offer'] = $offers->firstWhere('availability', 'available') ?? $offers->first();
        }

        return $card;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function offerSummary(Offer $offer, WebshopPublicProfile $profile, bool $detailed = false): ?array
    {
        $v = $offer->currentVersion;
        if ($v === null) {
            return null;
        }

        $summary = [
            'slug' => $offer->slug,
            'title' => $v->name,
            'summary' => $v->summary(),
            'how_to_obtain' => match ($offer->acquisition) {
                OfferAcquisition::MerchantReward => 'earn_with_purchase',
                OfferAcquisition::WalletPurchase => 'buy_with_wallet',
                OfferAcquisition::PromotionalCode => 'claim',
            },
            'discount_type' => $v->discount_type,
            'discount_value' => $v->discount_value,
            'currency' => $v->currency,
            'min_order_minor' => $v->min_order_minor,
            'price_minor' => $v->price_minor,
            'ends_at' => $v->ends_at?->toIso8601String(),
            'availability' => $this->availability($offer, $v),
            'webshop' => ['slug' => $profile->slug, 'name' => $profile->display_name],
        ];

        if ($detailed) {
            $summary += [
                'description' => $v->public_description,
                'max_discount_minor' => $v->max_discount_minor,
                'starts_at' => $v->starts_at?->toIso8601String(),
                'redemption_validity_days' => $v->redemption_validity_days,
                'categories' => $v->categories ?? [],
                'products' => $v->products ?? [],
                'combinable' => $v->combinable,
                'terms_version' => $v->version,
            ];
        }

        return $summary;
    }

    private function availability(Offer $offer, OfferVersion $v): string
    {
        if ($v->starts_at !== null && $v->starts_at->isFuture()) {
            return 'upcoming';
        }
        if ($v->ends_at !== null && $v->ends_at->isPast()) {
            return 'expired';
        }
        if ($v->allocation_limit !== null
            && Voucher::where('offer_id', $offer->id)->where('status', '!=', VoucherStatus::Cancelled)->count() >= $v->allocation_limit) {
            return 'sold_out';
        }

        $stock = VoucherCode::where('offer_id', $offer->id)->where('status', VoucherCodeStatus::Available)->exists();

        return $stock ? 'available' : 'sold_out';
    }

    /** @return array{nl: string, en: string} */
    public static function categoryLabels(string $key): array
    {
        $labels = config('shopbonus.discovery.categories.'.$key, []);

        return ['nl' => $labels['nl'] ?? $key, 'en' => $labels['en'] ?? $key];
    }
}
