<?php

namespace Tests\Unit\Integrations;

use App\Enums\EcommercePlatform;
use App\Integrations\Connectors\GenericSignedWebhookConnector;
use App\Integrations\Connectors\PrestaShopConnector;
use App\Integrations\Data\PaymentStatus;
use App\Integrations\Data\VerifiedEvent;
use App\Integrations\Exceptions\InvalidWebhook;
use App\Models\WebshopConnection;
use Illuminate\Http\Request;
use Tests\TestCase;

class MerchantPackageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once base_path('merchant-packages/common/Delivery.php');
        require_once base_path('merchant-packages/opencart4/system/library/OrderPayload.php');
    }

    private function request(string $body, string $secret): Request
    {
        $request = Request::create('/', 'POST', [], [], [], [], $body);
        foreach (\ShopbonusDelivery::headers($body, $secret, str_repeat('a', 32), time()) as $header) {
            [$name, $value] = explode(': ', $header, 2);
            $request->headers->set($name, $value);
        }

        return $request;
    }

    public function test_packaged_signer_round_trips_to_native_prestashop_lookup_without_trusting_order_claims(): void
    {
        $connection = new WebshopConnection;
        $connection->credentials = ['signing_secret' => str_repeat('s', 48), 'shop_id' => '2'];
        $body = json_encode(['shopbonus_lookup_v1' => true, 'shop_id' => '2', 'order_id' => '4711', 'order' => ['payment_status' => 'paid']]);
        $connector = new PrestaShopConnector;
        $event = $connector->verifyIncomingEvent($connection, $this->request($body, str_repeat('s', 48)));
        $this->assertSame(VerifiedEvent::KIND_ORDER_LOOKUP, $event->kind);
        $this->assertSame('4711', $connector->notificationOrderId($connection, $body));
        $this->assertNull($connector->notificationOrderId($connection, str_replace('"2"', '"3"', $body)));
        $request = $this->request($body.' ', str_repeat('s', 48));
        $request->headers->set('X-Shopbonus-Signature', $this->request($body, str_repeat('s', 48))->header('X-Shopbonus-Signature'));
        $this->expectException(InvalidWebhook::class);
        $connector->verifyIncomingEvent($connection, $request);
    }

    public function test_signed_invalid_lookup_ids_and_ping_cannot_request_native_order_reads(): void
    {
        $connection = new WebshopConnection;
        $connection->credentials = ['signing_secret' => str_repeat('s', 48), 'shop_id' => '2'];
        $connector = new PrestaShopConnector;
        foreach (['../1', '0', '1?other=2', '9999999999999999999999'] as $id) {
            $body = json_encode(['shopbonus_lookup_v1' => true, 'shop_id' => '2', 'order_id' => $id]);
            $this->assertNull($connector->notificationOrderId($connection, $body));
            try {
                $connector->verifyIncomingEvent($connection, $this->request($body, str_repeat('s', 48)));
                $this->fail('Invalid lookup must not pass verification.');
            } catch (InvalidWebhook) {
                $this->assertTrue(true);
            }
        }
        $body = json_encode(['shopbonus_lookup_v1' => true, 'shop_id' => '2', 'order_id' => '4711']);
        $request = $this->request($body, str_repeat('s', 48));
        $request->headers->set('X-Shopbonus-Topic', 'ping');
        $this->expectException(InvalidWebhook::class);
        $connector->verifyIncomingEvent($connection, $request);
    }

    public function test_opencart_package_preserves_unknown_payment_and_refuses_currency_relabelling(): void
    {
        $native = ['order_id' => '7', 'firstname' => 'Synthetic', 'email' => 'buyer@example.test', 'currency_value' => '1.00000000', 'currency_code' => 'EUR', 'total' => '12.3400', 'order_status_id' => 5, 'date_added' => '2026-11-01 12:00:00', 'date_modified' => '2026-11-01 13:00:00'];
        $payload = \ShopbonusOpenCartOrder::payload($native, 'Europe/Amsterdam');
        $connector = new GenericSignedWebhookConnector(EcommercePlatform::OpenCart);
        $order = $connector->normalizeOrder($payload);
        $this->assertSame(1234, $order->amountMinor);
        $this->assertTrue($order->isComplete());
        $this->assertSame(PaymentStatus::Unknown, $order->paymentStatus);
        $this->assertNull($order->paidAt);
        $this->assertNull($order->refundedMinor);
        foreach ([['currency_value' => '1.2'], ['currency_code' => 'JPY'], ['total' => '12.3450']] as $change) {
            $this->assertNull($connector->normalizeOrder(\ShopbonusOpenCartOrder::payload(array_replace($native, $change), 'Europe/Amsterdam'))->amountMinor);
        }
        $native['date_added'] = '2026-10-25 02:30:00';
        $this->assertNull($connector->normalizeOrder(\ShopbonusOpenCartOrder::payload($native, 'Europe/Amsterdam'))->placedAt);
    }

    public function test_delivery_configuration_rejects_credential_and_redirect_destinations(): void
    {
        $base = 'https://api.example.com/api/v1/webhooks/prestashop/'.str_repeat('x', 40);
        $this->assertTrue(\ShopbonusDelivery::endpoint($base, 'prestashop'));
        foreach (['http://api.example.com', 'https://user:pass@api.example.com', 'https://api.example.com:8443', 'https://127.0.0.1'] as $origin) {
            $this->assertFalse(\ShopbonusDelivery::endpoint($origin.'/api/v1/webhooks/prestashop/'.str_repeat('x', 40), 'prestashop'));
        }
        $this->assertFalse(\ShopbonusDelivery::endpoint($base.'?redirect=https://example.com', 'prestashop'));
        $this->assertFalse(\ShopbonusDelivery::endpoint($base, 'opencart'));
    }

    public function test_shipped_archives_match_the_manifest_and_platform_entrypoints(): void
    {
        $manifest = json_decode(file_get_contents(public_path('downloads/integrations/manifest.json')), true, flags: JSON_THROW_ON_ERROR);
        $directories = ['shopbonus-adobe-appbuilder-source.zip' => 'adobe-appbuilder', 'shopbonus-magento2-async.zip' => 'magento2', 'shopbonus-prestashop9.zip' => 'prestashop', 'shopbonus.ocmod.zip' => 'opencart4',
            'shopbonus-woocommerce-banner.zip' => 'woocommerce', 'shopbonus-shopify-theme-source.zip' => 'shopify'];
        foreach (['shopbonus-adobe-appbuilder-source.zip' => 'app.config.yaml', 'shopbonus-magento2-async.zip' => 'registration.php', 'shopbonus-prestashop9.zip' => 'shopbonus/shopbonus.php', 'shopbonus.ocmod.zip' => 'install.json',
            'shopbonus-woocommerce-banner.zip' => 'shopbonus-banner/shopbonus-banner.php',
            'shopbonus-shopify-theme-source.zip' => 'extensions/shopbonus-banner/shopify.extension.toml'] as $filename => $entrypoint) {
            $path = public_path('downloads/integrations/'.$filename);
            $this->assertSame($manifest[$filename]['sha256'], hash_file('sha256', $path));
            $this->assertFalse($manifest[$filename]['live_verified']);
            $this->assertSame($manifest[$filename]['bytes'], filesize($path));
            $zip = new \ZipArchive;
            $this->assertTrue($zip->open($path));
            $this->assertNotFalse($zip->locateName($entrypoint));
            $this->assertNotFalse($zip->locateName('INSTALL.md'));
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $this->assertDoesNotMatchRegularExpression('~(?:^|/)(?:\.env|\.git|config\.php|config\.inc\.php)(?:$|/)~', $zip->getNameIndex($index));
                $name = $zip->getNameIndex($index);
                $source = match ($name) {
                    'INSTALL.md' => base_path('merchant-packages/INSTALL.md'),
                    'shopbonus/Delivery.php', 'system/library/Delivery.php', 'Shared/Delivery.php' => base_path('merchant-packages/common/Delivery.php'),
                    default => base_path('merchant-packages/'.$directories[$filename].'/'.$name),
                };
                $this->assertFileExists($source);
                $this->assertSame(file_get_contents($source), $zip->getFromIndex($index), $filename.': '.$name.' differs from reviewed source; rebuild packages.');
            }
            $zip->close();
        }
    }
}
