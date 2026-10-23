<?php

namespace Tests\Concerns;

use Illuminate\Testing\TestResponse;

/**
 * Makes feature tests behave like the real SPA: cookies from each response
 * (session, XSRF-TOKEN) are sent on the next request, the request comes
 * from a Sanctum stateful origin, and auth guards are reset between
 * requests as a fresh PHP request would. Without this, Laravel's test
 * client neither keeps cookies nor forgets cached users, so session tests
 * could pass for the wrong reason.
 */
trait BrowsesLikeSpa
{
    /** @var array<string, string> raw (already encrypted) cookie values */
    private array $spaCookies = [];

    /**
     * @param  array<string, mixed>  $data
     */
    protected function spa(string $method, string $uri, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        // The session store is a shared singleton in tests and would otherwise
        // carry the previous request's attributes; a real request only has
        // what its session cookie points to.
        $this->app['session']->driver()->flush();

        $headers = [
            'Accept' => 'application/json',
            'Referer' => 'http://localhost:3000/',
            'Origin' => 'http://localhost:3000',
        ];

        // withUnencryptedCookies() merges into state kept across calls; replace it.
        $this->unencryptedCookies = [];

        $response = $this->withCredentials()->withUnencryptedCookies($this->spaCookies)
            ->withHeaders($headers)
            ->json($method, $uri, $data);

        // Don't leak this browser's cookies into plain getJson()/postJson() calls.
        $this->withCredentials = false;
        $this->unencryptedCookies = [];

        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->isCleared()) {
                unset($this->spaCookies[$cookie->getName()]);
            } else {
                $this->spaCookies[$cookie->getName()] = (string) $cookie->getValue();
            }
        }

        return $response;
    }

    protected function spaCookie(string $name): ?string
    {
        return $this->spaCookies[$name] ?? null;
    }

    protected function forgetSpaCookies(): void
    {
        $this->spaCookies = [];
    }
}
