<?php

namespace Tests\Feature;

use App\Integrations\ConnectionReadiness;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * NL is the default language: every literal API error, provider setup step, capability reason,
 * platform config text and gate label must have a Dutch translation, or this test names it.
 */
class LocalizationCoverageTest extends TestCase
{
    use RefreshDatabase;

    private const LITERAL = "'((?:[^'\\\\]|\\\\.)*)'";

    /** @return array<string,string> */
    private function dutch(): array
    {
        return json_decode((string) file_get_contents(lang_path('nl.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return list<string> */
    private function literals(string $directory, string $pattern): array
    {
        $found = [];
        foreach ((new Finder)->files()->in(base_path($directory))->name('*.php') as $file) {
            preg_match_all($pattern, $file->getContents(), $matches);
            foreach ($matches[1] as $raw) {
                $found[] = str_replace("\\'", "'", $raw);
            }
        }

        return array_values(array_unique($found));
    }

    public function test_every_literal_api_error_message_has_a_dutch_translation(): void
    {
        $messages = $this->literals('app', '/(?:new ApiException|ApiException::conflict)\(\s*'.self::LITERAL.'/');
        $this->assertGreaterThan(600, count($messages));
        $this->assertSame([], array_values(array_diff($messages, array_keys($this->dutch()))), 'API error messages without a Dutch translation in lang/nl.json.');
    }

    public function test_provider_steps_capability_reasons_config_texts_and_gate_labels_have_dutch_translations(): void
    {
        $steps = [];
        foreach ((new Finder)->files()->in(app_path('Integrations'))->name('*.php') as $file) {
            preg_match_all("/'steps' => \\[(.*?)\\]/s", $file->getContents(), $blocks);
            foreach ($blocks[1] as $block) {
                preg_match_all('/'.self::LITERAL.'/', $block, $strings);
                array_push($steps, ...array_map(fn ($s) => str_replace("\\'", "'", $s), $strings[1]));
            }
        }
        $reasons = array_merge(
            $this->literals('app/Integrations', '/->cap\\([^,]+,\\s*'.self::LITERAL.'/'),
            $this->literals('app/Integrations', '/\\? null : '.self::LITERAL.'/'),
            $this->literals('app/Services/Catalog', "/'reason' => [^;]*?".self::LITERAL.'/'),
        );
        $config = [];
        foreach (config('integrations.platforms') as $entry) {
            $profile = $entry['profile'] ?? [];
            $config[] = $entry['blocked_reason'] ?? null;
            $config[] = $profile['banner_method'] ?? null;
            if (is_string($profile['coupon_api'] ?? null) && str_contains(strtoupper($profile['coupon_api']), 'UNSUPPORTED')) {
                $config[] = $profile['coupon_api'];
            }
            if (! ($profile['refund_amounts_mapped'] ?? false) && ! ($profile['reported_refund_total_mapped'] ?? false)) {
                $config[] = $profile['refund_api'] ?? null;
            }
        }
        // User-facing text is a phrase; keys, paths and placeholders picked up by the scan are not.
        $required = array_unique(array_filter([...$steps, ...$reasons, ...$config, ...array_values(ConnectionReadiness::LABELS)],
            fn ($text) => is_string($text) && str_contains($text, ' ')));
        $this->assertGreaterThan(60, count($required)); // guards against a scan that silently finds nothing
        $this->assertSame([], array_values(array_diff($required, array_keys($this->dutch()))), 'Integration texts without a Dutch translation in lang/nl.json.');
    }

    public function test_errors_and_capability_reasons_follow_the_request_language(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/workspaces/'.str_repeat('0', 26).'/vouchers/exports/'.str_repeat('0', 26), ['Accept-Language' => 'nl'])
            ->assertJsonPath('error.message', __('The requested resource was not found.', [], 'nl'));

        $nl = collect($this->getJson('/api/v1/integrations/platforms', ['Accept-Language' => 'nl'])->assertOk()->json('data'))->keyBy('platform');
        $en = collect($this->getJson('/api/v1/integrations/platforms', ['Accept-Language' => 'en'])->assertOk()->json('data'))->keyBy('platform');
        $this->assertSame('Kortingscodes aanmaken valt buiten deze afbakening.', $nl['woocommerce']['capabilities']['coupons_write']['reason']);
        $this->assertSame('Coupon writes are out of scope for this boundary.', $en['woocommerce']['capabilities']['coupons_write']['reason']);
    }
}
