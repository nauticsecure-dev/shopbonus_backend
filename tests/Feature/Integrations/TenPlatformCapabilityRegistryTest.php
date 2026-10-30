<?php

namespace Tests\Feature\Integrations;

use App\Enums\EcommercePlatform;
use App\Integrations\ConnectorRegistry;
use Tests\TestCase;

/** Ensures the ten priority platforms expose a complete capability registry profile. */
class TenPlatformCapabilityRegistryTest extends TestCase
{
    private const PRIORITY = [
        EcommercePlatform::Shopify,
        EcommercePlatform::WooCommerce,
        EcommercePlatform::Wix,
        EcommercePlatform::BigCommerce,
        EcommercePlatform::Ecwid,
        EcommercePlatform::LightspeedC,
        EcommercePlatform::PrestaShop,
        EcommercePlatform::OpenCart,
        EcommercePlatform::Shopware6,
        EcommercePlatform::Magento,
    ];

    private const PROFILE_KEYS = [
        'api_version', 'reviewed_at', 'last_live_verified_at', 'native_read_api_implemented',
        'auth_method', 'scopes', 'webhook_topics', 'order_api', 'refund_api', 'product_api',
        'coupon_api', 'banner_method', 'rate_limit_policy', 'sources',
    ];

    public function test_ten_priority_platforms_have_complete_profiles_and_honest_availability(): void
    {
        $registry = app(ConnectorRegistry::class);
        foreach (self::PRIORITY as $platform) {
            $entry = $registry->entry($platform);
            $this->assertNotNull($entry['connector'], $platform->value.' must register a connector');
            $profile = $entry['profile'] ?? null;
            $this->assertIsArray($profile, $platform->value.' missing profile');
            foreach (self::PROFILE_KEYS as $key) {
                $this->assertArrayHasKey($key, $profile, $platform->value.' missing profile.'.$key);
            }
            $this->assertSame('2026-10-30', $profile['reviewed_at']);
            $this->assertNull($profile['last_live_verified_at']);
            $this->assertSame('unverified', $entry['verification']);
            $this->assertIsArray($profile['sources']);
            $this->assertNotEmpty($profile['sources']);
            foreach ($profile['sources'] as $url) {
                $this->assertIsString($url);
                $this->assertMatchesRegularExpression('/\Ahttps:\/\//', $url);
            }
        }

        $this->assertTrue($registry->isAvailable(EcommercePlatform::LightspeedC));
        $this->assertFalse($registry->entry(EcommercePlatform::OpenCart)['profile']['native_read_api_implemented']);
        $this->assertFalse($registry->isAvailable(EcommercePlatform::LightspeedE));
    }
}
