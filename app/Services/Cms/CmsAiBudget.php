<?php

namespace App\Services\Cms;

use App\Exceptions\ApiException;
use App\Models\CmsAiSuggestion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Conservatively held EUR-micro reservations, not a claim about a provider invoice. */
class CmsAiBudget
{
    /** @param array<string,mixed> $payload */
    public function reserve(CmsAiSuggestion $suggestion, array $payload): void
    {
        $p = config('cms.pricing');
        $cap = config('cms.daily_budget_micros');
        if (! is_array($p) || ($p['model'] ?? null) !== $suggestion->model || ($p['currency'] ?? null) !== 'EUR' || ! is_string($p['reference'] ?? null) || trim($p['reference']) === '' || ! is_int($cap) || $cap <= 0 || $cap > 1000000000) {
            throw new ApiException('Verified CMS model pricing and a monetary cap are required.', 503, 'cms_budget_not_configured');
        }
        foreach (['context_tokens', 'input_micros_per_million', 'output_micros_per_million'] as $key) {
            if (! is_int($p[$key] ?? null) || $p[$key] <= 0 || $p[$key] > 10000000000) {
                throw new ApiException('Invalid verified CMS pricing profile.', 503, 'cms_budget_not_configured');
            }
        }
        try {
            if (! is_string($p['valid_until'] ?? null) || ! preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $p['valid_until'])) {
                throw new \RuntimeException;
            } $until = CarbonImmutable::parse($p['valid_until'], 'UTC')->endOfDay();
            if ($until->toDateString() !== $p['valid_until']) {
                throw new \RuntimeException;
            }
        } catch (\Throwable) {
            throw new ApiException('A current pricing review is required.', 503, 'cms_budget_not_configured');
        }
        $output = (int) config('cms.max_output_tokens');
        if ($until->isPast() || $until->greaterThan(now()->addDays(31)) || $p['context_tokens'] > 2000000 || $output < 1 || $output > $p['context_tokens'] || strlen(json_encode($payload, JSON_THROW_ON_ERROR)) + 4096 > $p['context_tokens']) {
            throw new ApiException('Pricing/context review is missing, stale or incompatible.', 503, 'cms_budget_not_configured');
        }
        // Full verified context input + maximum generated output is intentionally conservative.
        $reserved = $this->cost($p['context_tokens'], $output, $p);
        $p['output_tokens_limit'] = $output;
        $p['daily_cap_micros'] = $cap;
        $day = now()->utc()->toDateString();
        DB::transaction(function () use ($suggestion, $reserved, $day, $cap, $p) {
            DB::table('cms_ai_quotas')->upsert([['day' => $day, 'requests' => 0, 'reserved_micros' => 0, 'spend_blocked' => false]], ['day'], ['day']);
            $q = DB::table('cms_ai_quotas')->where('day', $day)->lockForUpdate()->first();
            $s = CmsAiSuggestion::whereKey($suggestion->id)->lockForUpdate()->firstOrFail();
            if ($s->reserved_micros !== null) {
                throw ApiException::conflict('This proposal already reserved provider execution.', 'cms_execution_reserved');
            }
            if ($q->spend_blocked || $q->reserved_micros > $cap - $reserved) {
                throw new ApiException('The daily monetary AI budget is exhausted.', 429, 'cms_ai_budget_exhausted');
            }
            DB::table('cms_ai_quotas')->where('day', $day)->increment('reserved_micros', $reserved);
            $s->forceFill(['budget_day' => $day, 'reserved_micros' => $reserved, 'pricing_snapshot' => $p])->save();
        }, 3);
        $suggestion->refresh();
    }

    public function recordUsage(CmsAiSuggestion $s): void
    {
        if ($s->input_tokens === null || $s->output_tokens === null || $s->pricing_snapshot === null) {
            return;
        }
        if ($s->input_tokens > $s->pricing_snapshot['context_tokens'] || $s->output_tokens > $s->pricing_snapshot['output_tokens_limit']) {
            DB::table('cms_ai_quotas')->where('day', $s->budget_day)->update(['spend_blocked' => true]);
            throw new ApiException('Provider usage exceeded the verified budget profile.', 503, 'cms_budget_profile_mismatch');
        }
        $cost = $this->cost($s->input_tokens, $s->output_tokens, $s->pricing_snapshot);
        $s->forceFill(['estimated_micros' => $cost])->save();
        if ($cost > $s->reserved_micros) {
            DB::table('cms_ai_quotas')->where('day', $s->budget_day)->update(['spend_blocked' => true]);
            throw new ApiException('Provider cost exceeded its reservation.', 503, 'cms_budget_profile_mismatch');
        }
    }

    /** @param array<string,mixed> $p */
    private function cost(int $input, int $output, array $p): int
    {
        return intdiv($input * $p['input_micros_per_million'] + 999999, 1000000) + intdiv($output * $p['output_micros_per_million'] + 999999, 1000000);
    }
}
