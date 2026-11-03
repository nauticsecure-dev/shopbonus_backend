<?php

namespace App\Services\Support;

use App\Services\Admin\Diagnostics\ProviderFailure;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/** Exact deployment/model proof and conservative, retained per-attempt exposure. */
final class SupportModelContract
{
    public const VERSION = 'strict-evidence-v1';

    /** @return array<string, mixed> */
    public function profile(string $model): array
    {
        $primary = $model === config('support_ai.model');
        $fallback = is_string(config('support_ai.fallback_model')) && config('support_ai.fallback_model') !== '' && $model === config('support_ai.fallback_model');
        $p = (array) config($primary ? 'support_ai.contract' : 'support_ai.fallback_contract');
        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,99}$/D', $model) !== 1 || (! $primary && ! $fallback) || ! is_string($p['pricing_reference'] ?? null) || trim($p['pricing_reference']) === '') {
            throw new ProviderFailure('model_contract_unconfigured');
        }
        foreach (['context_tokens' => 10000000, 'output_tokens' => 4000, 'input_micro_eur_per_token' => 1000000, 'output_micro_eur_per_token' => 1000000] as $field => $max) {
            if (! is_int($p[$field] ?? null) || $p[$field] < 1 || $p[$field] > $max) {
                throw new ProviderFailure('model_contract_unconfigured');
            }
        }

        if ($p['output_tokens'] < 256) {
            throw new ProviderFailure('model_contract_unconfigured');
        }

        return $p;
    }

    public function fingerprint(string $model): string
    {
        return hash('sha256', json_encode([self::VERSION, $model, hash('sha256', (string) config('support_ai.api_key')), $this->profile($model)], JSON_THROW_ON_ERROR));
    }

    public function verified(string $model): bool
    {
        try {
            return DB::table('support_model_verifications')->where('fingerprint', $this->fingerprint($model))
                ->where('expires_at', '>', now())->exists();
        } catch (ProviderFailure) {
            return false;
        }
    }

    public function record(string $model, string $fingerprint): void
    {
        if (! hash_equals($fingerprint, $this->fingerprint($model))) {
            throw new ProviderFailure('model_contract_changed');
        }
        DB::table('support_model_verifications')->updateOrInsert(['fingerprint' => $fingerprint], [
            'model' => $model, 'verified_at' => now(),
            'expires_at' => now()->addHours(max(1, min(168, (int) config('support_ai.contract_ttl_hours', 24)))),
        ]);
    }

    /** Reserve before HTTP. No refunds: missing usage, timeouts or crashes may still be billable. */
    public function reserve(string $model, int $outputTokens, mixed $policyLimit): int
    {
        $p = $this->profile($model);
        if ($outputTokens < 256 || $outputTokens > 4000) {
            throw new ProviderFailure('budget_unconfigured');
        }
        try {
            $limit = min(
                BigDecimal::of((string) $policyLimit)->multipliedBy(1000000)->toScale(0, RoundingMode::Floor)->toInt(),
                BigDecimal::of((string) config('support_ai.daily_cost_limit_eur', 20))->multipliedBy(1000000)->toScale(0, RoundingMode::Floor)->toInt(),
                1000000000,
            );
        } catch (\Throwable) {
            throw new ProviderFailure('budget_unconfigured');
        }
        $amount = $p['context_tokens'] * $p['input_micro_eur_per_token'] + $outputTokens * $p['output_micro_eur_per_token'];
        DB::transaction(function () use ($amount, $limit) {
            $key = ['day' => now()->utc()->toDateString(), 'scope' => 'budget:eur'];
            DB::table('support_quotas')->insertOrIgnore($key + ['requests' => 0, 'reserved_micro_eur' => 0]);
            $row = DB::table('support_quotas')->where($key)->lockForUpdate()->first();
            if ($limit <= 0 || $amount > $limit - $row->reserved_micro_eur) {
                throw new ProviderFailure('budget_exceeded');
            }
            DB::table('support_quotas')->where($key)->increment('reserved_micro_eur', $amount);
        }, 3);

        return $amount;
    }
}
