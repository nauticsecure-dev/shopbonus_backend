<?php

namespace Tests\Feature\Support;

use App\Services\Admin\Diagnostics\ProviderFailure;
use App\Services\Support\SupportModelContract;
use App\Services\Support\SupportProvider;
use App\Services\Support\ToolEvidence;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ConfiguresSupportModel;
use Tests\TestCase;

class SupportExecutionGuardTest extends TestCase
{
    use ConfiguresSupportModel, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['support_ai.api_key' => 'synthetic-secret']);
        $this->configureVerifiedSupportModel();
        Http::preventStrayRequests();
    }

    private function evidence(): ToolEvidence
    {
        return new ToolEvidence(0, 'get_my_wallet', [], null, 'en', 'synthetic', ['available_minor' => 0], CarbonImmutable::now());
    }

    private function failure(string $code, callable $call): void
    {
        try {
            $call();
            $this->fail('Expected fail-closed execution.');
        } catch (ProviderFailure $e) {
            $this->assertSame($code, $e->failureCode);
        }
    }

    public function test_syntax_alone_or_another_model_key_profile_or_expired_proof_cannot_enable_egress(): void
    {
        $provider = app(SupportProvider::class);
        $this->assertTrue($provider->configured('fixture-model'));
        $this->assertFalse($provider->configured('different-model'));
        config(['support_ai.api_key' => 'rotated-synthetic-key']);
        $this->assertFalse($provider->configured('fixture-model'));
        config(['support_ai.api_key' => 'synthetic-secret', 'support_ai.contract.context_tokens' => 9000]);
        $this->assertFalse($provider->configured('fixture-model'));
        config(['support_ai.contract.context_tokens' => 8192]);
        DB::table('support_model_verifications')->update(['expires_at' => now()->subSecond()]);
        $this->failure('model_contract_unverified', fn () => $provider->select([$this->evidence()], ['model' => 'fixture-model']));
        Http::assertNothingSent();
    }

    public function test_budget_is_atomic_conservative_and_not_refunded_after_a_timeout(): void
    {
        $contract = app(SupportModelContract::class);
        config(['support_ai.daily_cost_limit_eur' => '0.010240']);
        $this->assertSame(10240, $contract->reserve('fixture-model', 1024, '20'));
        $this->failure('budget_exceeded', fn () => $contract->reserve('fixture-model', 1024, '20'));
        $this->assertSame(10240, (int) DB::table('support_quotas')->where('scope', 'budget:eur')->value('reserved_micro_eur'));
        $this->travel(1)->days();
        $contract->record('fixture-model', $contract->fingerprint('fixture-model'));
        Http::fake(['https://api.openai.com/v1/responses' => Http::failedConnection()]);
        $this->failure('provider_unavailable', fn () => app(SupportProvider::class)->select([$this->evidence()], [
            'model' => 'fixture-model', 'version' => 'fixture', 'timeout_seconds' => 5, 'max_output_tokens' => 1024,
        ]));
        $this->failure('budget_exceeded', fn () => app(SupportProvider::class)->select([$this->evidence()], [
            'model' => 'fixture-model', 'version' => 'fixture', 'timeout_seconds' => 5, 'max_output_tokens' => 1024,
        ]));
        Http::assertSentCount(1);
    }

    public function test_unknown_prices_zero_budget_and_lower_policy_budget_fail_closed(): void
    {
        $contract = app(SupportModelContract::class);
        $this->failure('budget_exceeded', fn () => $contract->reserve('fixture-model', 1024, '0'));
        $this->failure('budget_exceeded', fn () => $contract->reserve('fixture-model', 1024, '0.000001'));
        config(['support_ai.contract.input_micro_eur_per_token' => 0]);
        $this->failure('model_contract_unconfigured', fn () => $contract->reserve('fixture-model', 1024, '20'));
        Http::assertNothingSent();
    }

    public function test_only_an_actual_successful_canary_records_proof_and_optional_parameters_are_omitted(): void
    {
        DB::table('support_model_verifications')->delete();
        $provider = app(SupportProvider::class);
        Http::fake(function ($request) {
            $this->assertFalse($request['store']);
            $this->assertFalse($request['parallel_tool_calls']);
            $this->assertSame('required', $request['tool_choice']);
            $this->assertArrayNotHasKey('reasoning', $request->data());
            $this->assertArrayNotHasKey('text', $request->data());
            $ids = array_column(json_decode($request['input'], true)['evidence'], 'evidence_id');

            return Http::response(['status' => 'completed', 'output' => [['type' => 'function_call', 'name' => 'verify_support_evidence', 'arguments' => json_encode(['evidence_ids' => $ids])]]]);
        });
        $provider->probe(['model' => 'fixture-model', 'version' => 'fixture', 'timeout_seconds' => 5,
            'store' => true, 'parallel_tool_calls' => true, 'tool_choice' => 'none', 'reasoning_effort' => 'high', 'text_verbosity' => 'high']);
        $this->assertTrue($provider->configured('fixture-model'));
        Http::swap(new Factory);
        Http::fake(['https://api.openai.com/v1/responses' => Http::response(['status' => 'incomplete'], 200)]);
        $this->failure('invalid_provider_output', fn () => $provider->probe(['model' => 'fixture-model', 'version' => 'fixture', 'timeout_seconds' => 5]));
        $this->assertFalse($provider->configured('fixture-model'));
    }

    public function test_policy_cannot_exceed_the_output_limit_covered_by_the_canary(): void
    {
        config(['support_ai.contract.output_tokens' => 1000]);
        $contract = app(SupportModelContract::class);
        $contract->record('fixture-model', $contract->fingerprint('fixture-model'));
        $this->failure('model_contract_output_limit', fn () => app(SupportProvider::class)->select([$this->evidence()], [
            'model' => 'fixture-model', 'max_output_tokens' => 1200,
        ]));
        Http::assertNothingSent();
    }

    public function test_operator_command_uses_budget_and_migration_reapply_cannot_reset_exposure(): void
    {
        config(['support_ai.daily_cost_limit_eur' => '0']);
        $this->artisan('shopbonus:verify-support-model')->assertFailed();
        Http::assertNothingSent();
        $this->assertFalse(app(SupportProvider::class)->configured('fixture-model'));
        config(['support_ai.daily_cost_limit_eur' => '20']);
        $contract = app(SupportModelContract::class);
        $contract->record('fixture-model', $contract->fingerprint('fixture-model'));
        app(SupportModelContract::class)->reserve('fixture-model', 1024, '20');
        $migration = require database_path('migrations/2026_11_03_100000_add_support_execution_guards.php');
        $migration->down();
        $migration->up();
        $this->assertSame(10240, (int) DB::table('support_quotas')->where('scope', 'budget:eur')->value('reserved_micro_eur'));
        $this->assertTrue(app(SupportProvider::class)->configured('fixture-model'));
    }
}
