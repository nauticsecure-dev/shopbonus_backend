<?php

namespace App\Services\Enrichment;

use App\Services\Onboarding\DomainName;
use App\Services\Onboarding\Network\HostResolver;
use App\Services\Onboarding\Network\PublicAddressGuard;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SSRF-safe HTTPS fetch for merchant website pages: public DNS only, pinned
 * connect, no redirects, bounded time and body size.
 */
class SafeUrlFetcher
{
    public function __construct(private readonly HostResolver $resolver) {}

    public function fetch(string $url, int $maxBytes): ?string
    {
        $parts = parse_url($url);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || (isset($parts['port']) && $parts['port'] !== 443)
            || ($parts['path'] ?? '/') === '') {
            return null;
        }
        if (! extension_loaded('curl')) {
            return null;
        }

        try {
            $host = DomainName::fromInput(strtolower((string) ($parts['host'] ?? '')));
        } catch (Throwable) {
            return null;
        }

        $addresses = $this->resolver->addresses($host);
        if (! PublicAddressGuard::allPublic($addresses)) {
            Log::info('Enrichment fetch refused: non-public or unresolvable host', ['host' => $host]);

            return null;
        }

        $pinned = $addresses[0];
        $target = str_contains($pinned, ':') ? "[{$pinned}]" : $pinned;
        $path = $parts['path'] ?? '/';
        if ($path === '' || ! str_starts_with($path, '/')) {
            $path = '/'.$path;
        }

        try {
            $response = Http::withOptions([
                'allow_redirects' => false,
                'proxy' => '',
                'verify' => true,
                'connect_timeout' => 3,
                'timeout' => 8,
                'stream' => true,
                'curl' => [CURLOPT_RESOLVE => ["{$host}:443:{$target}"]],
            ])->withHeaders(['User-Agent' => 'ShopbonusEnrichment/1.0', 'Accept' => 'text/html,text/plain;q=0.9'])
                ->get('https://'.$host.$path);
        } catch (Throwable $e) {
            Log::info('Enrichment fetch failed', ['host' => $host, 'exception_class' => $e::class]);

            return null;
        }

        if ($response->status() !== 200) {
            return null;
        }

        $ctype = strtolower((string) $response->header('Content-Type'));
        if ($ctype !== '' && ! str_starts_with($ctype, 'text/html') && ! str_starts_with($ctype, 'text/plain')) {
            return null;
        }

        $body = $response->toPsrResponse()->getBody();

        return $body->isReadable() ? $body->read(max(1024, min(1048576, $maxBytes))) : null;
    }
}
