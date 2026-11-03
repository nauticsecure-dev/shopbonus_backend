<?php

namespace Tests\Support;

use App\Models\CookieConsent;
use App\Models\CookiePolicy;
use App\Models\User;
use App\Services\Consent\CookieConsents;

class BannerFixture
{
    public static function consentId(): string
    {
        $id = 'd9181cd8-5aa7-4fcb-9f9e-5b2f6c04a834';
        if (! CookieConsent::where('consent_id', $id)->exists()) {
            $policy = CookiePolicy::current() ?? CookiePolicy::create([
                'version' => 'banner-test-v1', 'status' => 'published', 'categories' => [], 'texts' => [],
                'requires_reconsent' => true, 'published_at' => now(), 'created_by' => User::factory()->create()->id,
            ]);
            app(CookieConsents::class)->record($id, $policy->version, 'accept_all', [], null, null);
        }

        return $id;
    }

    public static function config(): array
    {
        return ['template' => 'ribbon', 'placement' => ['anchor' => 'top'],
            'content' => ['heading' => 'Next-purchase reward', 'subtitle' => 'Approved offer terms apply', 'cta_label' => 'View offer', 'cta_url' => 'https://example.nl/rewards', 'terms' => 'Original terms'],
            'style' => ['background' => '#123456', 'text' => '#FFFFFF', 'font' => 'inter', 'animation' => 'none'],
            'devices' => ['desktop', 'mobile'], 'frequency' => ['per_session' => 1, 'hide_after_dismiss' => true],
            'triggers' => ['all' => [['type' => 'url_match', 'value' => '/collections/*']]]];
    }

    public static function output(string $kind = 'designs'): array
    {
        return ['status' => 'completed', 'output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => json_encode(['proposals' => array_map(fn ($anchor) => [
            'layers' => array_map(fn ($type) => ['type' => $type, 'visible' => true, 'align' => 'left', 'font_size' => 16, 'width_pct' => 100], ['text', 'image', 'cta']),
            'anchor' => $anchor, 'background' => '#123456', 'text' => '#FFFFFF', 'font' => 'inter', 'delay_seconds' => 20,
            'cart_threshold_minor' => null, 'evidence_refs' => [$kind === 'insights' ? 'E3' : 'E1'],
        ], ['bottom', 'top', 'bottom_right'])])]]]], 'usage' => ['input_tokens' => 100, 'output_tokens' => 200]];
    }
}
