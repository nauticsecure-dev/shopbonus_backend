<?php

namespace Tests\Feature\Support;

use App\Services\Admin\Diagnostics\ProviderFailure;
use App\Services\Support\SupportProvider;
use App\Services\Support\ToolEvidence;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SupportProviderTest extends TestCase
{
    #[DataProvider('invalidOutputs')]
    public function test_model_refusals_malformed_calls_prose_and_redirects_never_publish(int $status, array $body, string $expected): void
    {
        config(['support_ai.api_key' => 'fixture-secret']);
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
            [429, ['error' => 'fixture-secret'], 'provider_rate_limited'], [503, [], 'provider_unavailable'], [302, [], 'provider_unavailable'],
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

    public function test_timeout_is_safe_with_no_automatic_retry(): void
    {
        config(['support_ai.api_key' => 'fixture-secret']);
        Http::fake(['https://api.openai.com/v1/responses' => Http::failedConnection()]);
        $e = new ToolEvidence(1, 'get_my_wallet', ['unit' => 'eur_credit'], null, 'en', 'wallet', ['available_minor' => 0], CarbonImmutable::now());
        $this->expectException(ProviderFailure::class);
        app(SupportProvider::class)->select([$e], ['model' => 'fixture-model', 'version' => 'test', 'timeout_seconds' => 5]);
    }
}
