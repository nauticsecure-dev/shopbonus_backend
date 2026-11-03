<?php

namespace Tests\Concerns;

use App\Services\Support\SupportModelContract;

trait ConfiguresSupportModel
{
    private function configureVerifiedSupportModel(): void
    {
        config(['support_ai.model' => 'fixture-model', 'support_ai.daily_cost_limit_eur' => '20', 'support_ai.contract' => [
            'context_tokens' => 8192, 'input_micro_eur_per_token' => 1, 'output_micro_eur_per_token' => 2,
            'pricing_reference' => 'SYNTHETIC TEST PRICES, not provider verification',
        ]]);
        $contract = app(SupportModelContract::class);
        $contract->record('fixture-model', $contract->fingerprint('fixture-model'));
    }
}
