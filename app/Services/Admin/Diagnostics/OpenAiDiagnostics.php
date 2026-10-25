<?php

namespace App\Services\Admin\Diagnostics;

use App\Logging\SensitiveDataProcessor;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class OpenAiDiagnostics
{
    public function configured(): bool
    {
        return config('diagnostics.enabled') && is_string(config('diagnostics.api_key')) && config('diagnostics.api_key') !== ''
            && is_string(config('diagnostics.model')) && preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,99}$/D', config('diagnostics.model')) === 1;
    }

    /** @param array<string, mixed> $context
     * @return array{analysis: array<string, mixed>, input_tokens: int|null, output_tokens: int|null}
     */
    public function analyze(array $context, string $model): array
    {
        if (! $this->configured()) {
            throw new ProviderFailure('not_configured');
        }
        $properties = [
            'likely_cause' => ['type' => 'string'],
            'evidence_refs' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => array_keys($context['evidence'])]],
            'impacted_component' => ['type' => 'string', 'enum' => ['api', 'integrations', 'background_jobs', 'unknown']],
            'suggested_investigation' => ['type' => 'array', 'items' => ['type' => 'string']],
            'proposed_fix' => ['type' => 'string'], 'confidence' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
            'uncertainty' => ['type' => 'string'],
        ];
        try {
            $response = Http::withToken(config('diagnostics.api_key'))->acceptJson()->connectTimeout(5)->timeout(20)
                ->post('https://api.openai.com/v1/responses', [
                    'model' => $model, 'store' => false, 'max_output_tokens' => max(256, min(4000, (int) config('diagnostics.max_output_tokens'))),
                    'instructions' => 'You propose advisory incident investigations only. Facts are limited to supplied evidence. Do not invent logs, customer data, actions taken or confirmed root causes. Explain uncertainty. Never execute tools, change permissions, deploy, pay/refund, alter a ledger or close an incident. Return plain text fields in the required JSON schema.',
                    'input' => json_encode($context, JSON_THROW_ON_ERROR),
                    'text' => ['format' => ['type' => 'json_schema', 'name' => 'incident_diagnosis', 'strict' => true,
                        'schema' => ['type' => 'object', 'properties' => $properties, 'required' => array_keys($properties), 'additionalProperties' => false]]],
                ]);
        } catch (\Throwable) {
            throw new ProviderFailure('provider_unavailable');
        }
        if (! $response->successful()) {
            throw new ProviderFailure($response->status() === 429 ? 'provider_rate_limited' : 'provider_unavailable');
        }
        if (strlen($response->body()) > 65536 || $response->json('status') !== 'completed') {
            throw new ProviderFailure('invalid_provider_output');
        }
        $texts = [];
        $outputs = $response->json('output');
        if (! is_array($outputs)) {
            throw new ProviderFailure('invalid_provider_output');
        }
        foreach ($outputs as $output) {
            if (! is_array($output)) {
                throw new ProviderFailure('invalid_provider_output');
            }
            if (($output['type'] ?? '') === 'reasoning') {
                continue;
            }
            if (($output['type'] ?? '') !== 'message' || ($output['role'] ?? '') !== 'assistant') {
                throw new ProviderFailure('invalid_provider_output');
            }
            if (! is_array($output['content'] ?? null)) {
                throw new ProviderFailure('invalid_provider_output');
            }
            foreach ($output['content'] as $item) {
                if (! is_array($item)) {
                    throw new ProviderFailure('invalid_provider_output');
                }
                if (($item['type'] ?? '') !== 'output_text' || ! is_string($item['text'] ?? null)) {
                    throw new ProviderFailure('invalid_provider_output');
                }
                $texts[] = $item['text'];
            }
        }
        $analysis = count($texts) === 1 ? json_decode(str_replace(config('diagnostics.api_key'), '[REDACTED]', $texts[0]), true) : null;
        if (! is_array($analysis) || array_diff(array_keys($analysis), array_keys($properties)) !== []) {
            throw new ProviderFailure('invalid_provider_output');
        }
        $validator = Validator::make($analysis, [
            'likely_cause' => ['required', 'string', 'max:1000'], 'evidence_refs' => ['required', 'array', 'list', 'min:1', 'max:5'],
            'evidence_refs.*' => ['required', 'string', 'distinct', Rule::in(array_keys($context['evidence']))],
            'impacted_component' => ['required', Rule::in(['api', 'integrations', 'background_jobs', 'unknown'])],
            'suggested_investigation' => ['required', 'array', 'list', 'min:1', 'max:5'], 'suggested_investigation.*' => ['required', 'string', 'max:500'],
            'proposed_fix' => ['required', 'string', 'max:2000'], 'confidence' => ['required', Rule::in(['low', 'medium', 'high'])], 'uncertainty' => ['required', 'string', 'max:1000'],
        ]);
        if ($validator->fails()) {
            throw new ProviderFailure('invalid_provider_output');
        }
        $analysis = app(SensitiveDataProcessor::class)->redactArray($validator->validated());
        array_walk_recursive($analysis, function (&$value) {
            if (is_string($value)) {
                $value = strip_tags($value);
            }
        });
        $tokens = fn ($value) => is_int($value) && $value >= 0 && $value <= 1000000 ? $value : null;

        return ['analysis' => $analysis, 'input_tokens' => $tokens($response->json('usage.input_tokens')), 'output_tokens' => $tokens($response->json('usage.output_tokens'))];
    }
}
