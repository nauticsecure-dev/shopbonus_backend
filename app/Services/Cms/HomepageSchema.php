<?php

namespace App\Services\Cms;

use App\Models\CmsAsset;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Structured homepage / landing section schema (consumer homepage CMS).
 * Plain strings only — no HTML; hrefs are relative or allowlisted https.
 */
final class HomepageSchema
{
    public const SECTION_KEYS = [
        'hero', 'search', 'popular_webshops', 'how_it_works', 'discount_codes', 'wallet_preview',
    ];

    private const BANNED = '/\b(uitbetalen|opnemen|withdraw|cash\s*out)\b/iu';

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    public function validate(array $content): array
    {
        $allowed = [
            'title', 'seo_title', 'seo_description', 'seo_image_asset_id', 'body',
            'section_order', 'section_visibility', 'nav', 'hero', 'search',
            'popular_webshops', 'how_it_works', 'discount_codes', 'wallet_preview',
            'footer', 'schedule',
        ];
        if (array_diff(array_keys($content), $allowed) !== []) {
            throw ValidationException::withMessages(['content' => 'Unknown content fields.']);
        }

        $data = Validator::make($content, [
            'title' => ['required', 'string', 'max:120', 'not_regex:/[<>]/'],
            'seo_title' => ['sometimes', 'nullable', 'string', 'max:70', 'not_regex:/[<>]/'],
            'seo_description' => ['sometimes', 'nullable', 'string', 'max:160', 'not_regex:/[<>]/'],
            'seo_image_asset_id' => ['sometimes', 'nullable', 'string', 'max:40'],
            'body' => ['sometimes', 'nullable', 'array'],
            'section_order' => ['sometimes', 'array', 'max:20'],
            'section_order.*' => ['string', Rule::in(self::SECTION_KEYS)],
            'section_visibility' => ['sometimes', 'array'],
            'nav' => ['sometimes', 'array'],
            'hero' => ['sometimes', 'array'],
            'search' => ['sometimes', 'array'],
            'popular_webshops' => ['sometimes', 'array'],
            'how_it_works' => ['sometimes', 'array'],
            'discount_codes' => ['sometimes', 'array'],
            'wallet_preview' => ['sometimes', 'array'],
            'footer' => ['sometimes', 'array'],
            'schedule' => ['sometimes', 'nullable', 'array'],
            'schedule.publish_at' => ['sometimes', 'nullable', 'date'],
            'schedule.unpublish_at' => ['sometimes', 'nullable', 'date', 'after:schedule.publish_at'],
        ])->validate();

        $this->rejectBanned($data['title'] ?? '', 'content.title');
        $this->rejectBanned($data['seo_title'] ?? '', 'content.seo_title');
        $this->rejectBanned($data['seo_description'] ?? '', 'content.seo_description');

        if (! empty($data['seo_image_asset_id'])) {
            $this->assertAsset($data['seo_image_asset_id'], 'content.seo_image_asset_id');
        }

        if (isset($data['section_visibility'])) {
            $vis = [];
            foreach ($data['section_visibility'] as $key => $on) {
                if (! in_array($key, self::SECTION_KEYS, true) || ! is_bool($on)) {
                    throw ValidationException::withMessages(['content.section_visibility' => 'Invalid section visibility.']);
                }
                $vis[$key] = $on;
            }
            $data['section_visibility'] = $vis;
        }

        if (isset($data['nav'])) {
            $data['nav'] = $this->nav($data['nav']);
        }
        if (isset($data['hero'])) {
            $data['hero'] = $this->hero($data['hero']);
        }
        if (isset($data['search'])) {
            $data['search'] = $this->search($data['search']);
        }
        if (isset($data['popular_webshops'])) {
            $data['popular_webshops'] = $this->popularWebshops($data['popular_webshops']);
        }
        if (isset($data['how_it_works'])) {
            $data['how_it_works'] = $this->howItWorks($data['how_it_works']);
        }
        if (isset($data['discount_codes'])) {
            $data['discount_codes'] = $this->discountCodes($data['discount_codes']);
        }
        if (isset($data['wallet_preview'])) {
            $data['wallet_preview'] = $this->walletPreview($data['wallet_preview']);
        }
        if (isset($data['footer'])) {
            $data['footer'] = $this->footer($data['footer']);
        }
        if (array_key_exists('schedule', $data) && $data['schedule'] !== null) {
            $data['schedule'] = array_intersect_key($data['schedule'], array_flip(['publish_at', 'unpublish_at']));
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public function defaults(string $locale): array
    {
        $nl = $locale === 'nl';

        return [
            'title' => 'ShopBonus',
            'seo_title' => $nl ? 'ShopBonus — spaar cashback voor vouchers' : 'ShopBonus — save cashback for vouchers',
            'seo_description' => $nl
                ? 'Ontdek korting, cashback en vouchers bij jouw favoriete webshops.'
                : 'Discover discounts, cashback and vouchers at your favourite webshops.',
            'seo_image_asset_id' => null,
            'body' => ['type' => 'doc', 'content' => []],
            'section_order' => self::SECTION_KEYS,
            'section_visibility' => array_fill_keys(self::SECTION_KEYS, true),
            'nav' => [
                'links' => [
                    ['label' => 'Webshops', 'href' => '/webshops'],
                    ['label' => 'Cashback', 'href' => '/webshops?has_offers=1'],
                    ['label' => $nl ? 'Kortingscodes' : 'Discount codes', 'href' => '/kortingscodes'],
                    ['label' => $nl ? 'Hoe werkt het?' : 'How it works', 'href' => '/hoe-werkt-het'],
                ],
                'logo_alt' => 'ShopBonus',
            ],
            'hero' => [
                'heading' => $nl
                    ? "Shop zoals je altijd doet.\nSpaar voor wat je écht wilt."
                    : "Shop like you always do.\nSave for what you really want.",
                'subtitle' => $nl
                    ? 'Ontdek korting, cashback en vouchers bij jouw favoriete webshops.'
                    : 'Discover discounts, cashback and vouchers at your favourite webshops.',
                'primary_cta' => ['label' => $nl ? 'Ontdek aanbiedingen' : 'Discover offers', 'href' => '/webshops'],
                'secondary_cta' => ['label' => $nl ? 'Hoe werkt het?' : 'How it works', 'href' => '/hoe-werkt-het'],
                'trust' => $nl
                    ? ['Gratis aanmelden', 'Kortingscodes en cashback', 'Sparen voor vouchers']
                    : ['Free to join', 'Discount codes and cashback', 'Save for vouchers'],
                'artwork_note' => $nl
                    ? 'Spaar voor cadeaubonnen van jouw favoriete winkels'
                    : 'Save for gift cards from your favourite stores',
                'promo_balance_example' => '€ 42,50',
                'promo_voucher_example' => '€ 25',
                'background_asset_id' => null,
            ],
            'search' => ['placeholder' => $nl ? 'Zoek een webshop of aanbieding...' : 'Search a webshop or offer...'],
            'popular_webshops' => [
                'heading' => $nl ? 'Populaire webshops' : 'Popular webshops',
                'cta_label' => $nl ? 'Bekijk alle webshops →' : 'View all webshops →',
                'cta_href' => '/webshops',
                'mode' => 'auto',
                'featured_slugs' => [],
                'limit' => 4,
            ],
            'how_it_works' => [
                'heading' => $nl ? 'Zo werkt ShopBonus' : 'How ShopBonus works',
                'subtitle' => $nl ? 'In 3 simpele stappen naar meer voordeel.' : 'Three simple steps to more benefits.',
                'steps' => $nl ? [
                    ['title' => 'Ontdek voordeel', 'description' => 'Zoek je favoriete webshop en bekijk actuele kortingscodes en cashbackaanbiedingen.'],
                    ['title' => 'Shop via ShopBonus', 'description' => 'Klik door naar de webshop en bestel zoals je gewend bent. Gebruik eventueel een geldige kortingscode.'],
                    ['title' => 'Spaar voor vouchers', 'description' => 'Ontvang bij deelnemende, geldige aankopen cashback in je ShopBonus-wallet. Wissel je saldo in voor een voucher.'],
                ] : [
                    ['title' => 'Discover benefits', 'description' => 'Find your favourite webshop and browse current discount codes and cashback offers.'],
                    ['title' => 'Shop via ShopBonus', 'description' => 'Click through to the webshop and order as usual. Use a valid discount code when available.'],
                    ['title' => 'Save for vouchers', 'description' => 'Earn cashback in your ShopBonus wallet on eligible purchases. Redeem your balance for a voucher.'],
                ],
            ],
            'discount_codes' => [
                'heading' => $nl ? 'Actuele kortingscodes' : 'Current discount codes',
                'cta_label' => $nl ? 'Bekijk alle kortingscodes →' : 'View all discount codes →',
                'cta_href' => '/kortingscodes',
                'limit' => 2,
            ],
            'wallet_preview' => [
                'heading' => $nl ? 'Jouw ShopBonus-wallet' : 'Your ShopBonus wallet',
                'guest_text' => $nl
                    ? 'Maak een gratis account en spaar cashback voor vouchers bij favoriete webshops.'
                    : 'Create a free account and save cashback for vouchers at favourite webshops.',
                'guest_cta' => ['label' => $nl ? 'Account aanmaken' : 'Create account', 'href' => '/account'],
                'voucher_cta' => ['label' => $nl ? 'Voucher kiezen' : 'Choose a voucher', 'href' => '/mijn-voordelen'],
                'transactions_cta' => ['label' => $nl ? 'Bekijk transacties' : 'View transactions', 'href' => '/mijn-voordelen'],
            ],
            'footer' => [
                'blurb' => $nl
                    ? 'ShopBonus helpt je sparen voor vouchers bij deelnemende webshops.'
                    : 'ShopBonus helps you save for vouchers at participating webshops.',
            ],
        ];
    }

    /** @param  array<string, mixed>  $nav
     * @return array<string, mixed>
     */
    private function nav(array $nav): array
    {
        $data = Validator::make($nav, [
            'logo_alt' => ['sometimes', 'string', 'max:80', 'not_regex:/[<>]/'],
            'links' => ['sometimes', 'array', 'max:12'],
            'links.*.label' => ['required', 'string', 'max:40', 'not_regex:/[<>]/'],
            'links.*.href' => ['required', 'string', 'max:500'],
        ])->validate();
        foreach ($data['links'] ?? [] as $i => $link) {
            $this->assertHref($link['href'], "content.nav.links.{$i}.href");
            $this->rejectBanned($link['label'], "content.nav.links.{$i}.label");
        }
        if (isset($data['logo_alt'])) {
            $this->rejectBanned($data['logo_alt'], 'content.nav.logo_alt');
        }

        return $data;
    }

    /** @param  array<string, mixed>  $hero
     * @return array<string, mixed>
     */
    private function hero(array $hero): array
    {
        $data = Validator::make($hero, [
            'heading' => ['required', 'string', 'max:200', 'not_regex:/[<>]/'],
            'subtitle' => ['sometimes', 'nullable', 'string', 'max:300', 'not_regex:/[<>]/'],
            'primary_cta' => ['sometimes', 'array'],
            'primary_cta.label' => ['required_with:primary_cta', 'string', 'max:40', 'not_regex:/[<>]/'],
            'primary_cta.href' => ['required_with:primary_cta', 'string', 'max:500'],
            'secondary_cta' => ['sometimes', 'array'],
            'secondary_cta.label' => ['required_with:secondary_cta', 'string', 'max:40', 'not_regex:/[<>]/'],
            'secondary_cta.href' => ['required_with:secondary_cta', 'string', 'max:500'],
            'trust' => ['sometimes', 'array', 'max:6'],
            'trust.*' => ['string', 'max:80', 'not_regex:/[<>]/'],
            'artwork_note' => ['sometimes', 'nullable', 'string', 'max:160', 'not_regex:/[<>]/'],
            'promo_balance_example' => ['sometimes', 'nullable', 'string', 'max:40', 'not_regex:/[<>]/'],
            'promo_voucher_example' => ['sometimes', 'nullable', 'string', 'max:40', 'not_regex:/[<>]/'],
            'background_asset_id' => ['sometimes', 'nullable', 'string', 'max:40'],
        ])->validate();

        foreach (['heading', 'subtitle', 'artwork_note', 'promo_balance_example', 'promo_voucher_example'] as $f) {
            if (isset($data[$f])) {
                $this->rejectBanned((string) $data[$f], "content.hero.{$f}");
            }
        }
        foreach ($data['trust'] ?? [] as $i => $t) {
            $this->rejectBanned($t, "content.hero.trust.{$i}");
        }
        foreach (['primary_cta', 'secondary_cta'] as $cta) {
            if (isset($data[$cta])) {
                $this->assertHref($data[$cta]['href'], "content.hero.{$cta}.href");
                $this->rejectBanned($data[$cta]['label'], "content.hero.{$cta}.label");
            }
        }
        if (! empty($data['background_asset_id'])) {
            $this->assertAsset($data['background_asset_id'], 'content.hero.background_asset_id');
        }

        return $data;
    }

    /** @param  array<string, mixed>  $search
     * @return array<string, mixed>
     */
    private function search(array $search): array
    {
        $data = Validator::make($search, [
            'placeholder' => ['required', 'string', 'max:120', 'not_regex:/[<>]/'],
        ])->validate();
        $this->rejectBanned($data['placeholder'], 'content.search.placeholder');

        return $data;
    }

    /** @param  array<string, mixed>  $section
     * @return array<string, mixed>
     */
    private function popularWebshops(array $section): array
    {
        $data = Validator::make($section, [
            'heading' => ['required', 'string', 'max:120', 'not_regex:/[<>]/'],
            'cta_label' => ['sometimes', 'nullable', 'string', 'max:60', 'not_regex:/[<>]/'],
            'cta_href' => ['sometimes', 'nullable', 'string', 'max:500'],
            'mode' => ['sometimes', Rule::in(['auto', 'featured'])],
            'featured_slugs' => ['sometimes', 'array', 'max:20'],
            'featured_slugs.*' => ['string', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:24'],
        ])->validate();
        $this->rejectBanned($data['heading'], 'content.popular_webshops.heading');
        if (! empty($data['cta_label'])) {
            $this->rejectBanned($data['cta_label'], 'content.popular_webshops.cta_label');
        }
        if (! empty($data['cta_href'])) {
            $this->assertHref($data['cta_href'], 'content.popular_webshops.cta_href');
        }

        return $data;
    }

    /** @param  array<string, mixed>  $section
     * @return array<string, mixed>
     */
    private function howItWorks(array $section): array
    {
        $data = Validator::make($section, [
            'heading' => ['required', 'string', 'max:120', 'not_regex:/[<>]/'],
            'subtitle' => ['sometimes', 'nullable', 'string', 'max:200', 'not_regex:/[<>]/'],
            'steps' => ['required', 'array', 'min:1', 'max:6'],
            'steps.*.title' => ['required', 'string', 'max:80', 'not_regex:/[<>]/'],
            'steps.*.description' => ['required', 'string', 'max:400', 'not_regex:/[<>]/'],
        ])->validate();
        $this->rejectBanned($data['heading'], 'content.how_it_works.heading');
        if (isset($data['subtitle'])) {
            $this->rejectBanned($data['subtitle'], 'content.how_it_works.subtitle');
        }
        foreach ($data['steps'] as $i => $step) {
            $this->rejectBanned($step['title'], "content.how_it_works.steps.{$i}.title");
            $this->rejectBanned($step['description'], "content.how_it_works.steps.{$i}.description");
        }

        return $data;
    }

    /** @param  array<string, mixed>  $section
     * @return array<string, mixed>
     */
    private function discountCodes(array $section): array
    {
        $data = Validator::make($section, [
            'heading' => ['required', 'string', 'max:120', 'not_regex:/[<>]/'],
            'cta_label' => ['sometimes', 'nullable', 'string', 'max:60', 'not_regex:/[<>]/'],
            'cta_href' => ['sometimes', 'nullable', 'string', 'max:500'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:20'],
        ])->validate();
        $this->rejectBanned($data['heading'], 'content.discount_codes.heading');
        if (! empty($data['cta_label'])) {
            $this->rejectBanned($data['cta_label'], 'content.discount_codes.cta_label');
        }
        if (! empty($data['cta_href'])) {
            $this->assertHref($data['cta_href'], 'content.discount_codes.cta_href');
        }

        return $data;
    }

    /** @param  array<string, mixed>  $section
     * @return array<string, mixed>
     */
    private function walletPreview(array $section): array
    {
        // Wallet balances / cashback % are never CMS fields — only copy + CTAs.
        if (array_intersect(array_keys($section), ['balance', 'cashback_rate', 'cashback_percent', 'available_minor', 'wallet_balance']) !== []) {
            throw ValidationException::withMessages(['content.wallet_preview' => 'Live wallet or cashback figures cannot be edited in CMS.']);
        }
        $data = Validator::make($section, [
            'heading' => ['required', 'string', 'max:120', 'not_regex:/[<>]/'],
            'guest_text' => ['sometimes', 'nullable', 'string', 'max:300', 'not_regex:/[<>]/'],
            'guest_cta' => ['sometimes', 'array'],
            'guest_cta.label' => ['required_with:guest_cta', 'string', 'max:40', 'not_regex:/[<>]/'],
            'guest_cta.href' => ['required_with:guest_cta', 'string', 'max:500'],
            'voucher_cta' => ['sometimes', 'array'],
            'voucher_cta.label' => ['required_with:voucher_cta', 'string', 'max:40', 'not_regex:/[<>]/'],
            'voucher_cta.href' => ['required_with:voucher_cta', 'string', 'max:500'],
            'transactions_cta' => ['sometimes', 'array'],
            'transactions_cta.label' => ['required_with:transactions_cta', 'string', 'max:40', 'not_regex:/[<>]/'],
            'transactions_cta.href' => ['required_with:transactions_cta', 'string', 'max:500'],
        ])->validate();
        $this->rejectBanned($data['heading'], 'content.wallet_preview.heading');
        if (isset($data['guest_text'])) {
            $this->rejectBanned($data['guest_text'], 'content.wallet_preview.guest_text');
        }
        foreach (['guest_cta', 'voucher_cta', 'transactions_cta'] as $cta) {
            if (isset($data[$cta])) {
                $this->assertHref($data[$cta]['href'], "content.wallet_preview.{$cta}.href");
                $this->rejectBanned($data[$cta]['label'], "content.wallet_preview.{$cta}.label");
            }
        }

        return $data;
    }

    /** @param  array<string, mixed>  $footer
     * @return array<string, mixed>
     */
    private function footer(array $footer): array
    {
        $data = Validator::make($footer, [
            'blurb' => ['sometimes', 'nullable', 'string', 'max:300', 'not_regex:/[<>]/'],
        ])->validate();
        if (isset($data['blurb'])) {
            $this->rejectBanned($data['blurb'], 'content.footer.blurb');
        }

        return $data;
    }

    public static function safeHref(mixed $href): bool
    {
        if (! is_string($href) || strlen($href) > 500 || preg_match('/[\s<>"\']/', $href) === 1) {
            return false;
        }
        if (str_starts_with($href, '/') && ! str_starts_with($href, '//')) {
            return ! preg_match('#^(javascript|data|vbscript):#i', $href);
        }
        if (! str_starts_with($href, 'https://') || filter_var($href, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $host = strtolower((string) parse_url($href, PHP_URL_HOST));
        $allowed = self::allowedHosts();

        return $host !== '' && in_array($host, $allowed, true);
    }

    /** @return list<string> */
    public static function allowedHosts(): array
    {
        $hosts = config()->array('shopbonus.cms.href_hosts');
        $frontend = parse_url((string) config('shopbonus.identity.frontend_url'), PHP_URL_HOST);
        if (is_string($frontend) && $frontend !== '') {
            $hosts[] = strtolower($frontend);
        }
        $app = parse_url((string) config('app.url'), PHP_URL_HOST);
        if (is_string($app) && $app !== '') {
            $hosts[] = strtolower($app);
        }

        return array_values(array_unique(array_map('strtolower', $hosts)));
    }

    private function assertHref(string $href, string $field): void
    {
        if (! self::safeHref($href)) {
            throw ValidationException::withMessages([$field => 'Links must be a relative path or https URL on an allowlisted host.']);
        }
    }

    private function assertAsset(string $id, string $field): void
    {
        if (! CmsAsset::where('public_id', $id)->exists()) {
            throw ValidationException::withMessages([$field => 'Unknown CMS asset.']);
        }
    }

    private function rejectBanned(string $text, string $field): void
    {
        if ($text !== '' && preg_match(self::BANNED, $text) === 1) {
            throw ValidationException::withMessages([$field => 'This wording is not allowed in CMS content.']);
        }
    }
}
