<?php

namespace App\Services\Discovery;

use App\Enums\OfferAcquisition;
use App\Enums\OfferStatus;
use App\Enums\VoucherCodeStatus;
use App\Enums\VoucherStatus;
use App\Enums\WebshopStatus;
use App\Models\AffiliateRewardRule;
use App\Models\MerchantCommissionAgreement;
use App\Models\Offer;
use App\Models\OfferVersion;
use App\Models\Voucher;
use App\Models\VoucherCode;
use App\Models\Webshop;
use App\Models\WebshopPublicProfile;
use App\Services\Affiliate\ProgramSelector;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Public, read-only view of participating webshops and their offers
 * (Trello #18). Only approved webshops with a published profile are
 * listed, and only active offers of those; every field returned is
 * explicitly public. Nothing here reserves or allocates anything.
 */
class PublicCatalog
{
    public function __construct(private readonly ProgramSelector $programSelector) {}

    /**
     * @param  array{search?: string|null, category?: string|null, has_offers?: bool|null, sort?: string|null, page?: int, per_page?: int, slugs?: list<string>|null}  $filters
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function webshops(array $filters): array
    {
        return DiscoveryCache::remember('webshops', $filters, function () use ($filters) {
            $query = $this->listedProfiles();
            $search = trim((string) ($filters['search'] ?? ''));
            $slugs = array_values(array_unique(array_filter(
                $filters['slugs'] ?? [],
                fn ($s) => is_string($s) && $s !== ''
            )));

            if ($slugs !== []) {
                $query->whereIn('slug', $slugs);
            }

            if ($search !== '') {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $categoryKeys = $this->categoryKeysMatchingSearch($search);
                $query->where(function (Builder $q) use ($like, $categoryKeys) {
                    $q->where('display_name', 'like', $like)
                        ->orWhere('short_description', 'like', $like)
                        ->orWhere('description', 'like', $like);
                    if ($categoryKeys !== []) {
                        $q->orWhereIn('webshop_id', DB::table('webshop_public_categories')->select('webshop_id')->whereIn('category', $categoryKeys));
                    }
                });
            }
            if (! empty($filters['category'])) {
                $query->whereIn('webshop_id', DB::table('webshop_public_categories')->select('webshop_id')->where('category', $filters['category']));
            }
            if (! empty($filters['has_offers'])) {
                $query->whereIn('webshop_id', $this->whereObtainable($this->listedOffers())->select('webshop_id'));
            }

            $activeOfferCountSql = '(SELECT COUNT(*) FROM offers'
                .' INNER JOIN offer_versions ON offer_versions.id = offers.current_version_id'
                .' WHERE offers.webshop_id = webshop_public_profiles.webshop_id'
                .' AND offers.status = \'active\' AND offers.slug IS NOT NULL'
                .' AND (offer_versions.ends_at IS NULL OR offer_versions.ends_at > ?))';

            match ($filters['sort'] ?? 'relevance') {
                'name' => $query->orderBy('display_name'),
                'newest' => $query->orderByDesc('first_published_at'),
                'most_offers' => $query->orderByRaw($activeOfferCountSql.' DESC', [now()])->orderBy('display_name'),
                default => $search !== ''
                    ? $query->orderByRaw('CASE WHEN display_name LIKE ? THEN 0 ELSE 1 END', [addcslashes($search, '%_\\').'%'])->orderBy('display_name')
                    : $query->orderByRaw($activeOfferCountSql.' DESC', [now()])->orderBy('display_name'),
            };

            $page = $query->orderBy('id')->paginate($filters['per_page'] ?? 20, ['*'], 'page', $filters['page'] ?? 1);
            $items = $page->items();
            $offersByShop = $this->offersGroupedByWebshop(array_map(fn (WebshopPublicProfile $p) => $p->webshop_id, $items));

            $cards = array_values(array_map(
                fn (WebshopPublicProfile $p) => $this->webshopCard($p, withOffers: true, preloadedOffers: $offersByShop[$p->webshop_id] ?? collect()),
                $items
            ));

            // Preserve CMS featured order when an explicit slug list was requested.
            if ($slugs !== []) {
                $bySlug = [];
                foreach ($cards as $card) {
                    $bySlug[$card['slug']] = $card;
                }
                $ordered = [];
                foreach ($slugs as $slug) {
                    if (isset($bySlug[$slug])) {
                        $ordered[] = $bySlug[$slug];
                    }
                }
                $cards = $ordered;
            }

            return [
                'data' => $cards,
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
            $cashback = $this->affiliateCashback($profile->webshop_id);
            $offerRows = $offers->map(fn (Offer $o) => $this->offerSummary($o, $profile, cashback: $cashback))->filter()->values();
            if ($cashback !== null) {
                $offerRows = $offerRows->prepend($this->syntheticCashbackOffer($cashback, $profile));
            }
            $sorted = $this->sortBenefitOffers($offerRows);

            return $this->webshopCard($profile, withOffers: false, cashback: $cashback) + [
                'description' => $profile->description,
                'website_url' => $profile->website_url,
                'benefits' => $this->webshopBenefits($cashback, $sorted->filter(fn (array $o) => ($o['source'] ?? '') !== 'affiliate')->values()),
                'offers' => $sorted->all(),
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

    public function cover(string $slug): ?WebshopPublicProfile
    {
        return $this->listedProfiles()->where('slug', $slug)->whereNotNull('cover_path')->first();
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

    /**
     * @param  Collection<int, Offer>|null  $preloadedOffers
     * @param  array<string, mixed>|null  $cashback
     * @return array<string, mixed>
     */
    private function webshopCard(WebshopPublicProfile $p, bool $withOffers = true, $preloadedOffers = null, ?array $cashback = null): array
    {
        $cashback ??= $this->affiliateCashback($p->webshop_id);
        $card = [
            'slug' => $p->slug,
            'name' => $p->display_name,
            'short_description' => $p->short_description,
            'logo_url' => $p->logo_path === null ? null : route('api.v1.public.webshops.logo', ['slug' => $p->slug, 'v' => $p->updated_at->getTimestamp()]),
            'cover_url' => $p->cover_path === null ? $p->cover_url : route('api.v1.public.webshops.cover', ['slug' => $p->slug, 'v' => $p->updated_at->getTimestamp()]),
            'categories' => array_map(fn (string $key) => ['key' => $key, 'label' => self::categoryLabels($key)], $p->categories()),
        ];

        if ($withOffers) {
            $offerModels = $preloadedOffers ?? $this->listedOffers()->where('webshop_id', $p->webshop_id)->with('currentVersion')->orderByDesc('activated_at')->get();
            $offers = $this->sortBenefitOffers($offerModels->map(fn (Offer $o) => $this->offerSummary($o, $p, cashback: $cashback))->filter()->values());
            // Counter excludes expired (and null) summaries; matches live eligibility, not a stored counter.
            $active = $offers->filter(fn (array $o) => ($o['availability'] ?? '') !== 'expired');
            $card['offer_count'] = $active->count();
            $card['active_offer_count'] = $card['offer_count'];
            $card['featured_offer'] = $this->pickFeaturedOffer($active, $cashback);
        }

        [$badge, $cashbackLabel] = $this->webshopBenefitLabels($p->webshop_id, $card['featured_offer'] ?? null, $cashback);
        $card['badge_label'] = $badge;
        $card['cashback_label'] = $cashbackLabel;

        return $card;
    }

    /**
     * Customer-facing badge: prefer approved agreement consumer cashback rate;
     * else featured offer discount (never raw network commission).
     *
     * @param  array<string, mixed>|null  $featured
     * @param  array<string, mixed>|null  $affiliateCashback
     * @return array{0: string|null, 1: string|null}
     */
    private function webshopBenefitLabels(int $webshopId, ?array $featured, ?array $affiliateCashback = null): array
    {
        $agreement = MerchantCommissionAgreement::current($webshopId);
        $rate = $agreement?->consumerCashbackRate();
        if ($rate !== null && $rate > 0) {
            $label = $this->formatCashbackLabel($rate, categoryRestricted: true);

            return [$label, $label];
        }

        $affiliateCashback ??= $this->affiliateCashback($webshopId);
        if (($affiliateCashback['label'] ?? null) !== null) {
            $label = $affiliateCashback['label'];

            return [$label, $label];
        }

        if ($featured === null) {
            return [null, null];
        }

        $type = $featured['discount_type'] ?? null;
        $value = $featured['discount_value'] ?? null;
        if ($type === 'percentage' && is_int($value)) {
            $pct = rtrim(rtrim(number_format($value / 100, 2, '.', ''), '0'), '.');
            $label = $pct.'% korting';

            return [$label, null];
        }
        if ($type === 'fixed' && is_int($value)) {
            $label = '€'.number_format($value / 100, $value % 100 === 0 ? 0 : 2, ',', '').' korting';

            return [$label, null];
        }

        return [null, null];
    }

    /**
     * @param  list<int>  $webshopIds
     * @return Collection<int, Collection<int, Offer>>
     */
    private function offersGroupedByWebshop(array $webshopIds)
    {
        if ($webshopIds === []) {
            return collect();
        }

        return $this->listedOffers()->whereIn('webshop_id', $webshopIds)->with('currentVersion')
            ->orderByDesc('activated_at')->get()->groupBy('webshop_id');
    }

    /** @return list<string> */
    private function categoryKeysMatchingSearch(string $search): array
    {
        $needle = mb_strtolower($search);
        $keys = [];
        foreach (config()->array('shopbonus.discovery.categories') as $key => $labels) {
            $haystacks = [$key, (string) ($labels['nl'] ?? ''), (string) ($labels['en'] ?? '')];
            foreach ($haystacks as $label) {
                if ($label !== '' && str_contains(mb_strtolower($label), $needle)) {
                    $keys[] = $key;
                    break;
                }
            }
        }

        return $keys;
    }

    /**
     * @param  array<string, mixed>|null  $cashback
     * @return array<string, mixed>|null
     */
    private function offerSummary(Offer $offer, WebshopPublicProfile $profile, bool $detailed = false, ?array $cashback = null): ?array
    {
        $v = $offer->currentVersion;
        if ($v === null) {
            return null;
        }

        $cashback ??= $this->affiliateCashback($profile->webshop_id);
        $offerKind = $this->offerKind($offer, $v, $cashback);
        $summary = [
            'slug' => $offer->slug,
            'title' => $v->name,
            'summary' => $v->summary(),
            'offer_kind' => $offerKind,
            'cta_hint' => $this->ctaHint($offerKind),
            'how_to_obtain' => match ($offer->acquisition) {
                OfferAcquisition::MerchantReward => 'earn_with_purchase',
                OfferAcquisition::WalletPurchase => 'buy_with_wallet',
                OfferAcquisition::PromotionalCode => 'claim',
            },
            'discount_type' => $v->discount_type,
            'discount_value' => $v->discount_value,
            'currency' => $v->currency,
            'min_order_minor' => $v->min_order_minor,
            'price_minor' => $offerKind === 'voucher_purchase' ? $v->price_minor : null,
            'ends_at' => $v->ends_at?->toIso8601String(),
            'availability' => $this->availability($offer, $v),
            'cashback_label' => in_array($offerKind, ['combined', 'cashback'], true) ? ($cashback['label'] ?? null) : null,
            'benefit_label' => $this->offerBenefitLabel([
                'discount_type' => $v->discount_type,
                'discount_value' => $v->discount_value,
            ]),
            'combinable' => (bool) $v->combinable,
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

    /** @return array<string, mixed>|null */
    private function affiliateCashback(int $webshopId): ?array
    {
        $shop = Webshop::find($webshopId);
        if ($shop === null) {
            return null;
        }

        $program = $this->programSelector->current($shop);
        if ($program === null || ! $program->active || ! $program->cashback_allowed || $program->commission_type !== 'percentage') {
            return null;
        }

        $rule = AffiliateRewardRule::current(webshopId: $webshopId, commissionSource: 'affiliate')
            ?? AffiliateRewardRule::current(webshopId: $webshopId);
        $shareBps = $rule?->share_bps ?? (int) config('shopbonus.affiliate.default_consumer_share_bps', 7000);
        $rate = ($program->commission_value * $shareBps) / 100_000_000;
        if ($rate <= 0) {
            return null;
        }

        return [
            'offer_kind' => 'cashback',
            'label' => $this->formatCashbackLabel($rate, $program->category_restricted),
            'consumer_cashback_rate' => $rate,
            'category_restricted' => $program->category_restricted,
            'cta_hint' => 'shop_and_earn',
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $offers
     * @return list<array<string, mixed>>
     */
    private function webshopBenefits(?array $cashback, Collection $offers): array
    {
        $benefits = [];
        if ($cashback !== null) {
            $benefits[] = $cashback + ['source' => 'affiliate'];
        }

        foreach ($offers as $offer) {
            if (($offer['availability'] ?? '') === 'expired') {
                continue;
            }
            $benefits[] = [
                'offer_kind' => $offer['offer_kind'],
                'label' => $offer['title'],
                'benefit_label' => $this->offerBenefitLabel($offer),
                'slug' => $offer['slug'],
                'cta_hint' => $offer['cta_hint'],
                'source' => 'offer',
            ];
        }

        return $benefits;
    }

    /** @param  Collection<int, array<string, mixed>>  $offers */
    private function sortBenefitOffers(Collection $offers): Collection
    {
        return $offers->sortBy(fn (array $o) => [$this->offerKindPriority($o['offer_kind'] ?? ''), $o['title'] ?? ''])->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $active
     * @param  array<string, mixed>|null  $cashback
     * @return array<string, mixed>|null
     */
    private function pickFeaturedOffer(Collection $active, ?array $cashback): ?array
    {
        $preferred = $this->sortBenefitOffers($active)->firstWhere('availability', 'available')
            ?? $this->sortBenefitOffers($active)->first();
        if ($preferred !== null && in_array($preferred['offer_kind'] ?? '', ['cashback', 'discount_code', 'combined'], true)) {
            return $preferred;
        }
        if ($cashback !== null) {
            return [
                'offer_kind' => 'cashback',
                'title' => $cashback['label'],
                'summary' => $cashback['label'],
                'cta_hint' => 'shop_and_earn',
                'availability' => 'available',
            ];
        }

        return $preferred;
    }

    /** @param  array<string, mixed>|null  $cashback */
    private function offerKind(Offer $offer, OfferVersion $v, ?array $cashback): string
    {
        return match ($offer->acquisition) {
            OfferAcquisition::WalletPurchase => 'voucher_purchase',
            OfferAcquisition::PromotionalCode => ($cashback !== null && $v->combinable) ? 'combined' : 'discount_code',
            OfferAcquisition::MerchantReward => 'discount_code',
        };
    }

    private function ctaHint(string $offerKind): string
    {
        return match ($offerKind) {
            'combined' => 'claim_both',
            'discount_code' => 'view_code_and_shop',
            'voucher_purchase' => 'view_code_and_shop',
            default => 'shop_and_earn',
        };
    }

    private function offerKindPriority(string $offerKind): int
    {
        return match ($offerKind) {
            'cashback', 'combined' => 0,
            'discount_code' => 1,
            'voucher_purchase' => 3,
            default => 2,
        };
    }

    /** @param  array<string, mixed>  $offer */
    private function offerBenefitLabel(array $offer): ?string
    {
        $type = $offer['discount_type'] ?? null;
        $value = $offer['discount_value'] ?? null;
        if ($type === 'percentage' && is_int($value)) {
            $pct = rtrim(rtrim(number_format($value / 100, 2, '.', ''), '0'), '.');

            return $pct.'% korting';
        }
        if ($type === 'fixed' && is_int($value)) {
            return '€'.number_format($value / 100, $value % 100 === 0 ? 0 : 2, ',', '').' korting';
        }

        return null;
    }

    private function formatCashbackLabel(float $rate, bool $categoryRestricted): string
    {
        $pct = rtrim(rtrim(number_format($rate * 100, 2, '.', ''), '0'), '.');
        $prefix = $categoryRestricted ? 'Tot ' : '';

        return $prefix.$pct.'% cashback';
    }

    /**
     * Affiliate cashback as a first-class public offer card (not a prepaid voucher).
     *
     * @param  array<string, mixed>  $cashback
     * @return array<string, mixed>
     */
    private function syntheticCashbackOffer(array $cashback, WebshopPublicProfile $profile): array
    {
        $label = (string) ($cashback['label'] ?? 'Cashback');

        return [
            'slug' => null,
            'title' => $label,
            'summary' => 'Shop bij '.$profile->display_name.' en verdien tegoed na een geldige aankoop.',
            'offer_kind' => 'cashback',
            'cta_hint' => 'shop_and_earn',
            'how_to_obtain' => 'earn_cashback',
            'discount_type' => null,
            'discount_value' => null,
            'currency' => 'EUR',
            'min_order_minor' => null,
            'price_minor' => null,
            'ends_at' => null,
            'availability' => 'available',
            'cashback_label' => $label,
            'benefit_label' => $label,
            'combinable' => false,
            'source' => 'affiliate',
            'consumer_cashback_rate' => $cashback['consumer_cashback_rate'] ?? null,
            'webshop' => ['slug' => $profile->slug, 'name' => $profile->display_name],
        ];
    }
}
