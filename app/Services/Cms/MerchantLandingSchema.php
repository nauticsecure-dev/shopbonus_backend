<?php

namespace App\Services\Cms;

use App\Models\CmsAsset;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Structured CMS schema for the webshop-owner landing page (key=voor-webshops).
 * Plain strings only — no HTML; hrefs relative or allowlisted https.
 * Must never invent guaranteed sales, free subscriptions, or unsupported integrations.
 */
final class MerchantLandingSchema
{
    public const SECTION_KEYS = [
        'hero', 'platforms', 'benefits', 'offer_preview', 'onboarding', 'bottom_cta', 'faq',
    ];

    public const PLATFORM_STATUSES = ['AVAILABLE', 'BETA', 'COMING_SOON', 'MANUAL_SETUP'];

    private const BANNED = '/\b(uitbetalen|opnemen|withdraw|cash\s*out|gegarandeerd\s+\d+%|guaranteed\s+sales)\b/iu';

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    public function validate(array $content): array
    {
        $allowed = [
            'title', 'seo_title', 'seo_description', 'seo_image_asset_id', 'body',
            'section_order', 'section_visibility', 'nav', 'hero', 'platforms',
            'benefits', 'offer_preview', 'onboarding', 'bottom_cta', 'faq', 'footer', 'schedule',
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
            'platforms' => ['sometimes', 'array'],
            'benefits' => ['sometimes', 'array'],
            'offer_preview' => ['sometimes', 'array'],
            'onboarding' => ['sometimes', 'array'],
            'bottom_cta' => ['sometimes', 'array'],
            'faq' => ['sometimes', 'array'],
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
        if (isset($data['platforms'])) {
            $data['platforms'] = $this->platforms($data['platforms']);
        }
        if (isset($data['benefits'])) {
            $data['benefits'] = $this->benefits($data['benefits']);
        }
        if (isset($data['offer_preview'])) {
            $data['offer_preview'] = $this->offerPreview($data['offer_preview']);
        }
        if (isset($data['onboarding'])) {
            $data['onboarding'] = $this->onboarding($data['onboarding']);
        }
        if (isset($data['bottom_cta'])) {
            $data['bottom_cta'] = $this->bottomCta($data['bottom_cta']);
        }
        if (isset($data['faq'])) {
            $data['faq'] = $this->faq($data['faq']);
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
        $register = '/voor-webshops/aanmelden';

        return [
            'title' => $nl ? 'ShopBonus voor webshops' : 'ShopBonus for webshops',
            'seo_title' => $nl
                ? 'ShopBonus voor webshops — meer klanten, alleen betalen voor resultaat'
                : 'ShopBonus for webshops — more customers, pay for results',
            'seo_description' => $nl
                ? 'Maak jouw webshop aantrekkelijker met cashback, kortingscodes en vouchers. Jij bepaalt de voorwaarden.'
                : 'Make your webshop more attractive with cashback, discount codes and vouchers. You set the terms.',
            'seo_image_asset_id' => null,
            'body' => ['type' => 'doc', 'content' => []],
            'section_order' => self::SECTION_KEYS,
            'section_visibility' => array_fill_keys(self::SECTION_KEYS, true),
            'nav' => [
                'links' => [
                    ['label' => $nl ? 'Voordelen' : 'Benefits', 'href' => '#voordelen'],
                    ['label' => $nl ? 'Zo werkt het' : 'How it works', 'href' => '#zo-werkt-het'],
                    ['label' => $nl ? 'Integraties' : 'Integrations', 'href' => '#integraties'],
                    ['label' => $nl ? 'Veelgestelde vragen' : 'FAQ', 'href' => '#faq'],
                ],
                'login_label' => $nl ? 'Inloggen' : 'Log in',
                'login_href' => '/account?mode=login&role=merchant',
                'cta_label' => $nl ? 'Webshop aanmelden' : 'Register webshop',
                'cta_href' => $register,
                'logo_alt' => 'ShopBonus',
            ],
            'hero' => [
                'eyebrow' => $nl ? 'VOOR WEBSHOPS' : 'FOR WEBSHOPS',
                'heading' => $nl
                    ? "Meer klanten. Meer bestellingen.\nAlleen betalen voor resultaat."
                    : "More customers. More orders.\nPay only for results.",
                'subtitle' => $nl
                    ? 'Maak jouw webshop aantrekkelijker met cashback, kortingscodes en vouchers. Jij bepaalt de voorwaarden.'
                    : 'Make your webshop more attractive with cashback, discount codes and vouchers. You set the terms.',
                'primary_cta' => ['label' => $nl ? 'Sluit je webshop aan' : 'Connect your webshop', 'href' => $register],
                'secondary_cta' => ['label' => $nl ? 'Bekijk hoe het werkt' : 'See how it works', 'href' => '#zo-werkt-het'],
                'trust' => $nl
                    ? ['Zelf commissie bepalen', 'Snel aansluiten', 'Meer zichtbaarheid']
                    : ['Set your own commission', 'Connect quickly', 'More visibility'],
                'artwork_note' => $nl
                    ? 'Promotionele illustratie — geen live resultaten'
                    : 'Promotional artwork — not live results',
                'background_asset_id' => null,
                'illustration_asset_id' => null,
            ],
            'platforms' => [
                'heading' => $nl ? 'Werkt met de populairste platforms' : 'Works with popular platforms',
                'subtitle' => $nl
                    ? 'Verbind je webshop met een ondersteunde integratie.'
                    : 'Connect your webshop with a supported integration.',
                'cta_label' => $nl ? 'Bekijk alle integraties' : 'View all integrations',
                'cta_href' => $register.'#platforms',
                'tiles' => [
                    ['platform' => 'shopify', 'name' => 'Shopify', 'status' => 'AVAILABLE', 'href' => $register.'?platform=shopify'],
                    ['platform' => 'woocommerce', 'name' => 'WooCommerce', 'status' => 'AVAILABLE', 'href' => $register.'?platform=woocommerce'],
                    ['platform' => 'wix', 'name' => 'Wix', 'status' => 'AVAILABLE', 'href' => $register.'?platform=wix'],
                    ['platform' => 'bigcommerce', 'name' => 'BigCommerce', 'status' => 'AVAILABLE', 'href' => $register.'?platform=bigcommerce'],
                    ['platform' => 'ecwid', 'name' => 'Ecwid', 'status' => 'AVAILABLE', 'href' => $register.'?platform=ecwid'],
                    ['platform' => 'lightspeed_c', 'name' => 'Lightspeed C-Series', 'status' => 'AVAILABLE', 'href' => $register.'?platform=lightspeed_c'],
                    ['platform' => 'prestashop', 'name' => 'PrestaShop', 'status' => 'MANUAL_SETUP', 'href' => $register.'?platform=prestashop'],
                    ['platform' => 'opencart', 'name' => 'OpenCart', 'status' => 'MANUAL_SETUP', 'href' => $register.'?platform=opencart'],
                    ['platform' => 'shopware6', 'name' => 'Shopware', 'status' => 'MANUAL_SETUP', 'href' => $register.'?platform=shopware6'],
                    ['platform' => 'magento', 'name' => 'Adobe Commerce / Magento', 'status' => 'MANUAL_SETUP', 'href' => $register.'?platform=magento'],
                ],
            ],
            'benefits' => [
                'eyebrow' => $nl ? 'GROEI MET SHOPBONUS' : 'GROW WITH SHOPBONUS',
                'heading' => $nl ? "Maak je webshop\nonweerstaanbaar." : "Make your webshop\nirresistible.",
                'description' => $nl
                    ? 'Bereik nieuwe klanten met aantrekkelijke aanbiedingen via ShopBonus. Jij houdt de controle over jouw acties en commerciële voorwaarden.'
                    : 'Reach new customers with attractive offers via ShopBonus. You keep control of your campaigns and commercial terms.',
                'image_asset_id' => null,
                'items' => $nl ? [
                    [
                        'icon' => 'people',
                        'title' => 'Bereik nieuwe klanten',
                        'description' => 'Maak jouw webshop zichtbaar voor consumenten die gericht zoeken naar aanbiedingen en nieuwe winkels.',
                    ],
                    [
                        'icon' => 'tag',
                        'title' => 'Kortingen én cashback',
                        'description' => 'Promoot geldige kortingscodes, cashbackacties of een combinatie daarvan, afhankelijk van jouw voorwaarden.',
                    ],
                    [
                        'icon' => 'cog',
                        'title' => 'Jij bepaalt de commissie',
                        'description' => 'Stel je commerciële afspraken in en houd controle over je marges.',
                    ],
                ] : [
                    [
                        'icon' => 'people',
                        'title' => 'Reach new customers',
                        'description' => 'Make your webshop visible to shoppers looking for deals and new stores.',
                    ],
                    [
                        'icon' => 'tag',
                        'title' => 'Discounts and cashback',
                        'description' => 'Promote valid discount codes, cashback campaigns, or a combination — based on your terms.',
                    ],
                    [
                        'icon' => 'cog',
                        'title' => 'You set the commission',
                        'description' => 'Configure your commercial agreements and keep control of your margins.',
                    ],
                ],
            ],
            'offer_preview' => [
                'eyebrow' => $nl ? 'ZO ZIEN JOUW AANBIEDINGEN ERUIT' : 'HOW YOUR OFFERS LOOK',
                'heading' => $nl ? "Jouw aanbiedingen.\nProfessioneel gepresenteerd." : "Your offers.\nProfessionally presented.",
                'description' => $nl
                    ? 'Jouw cashbackacties en kortingscodes worden op een aantrekkelijke en overzichtelijke manier getoond. Zo ontdekken consumenten eenvoudig wat jouw webshop te bieden heeft.'
                    : 'Your cashback campaigns and discount codes are shown clearly and attractively so shoppers can discover what your webshop offers.',
                'cta_label' => $nl ? 'Bekijk een voorbeeld' : 'View an example',
                'cta_href' => '/webshops',
                'preview_asset_id' => null,
                'illustrative' => true,
            ],
            'onboarding' => [
                'eyebrow' => $nl ? 'IN 3 EENVOUDIGE STAPPEN' : 'IN 3 SIMPLE STEPS',
                'heading' => $nl ? 'Binnen enkele stappen live.' : 'Live in a few steps.',
                'steps' => $nl ? [
                    [
                        'number' => '01',
                        'title' => 'Aanmelden',
                        'description' => 'Maak een account aan en registreer jouw webshop.',
                        'icon' => 'document',
                    ],
                    [
                        'number' => '02',
                        'title' => 'Koppelen',
                        'description' => 'Verbind jouw webshop via een ondersteunde integratie of beschikbare alternatieve aansluitmethode.',
                        'icon' => 'link',
                    ],
                    [
                        'number' => '03',
                        'title' => 'Start met aanbiedingen',
                        'description' => 'Stel jouw commissies en acties in. Na goedkeuring verschijnt jouw webshop op ShopBonus.',
                        'icon' => 'megaphone',
                    ],
                ] : [
                    [
                        'number' => '01',
                        'title' => 'Sign up',
                        'description' => 'Create an account and register your webshop.',
                        'icon' => 'document',
                    ],
                    [
                        'number' => '02',
                        'title' => 'Connect',
                        'description' => 'Connect your webshop via a supported integration or available alternative method.',
                        'icon' => 'link',
                    ],
                    [
                        'number' => '03',
                        'title' => 'Start with offers',
                        'description' => 'Set your commissions and campaigns. After approval your webshop appears on ShopBonus.',
                        'icon' => 'megaphone',
                    ],
                ],
            ],
            'bottom_cta' => [
                'eyebrow' => $nl ? 'START MET SHOPBONUS' : 'START WITH SHOPBONUS',
                'heading' => $nl ? 'Klaar om nieuwe klanten te bereiken?' : 'Ready to reach new customers?',
                'description' => $nl
                    ? 'Maak jouw aanbod zichtbaar voor koopjesjagers en bepaal zelf jouw commerciële voorwaarden.'
                    : 'Make your offers visible to deal hunters and set your own commercial terms.',
                'cta_label' => $nl ? 'Webshop aanmelden →' : 'Register webshop →',
                'cta_href' => $register,
            ],
            'faq' => [
                'heading' => $nl ? 'Veelgestelde vragen' : 'Frequently asked questions',
                'items' => $nl ? [
                    [
                        'question' => 'Wat kost aansluiten bij ShopBonus?',
                        'answer' => 'Aansluiten zelf is gratis. Je betaalt alleen wanneer er resultaat is via je afgesproken commissie of actievoorwaarden. Er is geen vast abonnement.',
                    ],
                    [
                        'question' => 'Hoe werkt de commissie?',
                        'answer' => 'Jij stelt je commerciële afspraak in. Bij cashback via affiliate- of commissieafspraken is de standaardverdeling 70% voor de klant en 30% voor ShopBonus, tenzij Super Admin een uitzondering goedkeurt. De commissie is niet automatisch gelijk aan de cashback die de consument ziet.',
                    ],
                    [
                        'question' => 'Kan ik mijn eigen cashbackacties instellen?',
                        'answer' => 'Ja, zodra je webshop is goedgekeurd en er een geldig cashbackprogramma of merchant-akkoord is. Cashbackacties en kortingscodes zijn aparte aanbiedingstypen.',
                    ],
                    [
                        'question' => 'Welke webshopplatformen ondersteunen jullie?',
                        'answer' => 'Onder meer Shopify, WooCommerce, Wix en andere platforms met connector of handmatige module. Status (beschikbaar, handmatige setup of binnenkort) staat per platform vermeld — een logo betekent niet automatisch een live koppeling.',
                    ],
                    [
                        'question' => 'Kan ik kortingscodes toevoegen?',
                        'answer' => 'Ja. Je kunt geldige kortingscodes aanmaken of importeren. Alleen goedgekeurde, actieve aanbiedingen verschijnen publiek.',
                    ],
                    [
                        'question' => 'Wanneer verschijnt mijn webshop op ShopBonus?',
                        'answer' => 'Na afronding van onboarding, verificatie en goedkeuring door ShopBonus. Activatie is niet instant wanneer verificatie vereist is.',
                    ],
                    [
                        'question' => 'Hoe worden bestellingen geregistreerd?',
                        'answer' => 'Via de gekoppelde integratie (webhooks of module) of een goedgekeurde alternatieve aansluitmethode. Alleen gevalideerde, in aanmerking komende bestellingen tellen mee voor cashbackberekening.',
                    ],
                    [
                        'question' => 'Kan ik mijn aanbiedingen aanpassen?',
                        'answer' => 'Ja. Je beheert commissies, cashbackacties en kortingscodes vanuit het merchant-dashboard. Publieke weergave volgt altijd de goedkeurings- en publicatieregels.',
                    ],
                ] : [
                    [
                        'question' => 'What does joining ShopBonus cost?',
                        'answer' => 'Joining itself is free. You only pay when there is a result under your agreed commission or campaign terms. There is no fixed subscription.',
                    ],
                    [
                        'question' => 'How does commission work?',
                        'answer' => 'You set your commercial agreement. For cashback via affiliate or commission agreements the default split is 70% customer / 30% ShopBonus, unless Super Admin approves an exception. Commission is not automatically equal to the cashback the consumer sees.',
                    ],
                    [
                        'question' => 'Can I set my own cashback campaigns?',
                        'answer' => 'Yes, once your webshop is approved and an eligible cashback program or merchant agreement exists. Cashback and discount codes are separate offer types.',
                    ],
                    [
                        'question' => 'Which platforms do you support?',
                        'answer' => 'Including Shopify, WooCommerce, Wix and others with a connector or manual module. Status (available, manual setup or coming soon) is shown per platform — a logo alone does not mean a live connection.',
                    ],
                    [
                        'question' => 'Can I add discount codes?',
                        'answer' => 'Yes. You can create or import valid discount codes. Only approved, active offers appear publicly.',
                    ],
                    [
                        'question' => 'When does my webshop appear on ShopBonus?',
                        'answer' => 'After onboarding, verification and ShopBonus approval. Activation is not instant when verification is required.',
                    ],
                    [
                        'question' => 'How are orders tracked?',
                        'answer' => 'Via the connected integration (webhooks or module) or an approved alternative method. Only validated, eligible orders count toward cashback calculation.',
                    ],
                    [
                        'question' => 'Can I change my offers?',
                        'answer' => 'Yes. You manage commissions, cashback campaigns and discount codes from the merchant dashboard. Public display always follows approval and publication rules.',
                    ],
                ],
            ],
            'footer' => [
                'blurb' => $nl
                    ? 'ShopBonus helpt webshops nieuwe klanten bereiken met cashback, kortingscodes en vouchers. Consumenten wisselen wallet-saldo in voor vouchers — nooit voor contant geld.'
                    : 'ShopBonus helps webshops reach new customers with cashback, discount codes and vouchers. Consumers redeem wallet balance for vouchers — never cash.',
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
            'subtitle' => ['sometimes', 'nullable', 'string', 'max:400', 'not_regex:/[<>]/'],
            'primary_cta' => ['sometimes', 'array'],
            'primary_cta.label' => ['required_with:primary_cta', 'string', 'max:50', 'not_regex:/[<>]/'],
            'primary_cta.href' => ['required_with:primary_cta', 'string', 'max:500'],
            'secondary_cta' => ['sometimes', 'array'],
            'secondary_cta.label' => ['required_with:secondary_cta', 'string', 'max:50', 'not_regex:/[<>]/'],
            'secondary_cta.href' => ['required_with:secondary_cta', 'string', 'max:500'],
            'trust' => ['sometimes', 'array', 'max:6'],
            'trust.*' => ['string', 'max:80', 'not_regex:/[<>]/'],
            'artwork_note' => ['sometimes', 'nullable', 'string', 'max:160', 'not_regex:/[<>]/'],
            'background_asset_id' => ['sometimes', 'nullable', 'string', 'max:40'],
            'illustration_asset_id' => ['sometimes', 'nullable', 'string', 'max:40'],
        ])->validate();

        foreach (['eyebrow', 'heading', 'subtitle', 'artwork_note'] as $f) {
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
        foreach (['background_asset_id', 'illustration_asset_id'] as $asset) {
            if (! empty($data[$asset])) {
                $this->assertAsset($data[$asset], "content.hero.{$asset}");
            }
        }

        return $data;
    }

    /** @param  array<string, mixed>  $section
     * @return array<string, mixed>
     */
    private function platforms(array $section): array
    {
        $data = Validator::make($section, [
            'heading' => ['required', 'string', 'max:120', 'not_regex:/[<>]/'],
            'subtitle' => ['sometimes', 'nullable', 'string', 'max:300', 'not_regex:/[<>]/'],
            'cta_label' => ['sometimes', 'nullable', 'string', 'max:60', 'not_regex:/[<>]/'],
            'cta_href' => ['sometimes', 'nullable', 'string', 'max:500'],
            'tiles' => ['sometimes', 'array', 'max:20'],
            'tiles.*.platform' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9_]+$/'],
            'tiles.*.name' => ['required', 'string', 'max:80', 'not_regex:/[<>]/'],
            'tiles.*.status' => ['required', Rule::in(self::PLATFORM_STATUSES)],
            'tiles.*.href' => ['required', 'string', 'max:500'],
        ])->validate();
        $this->rejectBanned($data['heading'], 'content.platforms.heading');
        if (isset($data['subtitle'])) {
            $this->rejectBanned($data['subtitle'], 'content.platforms.subtitle');
        }
        if (! empty($data['cta_label'])) {
            $this->rejectBanned($data['cta_label'], 'content.platforms.cta_label');
        }
        if (! empty($data['cta_href'])) {
            $this->assertHref($data['cta_href'], 'content.platforms.cta_href');
        }
        foreach ($data['tiles'] ?? [] as $i => $tile) {
            $this->rejectBanned($tile['name'], "content.platforms.tiles.{$i}.name");
            $this->assertHref($tile['href'], "content.platforms.tiles.{$i}.href");
        }

        return $data;
    }

    /** @param  array<string, mixed>  $section
     * @return array<string, mixed>
     */
    private function benefits(array $section): array
    {
        $data = Validator::make($section, [
            'eyebrow' => ['sometimes', 'nullable', 'string', 'max:40', 'not_regex:/[<>]/'],
            'heading' => ['required', 'string', 'max:160', 'not_regex:/[<>]/'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500', 'not_regex:/[<>]/'],
            'image_asset_id' => ['sometimes', 'nullable', 'string', 'max:40'],
            'items' => ['sometimes', 'array', 'max:6'],
            'items.*.icon' => ['required', 'string', 'max:40', Rule::in(['people', 'tag', 'cog', 'chart', 'shield', 'spark'])],
            'items.*.title' => ['required', 'string', 'max:80', 'not_regex:/[<>]/'],
            'items.*.description' => ['required', 'string', 'max:400', 'not_regex:/[<>]/'],
        ])->validate();
        foreach (['eyebrow', 'heading', 'description'] as $f) {
            if (isset($data[$f])) {
                $this->rejectBanned((string) $data[$f], "content.benefits.{$f}");
            }
        }
        if (! empty($data['image_asset_id'])) {
            $this->assertAsset($data['image_asset_id'], 'content.benefits.image_asset_id');
        }
        foreach ($data['items'] ?? [] as $i => $item) {
            $this->rejectBanned($item['title'], "content.benefits.items.{$i}.title");
            $this->rejectBanned($item['description'], "content.benefits.items.{$i}.description");
        }

        return $data;
    }

    /** @param  array<string, mixed>  $section
     * @return array<string, mixed>
     */
    private function offerPreview(array $section): array
    {
        $data = Validator::make($section, [
            'eyebrow' => ['sometimes', 'nullable', 'string', 'max:60', 'not_regex:/[<>]/'],
            'heading' => ['required', 'string', 'max:160', 'not_regex:/[<>]/'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500', 'not_regex:/[<>]/'],
            'cta_label' => ['sometimes', 'nullable', 'string', 'max:60', 'not_regex:/[<>]/'],
            'cta_href' => ['sometimes', 'nullable', 'string', 'max:500'],
            'preview_asset_id' => ['sometimes', 'nullable', 'string', 'max:40'],
            'illustrative' => ['sometimes', 'boolean'],
        ])->validate();
        foreach (['eyebrow', 'heading', 'description', 'cta_label'] as $f) {
            if (! empty($data[$f])) {
                $this->rejectBanned((string) $data[$f], "content.offer_preview.{$f}");
            }
        }
        if (! empty($data['cta_href'])) {
            $this->assertHref($data['cta_href'], 'content.offer_preview.cta_href');
        }
        if (! empty($data['preview_asset_id'])) {
            $this->assertAsset($data['preview_asset_id'], 'content.offer_preview.preview_asset_id');
        }
        $data['illustrative'] = (bool) ($data['illustrative'] ?? true);

        return $data;
    }

    /** @param  array<string, mixed>  $section
     * @return array<string, mixed>
     */
    private function onboarding(array $section): array
    {
        $data = Validator::make($section, [
            'eyebrow' => ['sometimes', 'nullable', 'string', 'max:60', 'not_regex:/[<>]/'],
            'heading' => ['required', 'string', 'max:120', 'not_regex:/[<>]/'],
            'steps' => ['required', 'array', 'min:1', 'max:6'],
            'steps.*.number' => ['required', 'string', 'max:4', 'not_regex:/[<>]/'],
            'steps.*.title' => ['required', 'string', 'max:80', 'not_regex:/[<>]/'],
            'steps.*.description' => ['required', 'string', 'max:400', 'not_regex:/[<>]/'],
            'steps.*.icon' => ['sometimes', 'string', 'max:40', Rule::in(['document', 'link', 'megaphone', 'check', 'shop'])],
        ])->validate();
        foreach (['eyebrow', 'heading'] as $f) {
            if (isset($data[$f])) {
                $this->rejectBanned((string) $data[$f], "content.onboarding.{$f}");
            }
        }
        foreach ($data['steps'] as $i => $step) {
            $this->rejectBanned($step['title'], "content.onboarding.steps.{$i}.title");
            $this->rejectBanned($step['description'], "content.onboarding.steps.{$i}.description");
        }

        return $data;
    }

    /** @param  array<string, mixed>  $section
     * @return array<string, mixed>
     */
    private function bottomCta(array $section): array
    {
        $data = Validator::make($section, [
            'eyebrow' => ['sometimes', 'nullable', 'string', 'max:40', 'not_regex:/[<>]/'],
            'heading' => ['required', 'string', 'max:160', 'not_regex:/[<>]/'],
            'description' => ['sometimes', 'nullable', 'string', 'max:400', 'not_regex:/[<>]/'],
            'cta_label' => ['required', 'string', 'max:50', 'not_regex:/[<>]/'],
            'cta_href' => ['required', 'string', 'max:500'],
        ])->validate();
        foreach (['eyebrow', 'heading', 'description', 'cta_label'] as $f) {
            if (isset($data[$f])) {
                $this->rejectBanned((string) $data[$f], "content.bottom_cta.{$f}");
            }
        }
        $this->assertHref($data['cta_href'], 'content.bottom_cta.cta_href');

        return $data;
    }

    /** @param  array<string, mixed>  $section
     * @return array<string, mixed>
     */
    private function faq(array $section): array
    {
        $data = Validator::make($section, [
            'heading' => ['required', 'string', 'max:120', 'not_regex:/[<>]/'],
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.question' => ['required', 'string', 'max:200', 'not_regex:/[<>]/'],
            'items.*.answer' => ['required', 'string', 'max:1200', 'not_regex:/[<>]/'],
        ])->validate();
        $this->rejectBanned($data['heading'], 'content.faq.heading');
        foreach ($data['items'] as $i => $item) {
            $this->rejectBanned($item['question'], "content.faq.items.{$i}.question");
            $this->rejectBanned($item['answer'], "content.faq.items.{$i}.answer");
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
        // Allow same-page anchors used by the merchant landing nav.
        if (preg_match('/^#[a-z0-9][a-z0-9_-]{0,60}$/i', $href) === 1) {
            return;
        }
        if (! HomepageSchema::safeHref($href)) {
            throw ValidationException::withMessages([$field => 'Links must be a relative path, page anchor, or https URL on an allowlisted host.']);
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
