<?php

namespace App\Services\Discovery;

use App\Enums\OfferStatus;
use App\Enums\VoucherCodeStatus;
use App\Enums\WebshopStatus;
use App\Models\Offer;
use App\Models\WebshopPublicProfile;
use Illuminate\Support\Facades\DB;

/**
 * Keyboard-friendly public autocomplete: webshops, categories, offers, reusable codes.
 */
class PublicSearch
{
    /**
     * @return array{q: string, groups: array{webshops: list<array<string, mixed>>, categories: list<array<string, mixed>>, offers: list<array<string, mixed>>, discount_codes: list<array<string, mixed>>}}
     */
    public function suggest(string $q, string $locale = 'nl', int $limit = 8): array
    {
        $q = trim($q);
        $limit = max(1, min(20, $limit));
        $empty = ['webshops' => [], 'categories' => [], 'offers' => [], 'discount_codes' => []];
        if (mb_strlen($q) < 1) {
            return ['q' => $q, 'groups' => $empty];
        }

        $like = '%'.addcslashes($q, '%_\\').'%';
        $per = max(1, (int) ceil($limit / 2));

        $webshops = WebshopPublicProfile::query()
            ->where('published', true)
            ->whereIn('webshop_id', DB::table('webshops')->select('id')->where('status', WebshopStatus::Approved->value))
            ->where(fn ($w) => $w->where('display_name', 'like', $like)->orWhere('short_description', 'like', $like)->orWhere('slug', 'like', $like))
            ->orderBy('display_name')
            ->limit($per)
            ->get(['slug', 'display_name', 'short_description'])
            ->map(fn (WebshopPublicProfile $p) => [
                'type' => 'webshop',
                'label' => $p->display_name,
                'description' => $p->short_description,
                'href' => '/webshops/'.$p->slug,
                'slug' => $p->slug,
            ])->all();

        $categories = [];
        $needle = mb_strtolower($q);
        foreach (config()->array('shopbonus.discovery.categories') as $key => $labels) {
            $nl = (string) ($labels['nl'] ?? '');
            $en = (string) ($labels['en'] ?? '');
            $label = $locale === 'en' ? ($en !== '' ? $en : $nl) : ($nl !== '' ? $nl : $en);
            if (str_contains(mb_strtolower($key), $needle) || str_contains(mb_strtolower($nl), $needle) || str_contains(mb_strtolower($en), $needle)) {
                $categories[] = [
                    'type' => 'category',
                    'label' => $label,
                    'key' => $key,
                    'href' => '/webshops?category='.$key,
                ];
            }
            if (count($categories) >= $per) {
                break;
            }
        }

        $offers = Offer::query()
            ->where('status', OfferStatus::Active)
            ->whereNotNull('slug')
            ->whereIn('webshop_id', WebshopPublicProfile::query()->where('published', true)->select('webshop_id'))
            ->whereHas('currentVersion', function ($v) use ($like) {
                $v->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
                    ->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('public_description', 'like', $like));
            })
            ->with(['currentVersion', 'webshop.publicProfile'])
            ->orderByDesc('activated_at')
            ->limit($per)
            ->get()
            ->map(function (Offer $o) {
                $profile = $o->webshop?->publicProfile;
                if ($profile === null || ! $profile->published) {
                    return null;
                }

                return [
                    'type' => 'offer',
                    'label' => $o->currentVersion->name ?? $o->slug,
                    'description' => $o->currentVersion?->summary(),
                    'href' => '/webshops/'.$profile->slug.'/offers/'.$o->slug,
                    'slug' => $o->slug,
                    'webshop_slug' => $profile->slug,
                ];
            })->filter()->values()->all();

        $discountCodes = Offer::query()
            ->where('status', OfferStatus::Active)
            ->whereNotNull('slug')
            ->whereIn('webshop_id', WebshopPublicProfile::query()->where('published', true)->select('webshop_id'))
            ->whereHas('currentVersion', fn ($v) => $v->where('code_mode', 'reusable')
                ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
                ->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('public_description', 'like', $like)))
            ->whereHas('codes', fn ($c) => $c->where('reusable', true)->whereIn('status', [VoucherCodeStatus::Available->value, VoucherCodeStatus::Issued->value]))
            ->with(['currentVersion', 'webshop.publicProfile'])
            ->orderByDesc('activated_at')
            ->limit($per)
            ->get()
            ->map(function (Offer $o) {
                $profile = $o->webshop?->publicProfile;
                if ($profile === null) {
                    return null;
                }

                return [
                    'type' => 'discount_code',
                    'label' => $o->currentVersion->name ?? $o->slug,
                    'href' => '/webshops/'.$profile->slug.'?offer='.$o->slug,
                    'slug' => $o->slug,
                    'webshop_slug' => $profile->slug,
                ];
            })->filter()->values()->all();

        return [
            'q' => $q,
            'groups' => [
                'webshops' => array_slice($webshops, 0, $limit),
                'categories' => array_slice($categories, 0, $limit),
                'offers' => array_slice($offers, 0, $limit),
                'discount_codes' => array_slice($discountCodes, 0, $limit),
            ],
        ];
    }
}
