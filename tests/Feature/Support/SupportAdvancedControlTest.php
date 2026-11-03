<?php

namespace Tests\Feature\Support;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\Admin\Diagnostics\ProviderFailure;
use App\Services\Admin\PlatformSettings;
use App\Services\Support\SupportModelContract;
use App\Services\Support\SupportPolicy;
use App\Services\Support\SupportPresentation;
use App\Services\Support\SupportProvider;
use App\Services\Support\ToolEvidence;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ConfiguresSupportModel;
use Tests\TestCase;

class SupportAdvancedControlTest extends TestCase
{
    use ConfiguresSupportModel, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['support_ai.api_key' => 'SYNTHETIC-ONLY']);
        $this->configureVerifiedSupportModel();
        config(['support_ai.fallback_model' => 'fixture-fallback', 'support_ai.fallback_contract' => config('support_ai.contract')]);
        Http::preventStrayRequests();
    }

    private function evidence(): ToolEvidence
    {
        return new ToolEvidence(0, 'get_my_wallet', [], null, 'en', 'synthetic', ['available_minor' => 0], CarbonImmutable::now());
    }

    private function policy(): array
    {
        return ['model' => 'fixture-model', 'fallback_model' => 'fixture-fallback', 'version' => 'synthetic', 'timeout_seconds' => 5, 'max_output_tokens' => 512];
    }

    private function verifiedFallback(): void
    {
        $contract = app(SupportModelContract::class);
        $contract->record('fixture-fallback', $contract->fingerprint('fixture-fallback'));
    }

    private function failure(string $code, callable $work): void
    {
        try {
            $work();
            $this->fail('Unsafe operation succeeded.');
        } catch (ProviderFailure $e) {
            $this->assertSame($code, $e->failureCode);
        }
    }

    public function test_each_empathy_choice_has_distinct_server_owned_wording_in_both_locales(): void
    {
        $renderer = app(SupportPresentation::class);
        foreach (['en', 'nl'] as $locale) {
            $answers = [];
            foreach (['minimal', 'balanced', 'high'] as $empathy) {
                $result = $renderer->apply(['status' => 'VERIFIED', 'answer' => 'SYNTHETIC-SOURCE', 'action' => null, 'evidence_ids' => []], ['tone' => 'direct', 'empathy' => $empathy], $locale);
                $this->assertStringContainsString('SYNTHETIC-SOURCE', $result['answer']);
                $answers[] = $result['answer'];
            }
            $this->assertCount(3, array_unique($answers));
        }
    }

    public function test_separately_probed_fallback_shares_budget_and_is_used_once_on_transient_failure(): void
    {
        Http::fake(function ($request) {
            if ($request['model'] === 'fixture-model') {
                return Http::response([], 503);
            }
            $ids = array_column(json_decode($request['input'], true)['evidence'], 'evidence_id');

            return Http::response(['status' => 'completed', 'output' => [['type' => 'function_call', 'name' => 'verify_support_evidence', 'arguments' => json_encode(['evidence_ids' => $ids])]]]);
        });
        $provider = app(SupportProvider::class);
        $provider->probe(array_replace($this->policy(), ['model' => 'fixture-fallback']));
        $before = (int) DB::table('support_quotas')->where('scope', 'budget:eur')->value('reserved_micro_eur');
        $result = $provider->select([$this->evidence()], $this->policy());
        $this->assertTrue($result['usage']['fallback_used']);
        $this->assertSame('fixture-fallback', $result['usage']['model']);
        $this->assertSame(2, $result['usage']['provider_attempts']);
        $this->assertSame('provider_unavailable', $result['usage']['primary_failure_code']);
        $this->assertSame(18432, (int) DB::table('support_quotas')->where('scope', 'budget:eur')->value('reserved_micro_eur') - $before);
        Http::assertSentCount(3); // one canary and two execution attempts
    }

    public function test_unverified_fallback_and_authentication_failure_never_trigger_a_second_request(): void
    {
        Http::fakeSequence()->push([], 503)->push([], 401);
        $this->failure('provider_unavailable', fn () => app(SupportProvider::class)->select([$this->evidence()], $this->policy()));
        Http::assertSentCount(1);
        $this->verifiedFallback();
        $this->failure('provider_authentication_failed', fn () => app(SupportProvider::class)->select([$this->evidence()], $this->policy()));
        Http::assertSentCount(2);
    }

    public function test_fallback_cannot_bypass_budget_or_deadline_or_invalid_output(): void
    {
        $this->verifiedFallback();
        config(['support_ai.daily_cost_limit_eur' => '0.010000']);
        Http::fakeSequence()->push([], 503)->push(['status' => 'completed', 'output' => [['type' => 'message', 'content' => 'Invented financial claim']]]);
        $this->failure('budget_exceeded', fn () => app(SupportProvider::class)->select([$this->evidence()], $this->policy()));
        Http::assertSentCount(1);
        $this->assertSame(9216, (int) DB::table('support_quotas')->where('scope', 'budget:eur')->value('reserved_micro_eur'));
        $this->failure('response_deadline_exceeded', fn () => app(SupportProvider::class)->select([$this->evidence()], $this->policy() + ['deadline_at' => microtime(true) - 1]));
        Http::assertSentCount(1);
        config(['support_ai.daily_cost_limit_eur' => '20']);
        $this->failure('invalid_provider_output', fn () => app(SupportProvider::class)->select([$this->evidence()], $this->policy()));
        Http::assertSentCount(2);
    }

    public function test_expired_response_is_rejected_even_if_the_provider_returns_valid_tool_output(): void
    {
        $this->verifiedFallback();
        Http::fake(function ($request) {
            usleep(100000);
            $ids = array_column(json_decode($request['input'], true)['evidence'], 'evidence_id');

            return Http::response(['status' => 'completed', 'output' => [['type' => 'function_call', 'name' => 'verify_support_evidence', 'arguments' => json_encode(['evidence_ids' => $ids])]]]);
        });
        $this->failure('response_deadline_exceeded', fn () => app(SupportProvider::class)->select([$this->evidence()], $this->policy() + ['response_deadline_ms' => 50]));
        Http::assertSentCount(1);
    }

    public function test_secondary_failure_stops_after_two_attempts_and_limits_cannot_exceed_its_proof(): void
    {
        $this->verifiedFallback();
        Http::fake(['https://api.openai.com/v1/responses' => Http::response([], 503)]);
        $this->failure('provider_unavailable', fn () => app(SupportProvider::class)->select([$this->evidence()], $this->policy()));
        Http::assertSentCount(2);
        config(['support_ai.fallback_contract.output_tokens' => 1000]);
        $this->verifiedFallback();
        try {
            app(SupportPolicy::class)->assertPublishable(['enabled' => true, 'model' => 'fixture-model', 'fallback_model' => 'fixture-fallback', 'max_output_tokens' => 1200]);
            $this->fail('Fallback output limit ignored.');
        } catch (ApiException $e) {
            $this->assertSame('support_model_output_limit', $e->errorCode);
        }
    }

    public function test_enabled_policy_publication_requires_exact_primary_and_fallback_proof(): void
    {
        $settings = app(PlatformSettings::class);
        $a = User::factory()->create();
        $b = User::factory()->create();
        $value = array_replace(SupportPolicy::defaults(), ['enabled' => true, 'model' => 'fixture-model', 'fallback_model' => 'fixture-fallback']);
        $version = $settings->draft($a, 'support.ai', $value, 'Synthetic capability gate');
        app(SupportPolicy::class)->testVersion($version, $a);
        $settings->submit($version, $a);
        try {
            $settings->publish($version, $b, 'Synthetic independent review');
            $this->fail('Unverified fallback was activated.');
        } catch (ApiException $e) {
            $this->assertSame('support_model_contract_required', $e->errorCode);
        }
        $this->verifiedFallback();
        $this->assertSame('published', $settings->publish($version, $b, 'Synthetic verified contracts')->status);
        config(['support_ai.api_key' => 'ROTATED-SYNTHETIC-KEY']);
        try {
            app(SupportPolicy::class)->assertPublishable($value);
            $this->fail('Rotated credentials retained proof.');
        } catch (ApiException $e) {
            $this->assertSame('support_model_contract_required', $e->errorCode);
        }
        Http::assertNothingSent(); // regression tests are not mistaken for model proof
    }
}
