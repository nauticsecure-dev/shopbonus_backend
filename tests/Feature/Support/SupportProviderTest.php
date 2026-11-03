<?php

namespace Tests\Feature\Support;

use App\Services\Admin\Diagnostics\ProviderFailure;
use App\Services\Support\SupportProvider;
use App\Services\Support\ToolEvidence;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ConfiguresSupportModel;
use Tests\TestCase;

class SupportProviderTest extends TestCase
{
    use ConfiguresSupportModel, \Illuminate\Foundation\Testing\RefreshDatabase;

    #[DataProvider('invalidOutputs')]
    public function test_model_refusals_malformed_calls_prose_and_redirects_never_publish(int $status, array $body, string $expected): void
    {
        config(['support_ai.api_key' => 'fixture-secret']);
        $this->configureVerifiedSupportModel();
        Http::fake(['https://api.openai.com/v1/responses' => Http::response($body, $status)]);
        $e = new ToolEvidence(1, 'get_my_wallet', ['unit' => 'eur_credit'], null, 'en', 'wallet', ['available_minor' => 0], CarbonImmutable::now());
        try {
            app(SupportProvider::class)->select([$e], ['model' => 'fixture-model', 'version' => 'test', 'timeout_seconds' => 5]);
            $this->fail('Unverified provider output was accepted.');
        } catch (ProviderFailure $f) {
            $this->assertSame($expected, $f->failureCode);
            $this->assertStringNotContainsString('fixture-secret', $f->getMessage());
        }
        Http::assertSentCount(1);
    }

    public static function invalidOutputs(): array
    {
        $call = ['type' => 'function_call', 'name' => 'verify_support_evidence', 'arguments' => '{"evidence_ids":["FAKE"]}'];

        return [
            [429, ['error' => 'fixture-secret'], 'provider_rate_limited'], [503, [], 'provider_unavailable'], [302, [], 'provider_rejected'],
            [200, ['status' => 'incomplete', 'output' => []], 'invalid_provider_output'],
            [200, ['status' => 'completed', 'output' => []], 'invalid_provider_output'],
            [200, ['status' => 'completed', 'output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'refusal', 'refusal' => 'No']]]]], 'invalid_provider_output'],
            [200, ['status' => 'completed', 'output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'EUR 999 tomorrow']]]]], 'invalid_provider_output'],
            [200, ['status' => 'completed', 'output' => [$call]], 'invalid_provider_output'],
            [200, ['status' => 'completed', 'output' => [$call, $call]], 'invalid_provider_output'],
            [200, ['status' => 'completed', 'output' => [array_replace($call, ['name' => 'refund'])]], 'invalid_provider_output'],
            [200, ['status' => 'completed', 'output' => [array_replace($call, ['arguments' => 'invalid JSON'])]], 'invalid_provider_output'],
        ];
    }

    public function test_approved_knowledge_prose_and_contact_data_stay_in_laravel(): void
    {
        config(['support_ai.api_key' => 'fixture-secret']);
        $this->configureVerifiedSupportModel();
        $e = new ToolEvidence(1, 'get_approved_knowledge', ['document_id' => 'internal-id'], null, 'en', 'private-source',
            ['scope' => 'consumers', 'version' => 1, 'question' => 'Private contact question', 'content' => 'Contact person@example.test phone +31612345678 secret voucher ABCD'], CarbonImmutable::now());
        Http::fake(function ($request) use ($e) {
            $this->assertStringNotContainsString('person@example.test', $request->body());
            $this->assertStringNotContainsString('31612345678', $request->body());
            $this->assertStringNotContainsString('ABCD', $request->body());
            $this->assertStringNotContainsString('Private contact question', $request->body());

            return Http::response(['status' => 'completed', 'output' => [['type' => 'function_call', 'name' => 'verify_support_evidence', 'arguments' => json_encode(['evidence_ids' => [$e->id]])]]]);
        });
        $result = app(SupportProvider::class)->select([$e], ['model' => 'fixture-model', 'version' => 'test', 'timeout_seconds' => 5]);
        $this->assertSame([$e->id], $result['selection']['evidence_ids']);
    }

    public function test_cost_estimate_requires_rates_and_model_specific_controls_are_omitted(): void
    {
        config(['support_ai.api_key' => 'fixture-secret', 'support_ai.verified_eur_rates' => ['fixture.model' => ['input_per_million' => 2, 'cached_input_per_million' => 1, 'output_per_million' => 4, 'verified_at' => '2026-10-31']]]);
        $this->configureVerifiedSupportModel('fixture.model');
        $e = new ToolEvidence(1, 'get_my_wallet', ['unit' => 'eur_credit'], null, 'en', 'wallet', ['available_minor' => 0], CarbonImmutable::now());
        Http::fake(function ($request) use ($e) {
            $this->assertArrayNotHasKey('reasoning', $request->data());
            $this->assertArrayNotHasKey('text', $request->data());
            $this->assertSame('required', $request['tool_choice']);
            $this->assertFalse($request['store']);

            return Http::response(['status' => 'completed', 'output' => [['type' => 'function_call', 'name' => 'verify_support_evidence', 'arguments' => json_encode(['evidence_ids' => [$e->id]])]], 'usage' => ['input_tokens' => 100, 'input_tokens_details' => ['cached_tokens' => 20], 'output_tokens' => 50]]);
        });
        $policy = ['model' => 'fixture.model', 'version' => 'test', 'timeout_seconds' => 5, 'reasoning_effort' => 'high', 'text_verbosity' => 'high', 'store' => true];
        $this->assertEqualsWithDelta(.00038, app(SupportProvider::class)->select([$e], $policy)['usage']['estimated_cost_eur'], .00000001);
        config(['support_ai.verified_eur_rates' => []]);
        $this->assertNull(app(SupportProvider::class)->select([$e], $policy)['usage']['estimated_cost_eur']);
    }

    public function test_timeout_is_safe_with_no_automatic_retry(): void
    {
        config(['support_ai.api_key' => 'fixture-secret']);
        $this->configureVerifiedSupportModel();
        Http::fake(['https://api.openai.com/v1/responses' => Http::failedConnection()]);
        $e = new ToolEvidence(1, 'get_my_wallet', ['unit' => 'eur_credit'], null, 'en', 'wallet', ['available_minor' => 0], CarbonImmutable::now());
        $this->expectException(ProviderFailure::class);
        app(SupportProvider::class)->select([$e], ['model' => 'fixture-model', 'version' => 'test', 'timeout_seconds' => 5]);
    }
}
