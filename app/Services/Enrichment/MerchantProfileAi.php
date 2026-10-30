<?php

namespace App\Services\Enrichment;

use App\Logging\SensitiveDataProcessor;
use App\Services\Admin\Diagnostics\ProviderFailure;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

class MerchantProfileAi
{
    /** @var list<string> */
    public const CATEGORIES = [
        'fashion', 'home_garden', 'electronics', 'beauty', 'food', 'sports', 'kids', 'health', 'automotive', 'other',
    ];

    public function configured(): bool
    {
        return config('enrichment.enabled') && is_string(config('enrichment.api_key')) && config('enrichment.api_key') !== ''
            && is_string(config('enrichment.text_model')) && preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,99}$/D', config('enrichment.text_model')) === 1;
    }

    /**
     * @param  array<string, mixed>  $research
     * @return array{proposal: array<string, mixed>, input_tokens: int|null, output_tokens: int|null}
     */
    public function propose(array $research, string $merchantName, string $model): array
    {
        if (! $this->configured()) {
            throw new ProviderFailure('not_configured');
        }

        $evidenceKeys = array_keys($research['evidence'] ?? []);
        $properties = [
            'short_description' => ['type' => 'string'],
            'description' => ['type' => 'string'],
            'categories' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => self::CATEGORIES]],
            'review_required' => ['type' => 'boolean'],
            'risk_level' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
            'evidence_refs' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => $evidenceKeys]],
            'uncertainty' => ['type' => 'string'],
        ];

        $context = [
            'merchant_name' => $merchantName,
            'research' => $research,
        ];

        try {
            $response = Http::withToken(config('enrichment.api_key'))->withOptions(['allow_redirects' => false])->acceptJson()->connectTimeout(5)->timeout(25)
                ->post('https://api.openai.com/v1/responses', [
                    'model' => $model,
                    'store' => false,
                    'max_output_tokens' => max(256, min(4000, (int) config('enrichment.max_output_tokens'))),
                    'instructions' => 'Schrijf een publiek webshopprofiel in het Nederlands uitsluitend op basis van de meegeleverde website-onderzoeksgegevens. Verzin geen feiten, prijzen, aanbiedingen, reviews, locaties of contactgegevens die niet in het bewijs staan. Houd short_description onder 160 tekens. Beschrijving maximaal 600 tekens, feitelijk en neutraal. Kies nul tot drie categorieën uit de toegestane lijst. Zet review_required op true wanneer het bewijs dun, tegenstrijdig of onduidelijk is, of wanneer je niet zeker bent. risk_level low alleen bij duidelijk, consistent bewijs over wat de winkel verkoopt; anders medium of high. evidence_refs moeten verwijzen naar gebruikte bewijsregels.',
                    'input' => json_encode($context, JSON_THROW_ON_ERROR),
                    'text' => ['format' => ['type' => 'json_schema', 'name' => 'merchant_profile_proposal', 'strict' => true, 'schema' => [
                        'type' => 'object', 'additionalProperties' => false, 'required' => array_keys($properties), 'properties' => $properties,
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

        $proposal = count($texts) === 1 ? json_decode(str_replace(config('enrichment.api_key'), '[REDACTED]', $texts[0]), true) : null;
        $rules = [
            'short_description' => ['required', 'string', 'min:10', 'max:160'],
            'description' => ['required', 'string', 'min:20', 'max:600'],
            'categories' => ['required', 'array', 'list', 'max:3'],
            'categories.*' => ['required', Rule::in(self::CATEGORIES)],
            'review_required' => ['required', 'boolean'],
            'risk_level' => ['required', Rule::in(['low', 'medium', 'high'])],
            'evidence_refs' => ['required', 'array', 'list', 'min:1', 'max:5'],
            'evidence_refs.*' => ['required', Rule::in($evidenceKeys)],
            'uncertainty' => ['required', 'string', 'max:500'],
        ];

        if (! is_array($proposal) || Validator::make($proposal, $rules)->fails()) {
            throw new ProviderFailure('invalid_provider_output');
        }

        $proposal = app(SensitiveDataProcessor::class)->redactArray(Validator::make($proposal, $rules)->validated());
        array_walk_recursive($proposal, function (&$value) {
            if (is_string($value)) {
                $value = strip_tags($value);
            }
        });

        $tokens = fn ($v) => is_int($v) && $v >= 0 && $v <= 1000000 ? $v : null;

        return [
            'proposal' => $proposal,
            'input_tokens' => $tokens($response->json('usage.input_tokens')),
            'output_tokens' => $tokens($response->json('usage.output_tokens')),
        ];
    }
}
