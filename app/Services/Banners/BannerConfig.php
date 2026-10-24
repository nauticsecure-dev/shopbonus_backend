<?php

namespace App\Services\Banners;

use App\Models\BannerAsset;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The banner configuration schema (Trello #25 §1, §4). Strict: plain text
 * only, https links, hex colours, known templates/anchors/fonts, images only
 * from this webshop's own uploaded assets. What passes here is what the
 * public renderer receives, so nothing executable can be published.
 */
class BannerConfig
{
    public const TEMPLATES = ['ribbon', 'inline_card', 'floating', 'teaser', 'popup', 'exit_intent', 'cart_progress'];

    public const ANCHORS = ['top', 'bottom', 'top_left', 'top_right', 'bottom_left', 'bottom_right', 'inline'];

    public const FONTS = ['system', 'inter', 'roboto', 'open_sans', 'lato', 'montserrat'];

    public const ANIMATIONS = ['none', 'fade_in', 'slide_in'];

    public const CONDITIONS = ['url_match', 'category', 'product_sku', 'min_seconds', 'min_scroll_pct', 'exit_intent', 'device', 'language', 'weekday', 'hour_between',
        'visitor', 'cart_min_minor'];

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function validate(array $config, int $webshopId): array
    {
        $noHtml = 'not_regex:/[<>]/';
        $color = 'regex:/^#[0-9a-fA-F]{6}$/';
        $data = Validator::make($config, [
            'template' => ['required', Rule::in(self::TEMPLATES)],
            'placement' => ['required', 'array:anchor,offset_x,offset_y,selector_hint'],
            'placement.anchor' => ['required', Rule::in(self::ANCHORS)],
            'placement.offset_x' => ['sometimes', 'integer', 'between:-500,500'],
            'placement.offset_y' => ['sometimes', 'integer', 'between:-500,500'],
            'placement.selector_hint' => ['sometimes', 'nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9 ._#\-\[\]=\"\']+$/'],
            'content' => ['required', 'array:heading,subtitle,cta_label,cta_url,terms,image_asset_id'],
            'content.heading' => ['required', 'string', 'max:80', $noHtml],
            'content.subtitle' => ['sometimes', 'nullable', 'string', 'max:160', $noHtml],
            'content.cta_label' => ['required', 'string', 'max:30', $noHtml],
            'content.cta_url' => ['required', 'string', 'max:500', 'url:https'],
            'content.terms' => ['sometimes', 'nullable', 'string', 'max:200', $noHtml],
            'content.image_asset_id' => ['sometimes', 'nullable', 'string', 'max:40'],
            'style' => ['required', 'array:background,text,accent,font,animation,radius'],
            'style.background' => ['required', $color], 'style.text' => ['required', $color], 'style.accent' => ['sometimes', $color],
            'style.font' => ['required', Rule::in(self::FONTS)], 'style.animation' => ['required', Rule::in(self::ANIMATIONS)],
            'style.radius' => ['sometimes', 'integer', 'between:0,32'],
            'devices' => ['required', 'array', 'min:1'], 'devices.*' => [Rule::in(['desktop', 'tablet', 'mobile'])],
            'frequency' => ['required', 'array:per_session,cooldown_hours,hide_after_dismiss'],
            'frequency.per_session' => ['required', 'integer', 'between:1,10'],
            'frequency.cooldown_hours' => ['sometimes', 'integer', 'between:0,720'],
            'frequency.hide_after_dismiss' => ['sometimes', 'boolean'],
            'priority' => ['sometimes', 'integer', 'between:0,100'],
            'triggers' => ['required', 'array'],
        ])->validate();

        $this->validateRule($data['triggers'], 'triggers', 0);

        if (! empty($data['content']['image_asset_id']) && ! BannerAsset::where('public_id', $data['content']['image_asset_id'])->where('webshop_id', $webshopId)->exists()) {
            throw ValidationException::withMessages(['content.image_asset_id' => 'Use an image uploaded for this webshop.']);
        }

        return $data;
    }

    /**
     * A rule is {all: [...]} / {any: [...]} of rules or conditions; nesting ≤ 3.
     *
     * @param  array<string, mixed>  $rule
     */
    private function validateRule(array $rule, string $path, int $depth): void
    {
        $fail = fn (string $msg) => throw ValidationException::withMessages([$path => $msg]);

        if ($depth > 3) {
            $fail('Rules can be nested at most three levels deep.');
        }
        $keys = array_keys($rule);
        if (count(array_intersect($keys, ['all', 'any'])) === 1 && count($keys) === 1) {
            $children = $rule['all'] ?? $rule['any'];
            if (! is_array($children) || $children === [] || count($children) > 20) {
                $fail('A group needs 1–20 rules.');
            }
            foreach (array_values($children) as $i => $child) {
                is_array($child) ? $this->validateRule($child, "{$path}.{$i}", $depth + 1) : $fail('Each rule must be an object.');
            }

            return;
        }

        $type = $rule['type'] ?? null;
        if (! in_array($type, self::CONDITIONS, true)) {
            $fail('Unknown condition type.');
        }
        $value = $rule['value'] ?? null;
        $ok = match ($type) {
            'url_match' => is_string($value) && preg_match('#^/[A-Za-z0-9/_\-.*]{0,200}$#', $value) === 1,
            'category', 'product_sku', 'language' => is_array($value) && $value !== [] && count($value) <= 50 && collect($value)->every(fn ($v) => is_string($v) && strlen($v) <= 60 && preg_match('/[<>]/', $v) !== 1),
            'min_seconds' => is_int($value) && $value >= 0 && $value <= 3600,
            'min_scroll_pct' => is_int($value) && $value >= 0 && $value <= 100,
            'exit_intent' => $value === true,
            'device' => is_array($value) && array_diff($value, ['desktop', 'tablet', 'mobile']) === [] && $value !== [],
            'weekday' => is_array($value) && array_diff($value, [1, 2, 3, 4, 5, 6, 7]) === [] && $value !== [],
            'hour_between' => is_array($value) && count($value) === 2 && is_int($value[0]) && is_int($value[1]) && $value[0] >= 0 && $value[1] <= 24 && $value[0] < $value[1],
            'visitor' => in_array($value, ['first', 'returning'], true),
            default => is_int($value) && $value > 0, // cart_min_minor
        };
        if (! $ok) {
            $fail("Invalid value for {$type}.");
        }
        if (isset($rule['negate']) && ! is_bool($rule['negate'])) {
            $fail('negate must be true or false.');
        }
    }
}
