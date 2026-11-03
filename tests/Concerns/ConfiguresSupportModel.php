<?php

namespace Tests\Concerns;

use App\Services\Support\SupportModelContract;

trait ConfiguresSupportModel
{
    private function configureVerifiedSupportModel(string $model = 'fixture-model'): void
    {
        config(['support_ai.model' => $model, 'support_ai.daily_cost_limit_eur' => '20', 'support_ai.contract' => [
            'context_tokens' => 8192, 'output_tokens' => 4000, 'input_micro_eur_per_token' => 1, 'output_micro_eur_per_token' => 2,
            'pricing_reference' => 'SYNTHETIC TEST PRICES, not provider verification',
        ]]);
        $contract = app(SupportModelContract::class);
        $contract->record($model, $contract->fingerprint($model));
    }
}
