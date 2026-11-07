<?php

namespace Tests\Feature\Integrations;

use App\Enums\EcommercePlatform;
use App\Http\Resources\ConnectionResource;
use App\Integrations\ConnectionManager;
use App\Models\User;
use App\Services\Banners\NativeBannerPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Concerns\CreatesActiveWebshops;
use Tests\TestCase;

class ProviderNoticeLocalizationTest extends TestCase
{
    use CreatesActiveWebshops, RefreshDatabase;

    public function test_notice_translation_keeps_dynamic_module_url_and_provider_identifiers(): void
    {
        $owner = User::factory()->create();
        $shop = $this->activeWebshop($owner);
        foreach ([EcommercePlatform::PrestaShop, EcommercePlatform::Shopware6, EcommercePlatform::Magento] as $platform) {
            [$c] = app(ConnectionManager::class)->create($shop->memberships()->where('user_id', $owner->id)->sole(), $platform, []);
            app()->setLocale('en');
            $en = (new ConnectionResource($c))->withSetup()->toArray(Request::create('/'))['setup']['native_notice'];
            app()->setLocale('nl');
            $nl = (new ConnectionResource($c))->withSetup()->toArray(Request::create('/'))['setup']['native_notice'];
            $this->assertNotSame($en, $nl);
            $this->assertStringNotContainsString('Configure ', $nl);
            if ($platform === EcommercePlatform::Magento) {
                $this->assertStringContainsString('/downloads/integrations/shopbonus-magento2-async.zip', $nl);
            }
        }
    }

    public function test_native_readiness_localizes_human_text_and_preserves_machine_states(): void
    {
        foreach ([EcommercePlatform::Ecwid, EcommercePlatform::LightspeedE, EcommercePlatform::Squarespace, EcommercePlatform::LightspeedC, EcommercePlatform::BigCommerce, EcommercePlatform::Other] as $platform) {
            app()->setLocale('en');
            $en = app(NativeBannerPublisher::class)->readiness($platform);
            app()->setLocale('nl');
            $nl = app(NativeBannerPublisher::class)->readiness($platform);
            $this->assertSame($en['state'], $nl['state']);
            $this->assertSame($en['automatic_publication'], $nl['automatic_publication']);
            if (isset($en['reason'])) {
                $this->assertNotSame($en['reason'], $nl['reason']);
            }
            if (isset($en['source'])) {
                $this->assertSame($en['source'], $nl['source']);
            }
        }
    }
}
