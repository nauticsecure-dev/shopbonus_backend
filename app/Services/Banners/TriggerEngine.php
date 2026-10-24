<?php

namespace App\Services\Banners;

use Carbon\CarbonImmutable;

/**
 * Decides whether a banner shows for a visitor context and explains why
 * (Trello #25 §4 "Why shown/hidden"). The same rules run in the public
 * renderer; this server-side version powers simulation and tests.
 *
 * Checkout, payment and account pages are always excluded. A dismissal is
 * never a click. Cart conditions need a verified cart total from a cart
 * bridge; without one, a cart-progress banner falls back to a static offer
 * instead of showing fake progress.
 */
class TriggerEngine
{
    private const EXCLUDED = '#^/(checkout|cart/checkout|payment|payments|account|my-account|login|register|order-received)(/|$)#i';

    /**
     * @param  array<string, mixed>  $config  validated BannerConfig
     * @param  array<string, mixed>  $ctx  path, category, product_sku, seconds, scroll_pct, exit_intent, device, language,
     *                                     local_time, visitor, shown_this_session, last_shown_at, dismissed, cart_total_minor, simulated
     * @return array{shown: bool, reasons: list<string>, cart_progress: array<string, int>|null, fallback: string|null, simulated: bool}
     */
    public function evaluate(array $config, array $ctx, ?int $rewardThresholdMinor = null): array
    {
        $reasons = [];
        $path = (string) ($ctx['path'] ?? '/');
        $simulated = (bool) ($ctx['simulated'] ?? false);
        $hide = fn (string $why) => ['shown' => false, 'reasons' => [...$reasons, $why], 'cart_progress' => null, 'fallback' => null, 'simulated' => $simulated];

        if (preg_match(self::EXCLUDED, $path) === 1) {
            return $hide('hidden: checkout, payment and account pages never show banners');
        }
        if (! in_array($ctx['device'] ?? 'desktop', $config['devices'], true)) {
            return $hide('hidden: not shown on '.($ctx['device'] ?? 'desktop'));
        }
        if (($config['frequency']['hide_after_dismiss'] ?? true) && ! empty($ctx['dismissed'])) {
            return $hide('hidden: the visitor closed it before');
        }
        if ((int) ($ctx['shown_this_session'] ?? 0) >= (int) $config['frequency']['per_session']) {
            return $hide('hidden: already shown '.$config['frequency']['per_session'].'× this session');
        }
        $cooldown = (int) ($config['frequency']['cooldown_hours'] ?? 0);
        if ($cooldown > 0 && isset($ctx['last_shown_at']) && CarbonImmutable::parse($ctx['last_shown_at'])->greaterThan(CarbonImmutable::now()->subHours($cooldown))) {
            return $hide("hidden: cooldown of {$cooldown} h not over");
        }

        [$ok, $why] = $this->rule($config['triggers'], $ctx);
        $reasons = [...$reasons, ...$why];
        if (! $ok) {
            return ['shown' => false, 'reasons' => $reasons, 'cart_progress' => null, 'fallback' => null, 'simulated' => $simulated];
        }

        $progress = null;
        $fallback = null;
        if ($config['template'] === 'cart_progress') {
            $cart = $ctx['cart_total_minor'] ?? null;
            if (! is_int($cart) || $rewardThresholdMinor === null) {
                $fallback = 'static_offer';
                $reasons[] = 'cart total not available from a verified cart bridge: showing the static offer, no progress';
            } else {
                $progress = ['cart_total_minor' => $cart, 'threshold_minor' => $rewardThresholdMinor, 'remaining_minor' => max(0, $rewardThresholdMinor - $cart),
                    'percent' => min(100, intdiv($cart * 100, max(1, $rewardThresholdMinor)))];
            }
        }

        return ['shown' => true, 'reasons' => [...$reasons, 'shown'], 'cart_progress' => $progress, 'fallback' => $fallback, 'simulated' => $simulated];
    }

    /**
     * @param  array<string, mixed>  $rule
     * @param  array<string, mixed>  $ctx
     * @return array{0: bool, 1: list<string>}
     */
    private function rule(array $rule, array $ctx): array
    {
        if (isset($rule['all']) || isset($rule['any'])) {
            $all = isset($rule['all']);
            $results = array_map(fn ($r) => $this->rule($r, $ctx), $all ? $rule['all'] : $rule['any']);
            $ok = $all ? ! in_array(false, array_column($results, 0), true) : in_array(true, array_column($results, 0), true);

            return [$ok, array_merge(...array_column($results, 1))];
        }

        $type = $rule['type'];
        $value = $rule['value'];
        $ok = match ($type) {
            'url_match' => preg_match('#^'.str_replace('\*', '.*', preg_quote($value, '#')).'$#i', (string) ($ctx['path'] ?? '/')) === 1,
            'category' => in_array($ctx['category'] ?? null, $value, true),
            'product_sku' => in_array($ctx['product_sku'] ?? null, $value, true),
            'min_seconds' => (int) ($ctx['seconds'] ?? 0) >= $value,
            'min_scroll_pct' => (int) ($ctx['scroll_pct'] ?? 0) >= $value,
            'exit_intent' => ! empty($ctx['exit_intent']) && ($ctx['device'] ?? 'desktop') === 'desktop',
            'device' => in_array($ctx['device'] ?? 'desktop', $value, true),
            'language' => in_array($ctx['language'] ?? null, $value, true),
            'weekday' => in_array($this->local($ctx)->isoWeekday(), $value, true),
            'hour_between' => $this->local($ctx)->hour >= $value[0] && $this->local($ctx)->hour < $value[1],
            'visitor' => ($ctx['visitor'] ?? 'first') === $value,
            'cart_min_minor' => is_int($ctx['cart_total_minor'] ?? null) && $ctx['cart_total_minor'] >= $value,
            default => false,
        };
        if (! empty($rule['negate'])) {
            $ok = ! $ok;
        }
        $label = $type.(! empty($rule['negate']) ? ' (not)' : '').' '.(is_array($value) ? implode(',', array_map('strval', $value)) : (is_bool($value) ? 'yes' : (string) $value));

        return [$ok, [($ok ? 'matched: ' : 'not matched: ').$label]];
    }

    /** @param array<string, mixed> $ctx */
    private function local(array $ctx): CarbonImmutable
    {
        return isset($ctx['local_time']) ? CarbonImmutable::parse($ctx['local_time']) : CarbonImmutable::now((string) config('shopbonus.business_timezone'));
    }
}
