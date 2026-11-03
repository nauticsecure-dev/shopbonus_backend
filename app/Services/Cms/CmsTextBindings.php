<?php

namespace App\Services\Cms;

use RuntimeException;

/** Canonical translatable paths. Routing, identity, money and media references never enter AI input. */
final class CmsTextBindings
{
    /** @return array<int, string> Maximum characters => space-separated path patterns. */
    private static function patterns(?string $type, ?string $key): array
    {
        $common = [120 => 'title', 70 => 'seo_title', 160 => 'seo_description'];
        if ($type !== 'landing' && ! in_array($key, ['homepage', 'voor-webshops'], true)) {
            return $common;
        }
        $common[200] = 'media.*.alt';
        $common[80] = 'nav.logo_alt';
        $common[40] = 'nav.links.*.label';
        $specific = match ($key) {
            'over-ons' => [
                40 => 'nav.login_label nav.cta_label hero.eyebrow story.eyebrow values.eyebrow contact.links.*.label',
                50 => 'hero.cta_label bottom_cta.consumer_cta.label bottom_cta.merchant_cta.label',
                60 => 'revenue_model.eyebrow team.eyebrow bottom_cta.eyebrow',
                80 => 'hero.heading_accent hero.float_title story.heading_accent revenue_model.consumer_label revenue_model.platform_label values.items.*.title team.profiles.*.role team.placeholder_label bottom_cta.heading_accent',
                120 => 'contact.heading',
                160 => 'story.heading revenue_model.heading values.heading team.heading bottom_cta.heading',
                200 => 'hero.float_text',
                220 => 'hero.heading',
                400 => 'hero.subtitle revenue_model.consumer_description revenue_model.platform_description values.items.*.description team.quote team.profiles.*.bio footer.blurb',
                500 => 'revenue_model.footnote values.introduction contact.intro bottom_cta.description',
                800 => 'story.paragraphs.* revenue_model.explanation team.body',
            ],
            'voor-webshops' => [
                40 => 'nav.login_label nav.cta_label hero.eyebrow benefits.eyebrow bottom_cta.eyebrow',
                50 => 'hero.primary_cta.label hero.secondary_cta.label bottom_cta.cta_label',
                60 => 'platforms.cta_label offer_preview.eyebrow offer_preview.cta_label onboarding.eyebrow',
                80 => 'hero.trust.* benefits.items.*.title onboarding.steps.*.title footer.links.*.label',
                120 => 'platforms.heading onboarding.heading faq.heading',
                160 => 'hero.artwork_note benefits.heading offer_preview.heading bottom_cta.heading',
                200 => 'faq.items.*.question',
                220 => 'hero.heading',
                300 => 'platforms.subtitle',
                400 => 'hero.subtitle platforms.tiles.*.description benefits.items.*.description onboarding.steps.*.description bottom_cta.description footer.blurb',
                500 => 'benefits.description offer_preview.description',
                1200 => 'faq.items.*.answer',
            ],
            default => [
                40 => 'hero.primary_cta.label hero.secondary_cta.label hero.promo_balance_example hero.promo_voucher_example wallet_preview.guest_cta.label wallet_preview.voucher_cta.label wallet_preview.transactions_cta.label cta_band.consumer_cta.label cta_band.merchant_cta.label',
                60 => 'popular_webshops.cta_label discount_codes.cta_label',
                80 => 'hero.trust.* how_it_works.steps.*.title',
                120 => 'search.placeholder popular_webshops.heading how_it_works.heading discount_codes.heading wallet_preview.heading',
                160 => 'hero.artwork_note hero.background_alt',
                200 => 'hero.heading how_it_works.subtitle',
                300 => 'hero.subtitle wallet_preview.guest_text footer.blurb benefits.heading benefits.consumer_title benefits.consumer_items.* benefits.merchant_title benefits.merchant_items.* cta_band.heading cta_band.subtitle faq_teaser.heading faq_teaser.subtitle faq_teaser.cta_label',
                400 => 'how_it_works.steps.*.description',
            ],
        };
        foreach ($specific as $limit => $paths) {
            $common[$limit] = trim(($common[$limit] ?? '').' '.$paths);
        }

        return $common;
    }

    /**
     * @param  array<string, mixed>  $content  Validated CMS content
     * @return list<array{path: string, kind: string, max_length: int, text: string}>
     */
    public static function discover(array $content, ?string $type = null, ?string $key = null): array
    {
        $out = [];
        $add = function (string $path, mixed $value, int $limit, string $kind = 'text') use (&$out): void {
            if (is_string($value) && trim($value) !== '') {
                $out[] = ['path' => $path, 'kind' => $kind, 'max_length' => $limit, 'text' => $value];
            }
        };
        foreach (self::patterns($type, $key) as $limit => $patterns) {
            foreach (explode(' ', $patterns) as $pattern) {
                $visit = function (mixed $value, array $parts, string $path) use (&$visit, $add, $limit, $pattern): void {
                    if ($parts === []) {
                        $kind = in_array($pattern, ['title', 'seo_title', 'seo_description'], true) ? $pattern : 'text';
                        $add($path, $value, $limit, $kind);

                        return;
                    }
                    $part = array_shift($parts);
                    if (! is_array($value)) {
                        return;
                    }
                    foreach ($value as $childKey => $child) {
                        if ($part === '*' || (string) $childKey === $part) {
                            $visit($child, $parts, ltrim($path.'.'.$childKey, '.'));
                        }
                    }
                };
                $visit($content, explode('.', $pattern), '');
            }
        }
        $walk = function (array $node, string $path) use (&$walk, $add): void {
            match ($node['type'] ?? null) {
                'text' => $add($path.'.text', $node['text'] ?? null, 5000),
                'button' => $add($path.'.attrs.label', $node['attrs']['label'] ?? null, 40, 'label'),
                'image' => $add($path.'.attrs.alt', $node['attrs']['alt'] ?? null, 200, 'alt'),
                default => null,
            };
            foreach ($node['content'] ?? [] as $i => $child) {
                if (is_array($child)) {
                    $walk($child, $path.'.content.'.$i);
                }
            }
        };
        $walk((array) ($content['body'] ?? []), 'body');

        return $out;
    }

    /**
     * @param  array<string, mixed>  $content
     * @param  list<string>  $texts
     * @return array<string, mixed>
     */
    public static function replace(array $content, array $texts, ?string $type = null, ?string $key = null): array
    {
        $bindings = self::discover($content, $type, $key);
        if (count($texts) !== count($bindings)) {
            throw new RuntimeException('Segment count mismatch.');
        }
        foreach ($bindings as $i => $binding) {
            if (trim($texts[$i]) === '' || mb_strlen($texts[$i]) > $binding['max_length']) {
                throw new RuntimeException('Invalid translated field.');
            }
            $parts = explode('.', $binding['path']);
            $value = &$content;
            foreach ($parts as $part) {
                $value = &$value[$part];
            }
            $value = $texts[$i];
            unset($value);
        }

        return $content;
    }
}
