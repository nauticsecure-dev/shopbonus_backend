<?php

namespace App\Services\Banners;

use App\Logging\SensitiveDataProcessor;
use App\Services\Admin\Diagnostics\ProviderFailure;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

class BannerAiProvider
{
    public function configured(): bool
    {
        return config('banner_ai.enabled') && is_string(config('banner_ai.api_key')) && config('banner_ai.api_key') !== ''
            && is_string(config('banner_ai.model')) && preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,99}$/D', config('banner_ai.model')) === 1;
    }

    /** @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function generate(array $context, string $model): array
    {
        if (! $this->configured()) {
            throw new ProviderFailure('not_configured');
        }
        $properties = [
            'anchor' => ['type' => 'string', 'enum' => BannerConfig::ANCHORS],
            'background' => ['type' => 'string'], 'text' => ['type' => 'string'],
            'font' => ['type' => 'string', 'enum' => BannerConfig::FONTS],
            'delay_seconds' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 120],
            'cart_threshold_minor' => ['type' => ['integer', 'null'], 'minimum' => 0, 'maximum' => 200000000],
            'evidence_refs' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => array_keys($context['evidence'])]],
        ];
        try {
            $response = Http::withToken(config('banner_ai.api_key'))->withOptions(['allow_redirects' => false])->acceptJson()->connectTimeout(5)->timeout(20)
                ->post('https://api.openai.com/v1/responses', [
                    'model' => $model, 'store' => false, 'max_output_tokens' => max(256, min(4000, (int) config('banner_ai.max_output_tokens'))),
                    'instructions' => 'Propose exactly three editable banner layout/test hypotheses from the supplied evidence. Only supplied aggregate facts may be referenced. Do not invent measured uplift, actions, customer data, offer terms or prices. No publishing or tools. All proposals require human review. For design requests keep delay zero and cart threshold null; insights may propose location, timing or threshold tests. Plain text hypotheses only.',
                    'input' => json_encode($context, JSON_THROW_ON_ERROR),
                    'text' => ['format' => ['type' => 'json_schema', 'name' => 'banner_proposals', 'strict' => true, 'schema' => [
                        'type' => 'object', 'additionalProperties' => false, 'required' => ['proposals'], 'properties' => [
                            'proposals' => ['type' => 'array', 'minItems' => 3, 'maxItems' => 3, 'items' => [
                                'type' => 'object', 'additionalProperties' => false, 'required' => array_keys($properties), 'properties' => $properties,
                            ]],
                        ],
                    ]]],
                ]);
        } catch (Throwable) {
            throw new ProviderFailure('provider_unavailable');
        }
        if (! $response->successful()) {
            throw new ProviderFailure($response->status() === 429 ? 'provider_rate_limited' : 'provider_unavailable');
        }
        if (strlen($response->body()) > 65536 || $response->json('status') !== 'completed' || ! is_array($response->json('output'))) {
            throw new ProviderFailure('invalid_provider_output');
        }
        $texts = [];
        foreach ($response->json('output') as $output) {
            if (! is_array($output)) {
                throw new ProviderFailure('invalid_provider_output');
            }
            if (($output['type'] ?? '') === 'reasoning') {
                continue;
            }
            if (($output['type'] ?? '') !== 'message' || ($output['role'] ?? '') !== 'assistant' || ! is_array($output['content'] ?? null)) {
                throw new ProviderFailure('invalid_provider_output');
            }
            foreach ($output['content'] as $item) {
                if (! is_array($item) || ($item['type'] ?? '') !== 'output_text' || ! is_string($item['text'] ?? null)) {
                    throw new ProviderFailure('invalid_provider_output');
                }
                $texts[] = $item['text'];
            }
        }
        $result = count($texts) === 1 ? json_decode(str_replace(config('banner_ai.api_key'), '[REDACTED]', $texts[0]), true) : null;
        $rules = [
            'proposals' => ['required', 'array', 'list', 'size:3'],
            'proposals.*' => ['required', 'array:'.implode(',', array_keys($properties))],
            'proposals.*.anchor' => ['required', Rule::in(BannerConfig::ANCHORS)],
            'proposals.*.background' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/D'],
            'proposals.*.text' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/D'],
            'proposals.*.font' => ['required', Rule::in(BannerConfig::FONTS)],
            'proposals.*.delay_seconds' => ['required', 'integer:strict', 'between:0,120'],
            'proposals.*.cart_threshold_minor' => ['present', 'nullable', 'integer:strict', 'between:0,200000000'],
            'proposals.*.evidence_refs' => ['required', 'array', 'list', 'min:1', 'max:5'],
            'proposals.*.evidence_refs.*' => ['required', Rule::in(array_keys($context['evidence']))],
        ];
        if (! is_array($result) || array_keys($result) !== ['proposals'] || Validator::make($result, $rules)->fails()) {
            throw new ProviderFailure('invalid_provider_output');
        }
        $signatures = [];
        foreach ($result['proposals'] as &$proposal) {
            if ($context['kind'] === 'designs') {
                $proposal['delay_seconds'] = 0;
                $proposal['cart_threshold_minor'] = null;
            } elseif (array_intersect($proposal['evidence_refs'], ['E3', 'E4']) === []) {
                throw new ProviderFailure('invalid_provider_output');
            }
            $signatures[] = json_encode(array_intersect_key($proposal, array_flip(['anchor', 'background', 'text', 'font', 'delay_seconds', 'cart_threshold_minor'])), JSON_THROW_ON_ERROR);
            // Human-readable hypotheses are rendered from allowed fields, never unverifiable AI claims.
            $proposal['hypothesis'] = $context['kind'] === 'designs'
                ? 'Test placement '.$proposal['anchor'].' and style. Existing timing and eligibility stay unchanged. No measured uplift is established.'
                : 'Test placement '.$proposal['anchor'].' with an additional minimum delay of '.$proposal['delay_seconds'].' seconds'
                    .($proposal['cart_threshold_minor'] === null ? '' : ' and cart threshold '.$proposal['cart_threshold_minor'].' minor units')
                    .'. Existing restrictions still apply. No measured uplift is established.';
        }
        unset($proposal);
        if (count(array_unique($signatures)) !== 3) {
            throw new ProviderFailure('invalid_provider_output');
        }
        $tokens = fn ($v) => is_int($v) && $v >= 0 && $v <= 1000000 ? $v : null;

        return ['proposals' => app(SensitiveDataProcessor::class)->redactArray($result)['proposals'],
            'input_tokens' => $tokens($response->json('usage.input_tokens')), 'output_tokens' => $tokens($response->json('usage.output_tokens'))];
    }
}
