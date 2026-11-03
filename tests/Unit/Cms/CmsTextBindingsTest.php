<?php

namespace Tests\Unit\Cms;

use App\Services\Cms\AboutUsSchema;
use App\Services\Cms\CmsTextBindings;
use App\Services\Cms\HomepageSchema;
use App\Services\Cms\MerchantLandingSchema;
use RuntimeException;
use Tests\TestCase;

class CmsTextBindingsTest extends TestCase
{
    public function test_all_seeded_copy_is_bound_or_explicitly_protected_and_replacement_preserves_structure(): void
    {
        foreach (['homepage' => HomepageSchema::class, 'over-ons' => AboutUsSchema::class, 'voor-webshops' => MerchantLandingSchema::class] as $key => $schema) {
            $content = app($schema)->defaults('nl');
            $bindings = CmsTextBindings::discover($content, 'landing', $key);
            $paths = array_column($bindings, 'path');
            $this->assertSame(count($paths), count(array_unique($paths)), $key);
            $walk = function (array $values, string $parent = '') use (&$walk, $paths): void {
                foreach ($values as $key => $value) {
                    $path = ltrim($parent.'.'.$key, '.');
                    if (is_array($value)) {
                        $walk($value, $path);
                    } elseif (is_string($value) && trim($value) !== '') {
                        $protected = preg_match('/(?:^body\.type$|^section_order\.|^schedule\.|\.href$|_href$|_asset_id$|\.icon$|\.platform$|\.status$|\.number$|^contact\.(?:legal_name|address|kvk|email)$|^team\.profiles\.\d+\.name$|^platforms\.tiles\.\d+\.name$|\.mode$|\.featured_slugs\.)/', $path);
                        $this->assertTrue(in_array($path, $paths, true) || $protected === 1, 'Unclassified field: '.$path);
                    }
                }
            };
            $walk($content);
            $this->assertSame($content, CmsTextBindings::replace($content, array_column($bindings, 'text'), 'landing', $key));
            $changed = CmsTextBindings::replace($content, array_fill(0, count($bindings), 'X'), 'landing', $key);
            $this->assertSame($content['section_order'], $changed['section_order']);
            $this->assertSame($content['nav']['links'][0]['href'], $changed['nav']['links'][0]['href']);
            $this->assertSame('X', $changed['hero']['heading']);
        }
    }

    public function test_empty_optional_copy_and_decorative_alt_are_preserved_without_creating_fields(): void
    {
        $content = ['title' => 'Welkom', 'seo_title' => null, 'hero' => ['heading' => 'Hallo', 'subtitle' => ''],
            'media' => ['hero' => ['alt' => '', 'desktop_asset_id' => 'asset']],
            'body' => ['type' => 'doc', 'content' => [['type' => 'image', 'attrs' => ['asset_id' => 'asset', 'alt' => '']]]]];
        $bindings = CmsTextBindings::discover($content, 'landing', 'homepage');
        $this->assertSame(['title', 'hero.heading'], array_column($bindings, 'path'));
        $this->assertSame($content, CmsTextBindings::replace($content, array_column($bindings, 'text'), 'landing', 'homepage'));
        $this->assertSame(['title' => 'Hi'], CmsTextBindings::replace(['title' => 'Hoi'], ['Hi']));
    }

    public function test_schema_specific_limits_reject_overlong_output_before_replacing(): void
    {
        $this->expectException(RuntimeException::class);
        CmsTextBindings::replace(['title' => 'Welkom', 'hero' => ['heading' => 'Hallo']], ['Welcome', str_repeat('a', 201)], 'landing', 'homepage');
    }

    public function test_extra_output_cannot_shift_a_translation_onto_another_field(): void
    {
        $this->expectException(RuntimeException::class);
        CmsTextBindings::replace(['title' => 'Welkom'], ['Welcome', 'Extra']);
    }
}
