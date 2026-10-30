<?php

namespace App\Services\Enrichment;

use App\Services\Admin\Diagnostics\ProviderFailure;
use App\Services\Discovery\PublicProfileService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class MerchantCoverGenerator
{
    public function configured(): bool
    {
        return config('enrichment.enabled') && is_string(config('enrichment.api_key')) && config('enrichment.api_key') !== ''
            && is_string(config('enrichment.image_model')) && preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,99}$/D', config('enrichment.image_model')) === 1;
    }

    /**
     * @param  array<string, mixed>  $proposal
     * @return array{path: string, mime: string}|null
     */
    public function generate(string $webshopPublicId, string $merchantName, array $proposal, string $model): ?array
    {
        if (! $this->configured()) {
            return null;
        }

        $prompt = 'Abstracte, neutrale headerafbeelding voor een Nederlandse webshop. Geen tekst, geen logo, geen merknaam, geen productfoto\'s met herkenbare merken. Stijl: zachte kleuren passend bij '
            .implode(', ', $proposal['categories'] ?? ['other']).'. Alleen decoratief patroon of sfeerbeeld.';

        try {
            $response = Http::withToken(config('enrichment.api_key'))->withOptions(['allow_redirects' => false])->acceptJson()->connectTimeout(5)->timeout(40)
                ->post('https://api.openai.com/v1/images/generations', [
                    'model' => $model,
                    'prompt' => mb_substr($prompt, 0, 900),
                    'size' => '1536x1024',
                    'response_format' => 'b64_json',
                    'n' => 1,
                ]);
        } catch (Throwable) {
            throw new ProviderFailure('cover_provider_unavailable');
        }

        if (! $response->successful()) {
            throw new ProviderFailure($response->status() === 429 ? 'cover_rate_limited' : 'cover_provider_unavailable');
        }

        $b64 = $response->json('data.0.b64_json');
        if (! is_string($b64) || $b64 === '') {
            throw new ProviderFailure('invalid_cover_output');
        }

        $binary = base64_decode($b64, true);
        if ($binary === false || strlen($binary) > 5242880) {
            throw new ProviderFailure('invalid_cover_output');
        }

        $path = 'public-profiles/'.$webshopPublicId.'/cover-'.Str::lower((string) Str::ulid()).'.webp';
        if (Storage::disk(PublicProfileService::DISK)->put($path, $binary) === false) {
            throw new ProviderFailure('cover_storage_unavailable');
        }

        return ['path' => $path, 'mime' => 'image/webp'];
    }
}
