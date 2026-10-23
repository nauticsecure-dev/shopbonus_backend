<?php

namespace App\Services\Onboarding\Network;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SSRF-safe fetch of a verification file from a merchant website:
 * HTTPS only, every resolved address must be public, the connection is
 * pinned to a checked address (no DNS rebinding between check and connect),
 * redirects are not followed, and time and size are capped.
 */
class SafeWebsiteFileFetcher implements WebsiteFileFetcher
{
    public function __construct(private readonly HostResolver $resolver) {}

    public function fetch(string $domain, string $path, int $maxBytes = 1024): ?string
    {
        $addresses = $this->resolver->addresses($domain);

        if (! PublicAddressGuard::allPublic($addresses)) {
            Log::info('Verification fetch refused: non-public or unresolvable host', ['domain' => $domain]);

            return null;
        }

        $pinned = $addresses[0];
        $target = str_contains($pinned, ':') ? "[{$pinned}]" : $pinned;

        try {
            $response = Http::withOptions([
                'allow_redirects' => false,
                'connect_timeout' => 3,
                'timeout' => 5,
                'stream' => true,
                'curl' => [CURLOPT_RESOLVE => ["{$domain}:443:{$target}"]],
            ])->withHeaders(['User-Agent' => 'ShopbonusOwnershipVerifier/1.0'])
                ->get('https://'.$domain.$path);
        } catch (Throwable $e) {
            Log::info('Verification fetch failed', ['domain' => $domain, 'exception_class' => $e::class]);

            return null;
        }

        if ($response->status() !== 200) {
            return null;
        }

        $body = $response->toPsrResponse()->getBody();

        return $body->isReadable() ? $body->read($maxBytes) : null;
    }
}
