<?php

namespace Tests\Feature\Security;

use Illuminate\Http\Middleware\TrustProxies;
use Symfony\Component\HttpFoundation\Request;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    public function test_every_response_is_hardened_and_hsts_is_sent_only_over_https(): void
    {
        $plain = $this->getJson('/api/v1/health');
        $plain->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'DENY')->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeaderMissing('Strict-Transport-Security');
        $this->getJson('https://localhost/api/v1/health')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        // Error responses carry the same headers.
        $this->getJson('/api/v1/does-not-exist')->assertNotFound()->assertHeader('X-Frame-Options', 'DENY');

        config(['shopbonus.security.hsts_max_age' => 0]);
        $this->getJson('https://localhost/api/v1/health')->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_https_behind_a_trusted_proxy_is_detected(): void
    {
        // An untrusted client cannot claim HTTPS through a forwarded header.
        $this->withHeaders(['X-Forwarded-Proto' => 'https'])->getJson('/api/v1/health')->assertHeaderMissing('Strict-Transport-Security');
        TrustProxies::at('*');
        try {
            $this->withHeaders(['X-Forwarded-Proto' => 'https'])->getJson('/api/v1/health')->assertHeader('Strict-Transport-Security');
        } finally {
            TrustProxies::flushState();
            Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
        }
    }
}
