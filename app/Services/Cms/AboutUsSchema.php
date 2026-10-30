<?php

namespace App\Services\Cms;

use App\Models\CmsAsset;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Structured CMS schema for the About Us page (key=over-ons, type=landing).
 * Plain strings only — no HTML; hrefs relative, page anchors, or allowlisted https.
 * Commission split percentages are never stored as authoritative CMS fields.
 * Must never invent KvK, address, founders, team profiles, or purchase-amount cashback claims.
 */
final class AboutUsSchema
{
    public const SECTION_KEYS = [
        'hero', 'story', 'revenue_model', 'values', 'team', 'contact', 'bottom_cta',
    ];

    private const BANNED = '/\b(uitbetalen|opnemen|withdraw|cash\s*out)\b/iu';

    /** Reject wording that claims cashback on the full purchase/order amount. */
    private const BANNED_PURCHASE_CASHBACK = '/\b\d+\s*%\s*(cashback\s+)?(op|van)\s+(de\s+)?(aankoop|purchase|order)\b/iu';

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    public function validate(array $content): array
    {
        $allowed = [
            'title', 'seo_title', 'seo_description', 'seo_image_asset_id', 'body',
            'section_order', 'section_visibility', 'nav', 'hero', 'story', 'revenue_model',
            'values', 'team', 'contact', 'bottom_cta', 'footer', 'schedule',
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
            'story' => ['sometimes', 'array'],
            'revenue_model' => ['sometimes', 'array'],
            'values' => ['sometimes', 'array'],
            'team' => ['sometimes', 'array'],
            'contact' => ['sometimes', 'array'],
            'bottom_cta' => ['sometimes', 'array'],
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
        if (isset($data['story'])) {
            $data['story'] = $this->story($data['story']);
        }
        if (isset($data['revenue_model'])) {
            $data['revenue_model'] = $this->revenueModel($data['revenue_model']);
        }
        if (isset($data['values'])) {
            $data['values'] = $this->values($data['values']);
        }
        if (isset($data['team'])) {
            $data['team'] = $this->team($data['team']);
        }
        if (isset($data['contact'])) {
            $data['contact'] = $this->contact($data['contact']);
        }
        if (isset($data['bottom_cta'])) {
            $data['bottom_cta'] = $this->bottomCta($data['bottom_cta']);
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
            'title' => $nl ? 'Over ShopBonus' : 'About ShopBonus',
            'seo_title' => $nl
                ? 'Over ShopBonus — cashback, vouchers en eerlijk voordeel'
                : 'About ShopBonus — cashback, vouchers and fair value',
            'seo_description' => $nl
                ? 'Leer hoe ShopBonus consumenten en webshops verbindt met aantrekkelijke aanbiedingen, cashback en vouchers.'
                : 'Learn how ShopBonus connects consumers and webshops with attractive offers, cashback and vouchers.',
            'seo_image_asset_id' => null,
            'body' => ['type' => 'doc', 'content' => []],
            'section_order' => self::SECTION_KEYS,
            'section_visibility' => array_fill_keys(self::SECTION_KEYS, true),
            'nav' => [
                'links' => [
                    ['label' => 'Webshops', 'href' => '/webshops'],
                    ['label' => $nl ? 'Cashback' : 'Cashback', 'href' => '/webshops?has_offers=1'],
                    ['label' => $nl ? 'Kortingscodes' : 'Discount codes', 'href' => '/kortingscodes'],
                    ['label' => $nl ? 'Over ons' : 'About us', 'href' => '/over-ons'],
                    ['label' => $nl ? 'Voor webshops' : 'For webshops', 'href' => '/voor-webshops'],
                ],
                'login_label' => $nl ? 'Inloggen' : 'Log in',
                'login_href' => '/account?mode=login',
                'cta_label' => $nl ? 'Account aanmaken' : 'Create account',
                'cta_href' => '/account?mode=register',
                'logo_alt' => 'ShopBonus',
            ],
            'hero' => [
                'eyebrow' => $nl ? 'OVER SHOPBONUS' : 'ABOUT SHOPBONUS',
                'heading' => $nl ? 'Samen maken we online shoppen' : 'Together we make online shopping',
                'heading_accent' => $nl ? 'voordeliger.' : 'more rewarding.',
                'subtitle' => $nl
                    ? 'Wij verbinden consumenten en webshops met aantrekkelijke aanbiedingen, cashback en vouchers. Eenvoudig, transparant en voor beide kanten waardevol.'
                    : 'We connect consumers and webshops with attractive offers, cashback and vouchers. Simple, transparent and valuable for both sides.',
                'cta_label' => $nl ? 'Ontdek ons verhaal' : 'Discover our story',
                'cta_href' => '#verhaal',
                'float_title' => $nl ? 'Eerlijk voordeel' : 'Fair value',
                'float_text' => $nl
                    ? 'Aantrekkelijke deals en cashback, altijd transparant.'
                    : 'Attractive deals and cashback, always transparent.',
                'image_asset_id' => null,
            ],
            'story' => [
                'eyebrow' => $nl ? 'ONS VERHAAL' : 'OUR STORY',
                'heading' => $nl ? 'Een goed idee verdient' : 'A good idea deserves',
                'heading_accent' => $nl ? 'meer dan korting.' : 'more than a discount.',
                'paragraphs' => $nl ? [
                    'ShopBonus is ontstaan vanuit de overtuiging dat online shoppen eenvoudiger, aantrekkelijker en voordeliger kan.',
                    'Wij brengen consumenten en webshops dichter bij elkaar. Consumenten ontdekken aanbiedingen en sparen via cashback voor vouchers. Webshops krijgen een extra mogelijkheid om hun producten en acties onder de aandacht te brengen.',
                    'Zo bouwen we aan een platform waar beide kanten voordeel van kunnen hebben.',
                ] : [
                    'ShopBonus started from the conviction that online shopping can be simpler, more attractive and more rewarding.',
                    'We bring consumers and webshops closer together. Consumers discover offers and save via cashback for vouchers. Webshops get an extra way to highlight their products and campaigns.',
                    'That is how we build a platform both sides can benefit from.',
                ],
                'image_asset_id' => null,
            ],
            'revenue_model' => [
                'eyebrow' => $nl ? 'TRANSPARANT OVER ONS VERDIENMODEL' : 'TRANSPARENT ABOUT OUR REVENUE MODEL',
                'heading' => $nl ? 'Eerlijk verdeeld. Meer waarde voor iedereen.' : 'Fairly shared. More value for everyone.',
                'explanation' => $nl
                    ? 'Wanneer een consument via ShopBonus een geldige aankoop doet bij een deelnemende webshop, kan ShopBonus daarvoor een commissie ontvangen. Die commissie verdelen we volgens de afgesproken voorwaarden.'
                    : 'When a consumer completes a valid purchase at a participating webshop via ShopBonus, ShopBonus may receive a commission. We share that commission according to the agreed terms.',
                'consumer_label' => $nl ? 'voor de consument' : 'for the consumer',
                'consumer_description' => $nl
                    ? 'Het standaardaandeel van de toepasselijke commissie dat als cashback kan worden toegekend.'
                    : 'The default share of the applicable commission that may be awarded as cashback.',
                'platform_label' => $nl ? 'voor ShopBonus' : 'for ShopBonus',
                'platform_description' => $nl
                    ? 'Het standaardaandeel waarmee we het platform onderhouden en verder ontwikkelen.'
                    : 'The default share used to maintain and further develop the platform.',
                'footnote' => $nl
                    ? 'De standaardverdeling is 70/30. Afwijkende afspraken zijn mogelijk. De berekening is afhankelijk van de geldige commissiegrondslag en actievoorwaarden.'
                    : 'The default split is 70/30. Different agreements are possible. The calculation depends on the valid commission base and campaign terms.',
            ],
            'values' => [
                'eyebrow' => $nl ? 'WAAR WIJ IN GELOVEN' : 'WHAT WE BELIEVE IN',
                'heading' => $nl ? 'Meer dan alleen kortingen.' : 'More than just discounts.',
                'introduction' => $nl
                    ? 'Wij willen online shoppen duidelijker, aantrekkelijker en toegankelijker maken. Dat vormt de basis van ShopBonus.'
                    : 'We want to make online shopping clearer, more attractive and more accessible. That is the foundation of ShopBonus.',
                'items' => $nl ? [
                    [
                        'icon' => 'tag',
                        'title' => 'Eerlijk voordeel',
                        'description' => 'Aantrekkelijke aanbiedingen met duidelijke voorwaarden, zodat consumenten begrijpen welk voordeel ze kunnen ontvangen.',
                    ],
                    [
                        'icon' => 'shop',
                        'title' => 'Vrijheid voor webshops',
                        'description' => 'Webshops bepalen hun commerciële afspraken en welke aanbiedingen zij beschikbaar willen stellen.',
                    ],
                    [
                        'icon' => 'users',
                        'title' => 'Eenvoud voor iedereen',
                        'description' => 'Een gebruiksvriendelijk platform waar consumenten eenvoudig aanbiedingen ontdekken en sparen voor vouchers.',
                    ],
                ] : [
                    [
                        'icon' => 'tag',
                        'title' => 'Fair value',
                        'description' => 'Attractive offers with clear terms, so consumers understand the benefit they can receive.',
                    ],
                    [
                        'icon' => 'shop',
                        'title' => 'Freedom for webshops',
                        'description' => 'Webshops set their commercial terms and which offers they want to make available.',
                    ],
                    [
                        'icon' => 'users',
                        'title' => 'Simplicity for everyone',
                        'description' => 'A user-friendly platform where consumers easily discover offers and save for vouchers.',
                    ],
                ],
            ],
            'team' => [
                'eyebrow' => $nl ? 'DE MENSEN ACHTER SHOPBONUS' : 'THE PEOPLE BEHIND SHOPBONUS',
                'heading' => $nl ? 'Een betrokken team met een duidelijke missie.' : 'A dedicated team with a clear mission.',
                'body' => $nl
                    ? 'Achter ShopBonus werken mensen aan een platform dat online winkelen voor consumenten en ondernemers aantrekkelijker maakt. We combineren commercieel inzicht met slimme technologie en duidelijke afspraken.'
                    : 'Behind ShopBonus, people work on a platform that makes online shopping more attractive for consumers and entrepreneurs. We combine commercial insight with smart technology and clear agreements.',
                'quote' => $nl
                    ? 'We geloven in een online wereld waarin consumenten en webshops samen meer bereiken.'
                    : 'We believe in an online world where consumers and webshops achieve more together.',
                'image_asset_id' => null,
                'show_profiles' => false,
                'profiles' => [],
                'placeholder_label' => $nl ? "Foto oprichter\ntoevoegen" : "Add founder\nphoto",
            ],
            'contact' => [
                'heading' => $nl ? 'Contact met ShopBonus' : 'Contact ShopBonus',
                'intro' => $nl
                    ? 'Heb je een vraag over ShopBonus, cashback, vouchers of samenwerken met ons? We helpen je graag verder.'
                    : 'Do you have a question about ShopBonus, cashback, vouchers or working with us? We are happy to help.',
                'links' => [
                    ['label' => 'Contact', 'href' => '/support'],
                    ['label' => 'Privacy', 'href' => '/pagina/privacy'],
                    ['label' => $nl ? 'Voorwaarden' : 'Terms', 'href' => '/pagina/voorwaarden'],
                    ['label' => $nl ? 'Bedrijfsgegevens' : 'Company info', 'href' => '/over-ons#contact'],
                ],
                'legal_name' => '',
                'address' => '',
                'kvk' => '',
                'email' => '',
            ],
            'bottom_cta' => [
                'eyebrow' => $nl ? 'SAMEN NAAR EEN SLIMMERE TOEKOMST' : 'TOGETHER TOWARD A SMARTER FUTURE',
                'heading' => $nl ? 'Samen maken we shoppen' : 'Together we make shopping',
                'heading_accent' => $nl ? 'slimmer.' : 'smarter.',
                'description' => $nl
                    ? 'Of je nu op zoek bent naar de beste aanbiedingen of als webshop wilt groeien: we doen het samen. Ontdek wat ShopBonus voor jou kan betekenen.'
                    : 'Whether you are looking for the best deals or want to grow as a webshop: we do it together. Discover what ShopBonus can mean for you.',
                'consumer_cta' => [
                    'label' => $nl ? 'Ontdek aanbiedingen' : 'Discover offers',
                    'href' => '/webshops',
                ],
                'merchant_cta' => [
                    'label' => $nl ? 'Voor webshops' : 'For webshops',
                    'href' => '/voor-webshops',
                ],
            ],
            'footer' => [
                'blurb' => $nl
                    ? 'ShopBonus verbindt consumenten en webshops met cashback, kortingscodes en vouchers. Wallet-saldo wissel je in voor vouchers — nooit voor contant geld.'
                    : 'ShopBonus connects consumers and webshops with cashback, discount codes and vouchers. Redeem wallet balance for vouchers — never for cash.',
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
            'login_label' => ['sometimes', 'string', 'max:40', 'not_regex:/[<>]/'],
            'login_href' => ['sometimes', 'string', 'max:500'],
            'cta_label' => ['sometimes', 'string', 'max:40', 'not_regex:/[<>]/'],
            'cta_href' => ['sometimes', 'string', 'max:500'],
        ])->validate();
        foreach ($data['links'] ?? [] as $i => $link) {
            $this->assertHref($link['href'], "content.nav.links.{$i}.href");
            $this->rejectBanned($link['label'], "content.nav.links.{$i}.label");
        }
        foreach (['login_href', 'cta_href'] as $f) {
            if (! empty($data[$f])) {
                $this->assertHref($data[$f], "content.nav.{$f}");
            }
        }
        foreach (['logo_alt', 'login_label', 'cta_label'] as $f) {
            if (isset($data[$f])) {
                $this->rejectBanned($data[$f], "content.nav.{$f}");
            }
        }

        return $data;
    }

    /** @param  array<string, mixed>  $hero
     * @return array<string, mixed>
     */
    private function hero(array $hero): array
    {
        $data = Validator::make($hero, [
            'eyebrow' => ['sometimes', 'nullable', 'string', 'max:40', 'not_regex:/[<>]/'],
            'heading' => ['required', 'string', 'max:220', 'not_regex:/[<>]/'],
            'heading_accent' => ['sometimes', 'nullable', 'string', 'max:80', 'not_regex:/[<>]/'],
            'subtitle' => ['sometimes', 'nullable', 'string', 'max:400', 'not_regex:/[<>]/'],
            'cta_label' => ['sometimes', 'nullable', 'string', 'max:50', 'not_regex:/[<>]/'],
            'cta_href' => ['sometimes', 'nullable', 'string', 'max:500'],
            'float_title' => ['sometimes', 'nullable', 'string', 'max:80', 'not_regex:/[<>]/'],
            'float_text' => ['sometimes', 'nullable', 'string', 'max:200', 'not_regex:/[<>]/'],
            'image_asset_id' => ['sometimes', 'nullable', 'string', 'max:40'],
        ])->validate();

        foreach (['eyebrow', 'heading', 'heading_accent', 'subtitle', 'cta_label', 'float_title', 'float_text'] as $f) {
            if (isset($data[$f])) {
                $this->rejectBanned((string) $data[$f], "content.hero.{$f}");
            }
        }
        if (! empty($data['cta_href'])) {
            $this->assertHref($data['cta_href'], 'content.hero.cta_href');
        }
        if (! empty($data['image_asset_id'])) {
            $this->assertAsset($data['image_asset_id'], 'content.hero.image_asset_id');
        }

        return $data;
    }

    /** @param  array<string, mixed>  $section
     * @return array<string, mixed>
     */
    private function story(array $section): array
    {
        $data = Validator::make($section, [
            'eyebrow' => ['sometimes', 'nullable', 'string', 'max:40', 'not_regex:/[<>]/'],
            'heading' => ['required', 'string', 'max:160', 'not_regex:/[<>]/'],
            'heading_accent' => ['sometimes', 'nullable', 'string', 'max:80', 'not_regex:/[<>]/'],
            'paragraphs' => ['sometimes', 'array', 'max:8'],
            'paragraphs.*' => ['string', 'max:800', 'not_regex:/[<>]/'],
            'image_asset_id' => ['sometimes', 'nullable', 'string', 'max:40'],
        ])->validate();
        foreach (['eyebrow', 'heading', 'heading_accent'] as $f) {
            if (isset($data[$f])) {
                $this->rejectBanned((string) $data[$f], "content.story.{$f}");
            }
        }
        foreach ($data['paragraphs'] ?? [] as $i => $p) {
            $this->rejectBanned($p, "content.story.paragraphs.{$i}");
        }
        if (! empty($data['image_asset_id'])) {
            $this->assertAsset($data['image_asset_id'], 'content.story.image_asset_id');
        }

        return $data;
    }

    /** @param  array<string, mixed>  $section
     * @return array<string, mixed>
     */
    private function revenueModel(array $section): array
    {
        // Percentages are injected at read time from config — never store as authoritative CMS fields.
        unset($section['consumer_percent'], $section['platform_percent']);

        $data = Validator::make($section, [
            'eyebrow' => ['sometimes', 'nullable', 'string', 'max:60', 'not_regex:/[<>]/'],
            'heading' => ['required', 'string', 'max:160', 'not_regex:/[<>]/'],
            'explanation' => ['sometimes', 'nullable', 'string', 'max:800', 'not_regex:/[<>]/'],
            'consumer_label' => ['sometimes', 'nullable', 'string', 'max:80', 'not_regex:/[<>]/'],
            'consumer_description' => ['sometimes', 'nullable', 'string', 'max:400', 'not_regex:/[<>]/'],
            'platform_label' => ['sometimes', 'nullable', 'string', 'max:80', 'not_regex:/[<>]/'],
            'platform_description' => ['sometimes', 'nullable', 'string', 'max:400', 'not_regex:/[<>]/'],
            'footnote' => ['sometimes', 'nullable', 'string', 'max:500', 'not_regex:/[<>]/'],
        ])->validate();

        foreach (['eyebrow', 'heading', 'explanation', 'consumer_label', 'consumer_description', 'platform_label', 'platform_description', 'footnote'] as $f) {
            if (isset($data[$f])) {
                $this->rejectBanned((string) $data[$f], "content.revenue_model.{$f}");
            }
        }

        return $data;
    }

    /** @param  array<string, mixed>  $section
     * @return array<string, mixed>
     */
    private function values(array $section): array
    {
        $data = Validator::make($section, [
            'eyebrow' => ['sometimes', 'nullable', 'string', 'max:40', 'not_regex:/[<>]/'],
            'heading' => ['required', 'string', 'max:160', 'not_regex:/[<>]/'],
            'introduction' => ['sometimes', 'nullable', 'string', 'max:500', 'not_regex:/[<>]/'],
            'items' => ['sometimes', 'array', 'max:6'],
            'items.*.icon' => ['required', 'string', 'max:40', Rule::in(['tag', 'shop', 'users', 'shield', 'heart', 'spark'])],
            'items.*.title' => ['required', 'string', 'max:80', 'not_regex:/[<>]/'],
            'items.*.description' => ['required', 'string', 'max:400', 'not_regex:/[<>]/'],
        ])->validate();
        foreach (['eyebrow', 'heading', 'introduction'] as $f) {
            if (isset($data[$f])) {
                $this->rejectBanned((string) $data[$f], "content.values.{$f}");
            }
        }
        foreach ($data['items'] ?? [] as $i => $item) {
            $this->rejectBanned($item['title'], "content.values.items.{$i}.title");
            $this->rejectBanned($item['description'], "content.values.items.{$i}.description");
        }

        return $data;
    }

    /** @param  array<string, mixed>  $section
     * @return array<string, mixed>
     */
    private function team(array $section): array
    {
        $data = Validator::make($section, [
            'eyebrow' => ['sometimes', 'nullable', 'string', 'max:60', 'not_regex:/[<>]/'],
            'heading' => ['required', 'string', 'max:160', 'not_regex:/[<>]/'],
            'body' => ['sometimes', 'nullable', 'string', 'max:800', 'not_regex:/[<>]/'],
            'quote' => ['sometimes', 'nullable', 'string', 'max:400', 'not_regex:/[<>]/'],
            'image_asset_id' => ['sometimes', 'nullable', 'string', 'max:40'],
            'show_profiles' => ['sometimes', 'boolean'],
            'profiles' => ['sometimes', 'array', 'max:12'],
            'profiles.*.name' => ['sometimes', 'nullable', 'string', 'max:80', 'not_regex:/[<>]/'],
            'profiles.*.role' => ['sometimes', 'nullable', 'string', 'max:80', 'not_regex:/[<>]/'],
            'profiles.*.bio' => ['sometimes', 'nullable', 'string', 'max:400', 'not_regex:/[<>]/'],
            'profiles.*.photo_asset_id' => ['sometimes', 'nullable', 'string', 'max:40'],
            'profiles.*.linkedin_href' => ['sometimes', 'nullable', 'string', 'max:500'],
            'placeholder_label' => ['sometimes', 'nullable', 'string', 'max:80', 'not_regex:/[<>]/'],
        ])->validate();
        foreach (['eyebrow', 'heading', 'body', 'quote', 'placeholder_label'] as $f) {
            if (isset($data[$f])) {
                $this->rejectBanned((string) $data[$f], "content.team.{$f}");
            }
        }
        if (! empty($data['image_asset_id'])) {
            $this->assertAsset($data['image_asset_id'], 'content.team.image_asset_id');
        }
        $data['show_profiles'] = (bool) ($data['show_profiles'] ?? false);
        $profiles = [];
        foreach ($data['profiles'] ?? [] as $i => $profile) {
            foreach (['name', 'role', 'bio'] as $f) {
                if (! empty($profile[$f])) {
                    $this->rejectBanned((string) $profile[$f], "content.team.profiles.{$i}.{$f}");
                }
            }
            if (! empty($profile['photo_asset_id'])) {
                $this->assertAsset($profile['photo_asset_id'], "content.team.profiles.{$i}.photo_asset_id");
            }
            if (! empty($profile['linkedin_href'])) {
                $this->assertLinkedInHref($profile['linkedin_href'], "content.team.profiles.{$i}.linkedin_href");
            }
            $profiles[] = $profile;
        }
        $data['profiles'] = $profiles;

        return $data;
    }

    /** @param  array<string, mixed>  $section
     * @return array<string, mixed>
     */
    private function contact(array $section): array
    {
        $data = Validator::make($section, [
            'heading' => ['required', 'string', 'max:120', 'not_regex:/[<>]/'],
            'intro' => ['sometimes', 'nullable', 'string', 'max:500', 'not_regex:/[<>]/'],
            'links' => ['sometimes', 'array', 'max:12'],
            'links.*.label' => ['required', 'string', 'max:40', 'not_regex:/[<>]/'],
            'links.*.href' => ['required', 'string', 'max:500'],
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:160', 'not_regex:/[<>]/'],
            'address' => ['sometimes', 'nullable', 'string', 'max:300', 'not_regex:/[<>]/'],
            'kvk' => ['sometimes', 'nullable', 'string', 'max:40', 'not_regex:/[<>]/'],
            'email' => ['sometimes', 'nullable', 'string', 'max:254', 'not_regex:/[<>]/'],
        ])->validate();
        foreach (['heading', 'intro', 'legal_name', 'address', 'kvk', 'email'] as $f) {
            if (isset($data[$f])) {
                $this->rejectBanned((string) $data[$f], "content.contact.{$f}");
            }
        }
        if (! empty($data['email']) && filter_var($data['email'], FILTER_VALIDATE_EMAIL) === false) {
            throw ValidationException::withMessages(['content.contact.email' => 'A valid email address is required.']);
        }
        foreach ($data['links'] ?? [] as $i => $link) {
            $this->assertHref($link['href'], "content.contact.links.{$i}.href");
            $this->rejectBanned($link['label'], "content.contact.links.{$i}.label");
        }

        return $data;
    }

    /** @param  array<string, mixed>  $section
     * @return array<string, mixed>
     */
    private function bottomCta(array $section): array
    {
        $data = Validator::make($section, [
            'eyebrow' => ['sometimes', 'nullable', 'string', 'max:60', 'not_regex:/[<>]/'],
            'heading' => ['required', 'string', 'max:160', 'not_regex:/[<>]/'],
            'heading_accent' => ['sometimes', 'nullable', 'string', 'max:80', 'not_regex:/[<>]/'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500', 'not_regex:/[<>]/'],
            'consumer_cta' => ['sometimes', 'array'],
            'consumer_cta.label' => ['required_with:consumer_cta', 'string', 'max:50', 'not_regex:/[<>]/'],
            'consumer_cta.href' => ['required_with:consumer_cta', 'string', 'max:500'],
            'merchant_cta' => ['sometimes', 'array'],
            'merchant_cta.label' => ['required_with:merchant_cta', 'string', 'max:50', 'not_regex:/[<>]/'],
            'merchant_cta.href' => ['required_with:merchant_cta', 'string', 'max:500'],
        ])->validate();
        foreach (['eyebrow', 'heading', 'heading_accent', 'description'] as $f) {
            if (isset($data[$f])) {
                $this->rejectBanned((string) $data[$f], "content.bottom_cta.{$f}");
            }
        }
        foreach (['consumer_cta', 'merchant_cta'] as $cta) {
            if (isset($data[$cta])) {
                $this->assertHref($data[$cta]['href'], "content.bottom_cta.{$cta}.href");
                $this->rejectBanned($data[$cta]['label'], "content.bottom_cta.{$cta}.label");
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
            'blurb' => ['sometimes', 'nullable', 'string', 'max:400', 'not_regex:/[<>]/'],
        ])->validate();
        if (isset($data['blurb'])) {
            $this->rejectBanned($data['blurb'], 'content.footer.blurb');
        }

        return $data;
    }

    private function assertHref(string $href, string $field): void
    {
        if (preg_match('/^#[a-z0-9][a-z0-9_-]{0,60}$/i', $href) === 1) {
            return;
        }
        if (! HomepageSchema::safeHref($href)) {
            throw ValidationException::withMessages([$field => 'Links must be a relative path, page anchor, or https URL on an allowlisted host.']);
        }
    }

    private function assertLinkedInHref(string $href, string $field): void
    {
        if (preg_match('/[\s<>"\']/', $href) === 1 || strlen($href) > 500) {
            throw ValidationException::withMessages([$field => 'LinkedIn links must be https URLs on linkedin.com.']);
        }
        if (! str_starts_with($href, 'https://') || filter_var($href, FILTER_VALIDATE_URL) === false) {
            throw ValidationException::withMessages([$field => 'LinkedIn links must be https URLs on linkedin.com.']);
        }
        $host = strtolower((string) parse_url($href, PHP_URL_HOST));
        if (! in_array($host, ['linkedin.com', 'www.linkedin.com'], true)) {
            throw ValidationException::withMessages([$field => 'LinkedIn links must be https URLs on linkedin.com.']);
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
        if ($text === '') {
            return;
        }
        if (preg_match(self::BANNED, $text) === 1 || preg_match(self::BANNED_PURCHASE_CASHBACK, $text) === 1) {
            throw ValidationException::withMessages([$field => 'This wording is not allowed in CMS content.']);
        }
    }
}
