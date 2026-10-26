<?php

namespace Tests\Support;

class BannerFixture
{
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
            'anchor' => $anchor, 'background' => '#123456', 'text' => '#FFFFFF', 'font' => 'inter', 'delay_seconds' => 20,
            'cart_threshold_minor' => null, 'evidence_refs' => [$kind === 'insights' ? 'E3' : 'E1'],
        ], ['bottom', 'top', 'bottom_right'])])]]]], 'usage' => ['input_tokens' => 100, 'output_tokens' => 200]];
    }
}
