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

        if (! function_exists('imagecreatefromstring') || ! function_exists('imagewebp')) {
            throw new ProviderFailure('cover_codec_unavailable');
        }
        $info = @getimagesizefromstring($binary);
        if ($info === false || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)
            || $info[0] < 640 || $info[1] < 320 || $info[0] > 4096 || $info[1] > 4096 || $info[0] * $info[1] > 12_000_000) {
            throw new ProviderFailure('invalid_cover_output');
        }
        $image = @imagecreatefromstring($binary);
        if ($image === false) {
            throw new ProviderFailure('invalid_cover_output');
        }
        $path = 'public-profiles/'.$webshopPublicId.'/cover-'.Str::lower((string) Str::ulid()).'.webp';
        $written = [];
        try {
            foreach ([640, 960, 1536] as $width) {
                $targetWidth = min($width, $info[0]);
                $height = max(1, (int) round($info[1] * $targetWidth / $info[0]));
                $variant = imagescale($image, $targetWidth, $height, IMG_BICUBIC_FIXED);
                if ($variant === false) {
                    throw new ProviderFailure('invalid_cover_output');
                }
                ob_start();
                try {
                    $ok = imagewebp($variant, null, 82);
                    $bytes = ob_get_contents();
                } finally {
                    ob_end_clean();
                    imagedestroy($variant);
                }
                if (! $ok || ! is_string($bytes) || $bytes === '' || @getimagesizefromstring($bytes) === false) {
                    throw new ProviderFailure('invalid_cover_output');
                }
                $variantPath = $width === 1536 ? $path : substr($path, 0, -5).'-'.$width.'.webp';
                // Include the attempted path: a failed adapter may have partially written it.
                $written[] = $variantPath;
                try {
                    $stored = Storage::disk(PublicProfileService::DISK)->put($variantPath, $bytes);
                } catch (Throwable) {
                    throw new ProviderFailure('cover_storage_unavailable');
                }
                if (! $stored) {
                    throw new ProviderFailure('cover_storage_unavailable');
                }
            }
        } catch (Throwable $e) {
            try {
                Storage::disk(PublicProfileService::DISK)->delete($written);
            } catch (Throwable $cleanupFailure) {
                // Preserve the original failure so enrichment can retain its cover fallback.
                report($cleanupFailure);
            }
            throw $e;
        } finally {
            imagedestroy($image);
        }

        return ['path' => $path, 'mime' => 'image/webp'];
    }
}
