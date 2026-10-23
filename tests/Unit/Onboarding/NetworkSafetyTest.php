<?php

namespace Tests\Unit\Onboarding;

use App\Exceptions\ApiException;
use App\Services\Onboarding\DomainName;
use App\Services\Onboarding\Network\PublicAddressGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NetworkSafetyTest extends TestCase
{
    /** @return list<array{string}> */
    public static function blockedAddresses(): array
    {
        return [['127.0.0.1'], ['10.1.2.3'], ['172.16.0.9'], ['192.168.1.1'], ['169.254.169.254'], ['0.0.0.0'],
            ['100.64.1.1'], ['192.0.2.10'], ['198.51.100.7'], ['203.0.113.5'], ['224.0.0.1'], ['255.255.255.255'],
            ['::1'], ['fe80::1'], ['fc00::1'], ['fd12:3456::1'], ['ff02::1'], ['2001:db8::1'], ['::ffff:127.0.0.1'], ['::ffff:10.0.0.1'], ['not-an-ip']];
    }

    #[DataProvider('blockedAddresses')]
    public function test_non_public_addresses_are_refused(string $ip): void
    {
        $this->assertFalse(PublicAddressGuard::isPublic($ip));
    }

    public function test_public_addresses_are_allowed(): void
    {
        foreach (['93.184.216.34', '8.8.8.8', '2606:4700:4700::1111', '::ffff:93.184.216.34'] as $ip) {
            $this->assertTrue(PublicAddressGuard::isPublic($ip), $ip);
        }
    }

    public function test_all_addresses_must_be_public_and_none_is_not_enough(): void
    {
        $this->assertFalse(PublicAddressGuard::allPublic([]));
        $this->assertFalse(PublicAddressGuard::allPublic(['8.8.8.8', '10.0.0.1']));
        $this->assertTrue(PublicAddressGuard::allPublic(['8.8.8.8', '1.1.1.1']));
    }

    public function test_domain_normalisation(): void
    {
        $this->assertSame('fietsen.nl', DomainName::fromInput('https://www.Fietsen.NL/shop?x=1'));
        $this->assertSame('shop.fietsen.nl', DomainName::fromInput('shop.fietsen.nl:443/path'));
        $this->assertSame('xn--fietsen--01a.nl', DomainName::fromInput('fietsen-ë.nl'));
    }

    /** @return list<array{string}> */
    public static function invalidDomains(): array
    {
        return [['http://127.0.0.1'], ['localhost'], ['https://[::1]/'], ['intranet'], ['shop.local'], ['router.lan'],
            ['-bad-.nl'], ['bad_label.nl'], ['http://user@evil'], ['example.123'], ['']];
    }

    #[DataProvider('invalidDomains')]
    public function test_non_public_domains_are_rejected(string $input): void
    {
        $this->expectException(ApiException::class);
        DomainName::fromInput($input);
    }
}
