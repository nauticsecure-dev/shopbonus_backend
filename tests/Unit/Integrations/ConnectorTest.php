<?php

namespace Tests\Unit\Integrations;

use App\Enums\EcommercePlatform;
use App\Integrations\Connectors\GenericSignedWebhookConnector;
use App\Integrations\Connectors\ShopifyConnector;
use App\Integrations\Connectors\WooCommerceConnector;
use App\Integrations\Data\PaymentStatus;
use App\Integrations\Data\VerifiedEvent;
use App\Integrations\Exceptions\InvalidWebhook;
use App\Models\WebshopConnection;
use Illuminate\Http\Request;
use Tests\Concerns\SignsWebhooks;
use Tests\TestCase;

class ConnectorTest extends TestCase
{
    use SignsWebhooks;

    private function connection(array $credentials, ?string $shop = null): WebshopConnection
    {
        return new WebshopConnection(['credentials' => $credentials, 'external_shop_ref' => $shop]);
    }

    private function request(string $body, array $headers): Request
    {
        $server = [];
        foreach ($headers as $k => $v) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $k))] = $v;
        }

        return Request::create('/webhook', 'POST', [], [], [], $server, $body);
    }

    private function rejected(callable $fn, string $reason): void
    {
        try {
            $fn();
            $this->fail("Expected rejection: {$reason}");
        } catch (InvalidWebhook $e) {
            $this->assertSame($reason, $e->reason);
        }
    }

    // ---------------------------------------------------------------- WooCommerce

    public function test_woocommerce_signature_and_dedupe_key(): void
    {
        $c = new WooCommerceConnector;
        $conn = $this->connection(['webhook_secret' => 'woo-secret-1234567890']);
        $body = json_encode($this->wooOrder());

        $event = $c->verifyIncomingEvent($conn, $this->request($body, $this->wooHeaders($body, 'woo-secret-1234567890', 'abc-123')));
        $this->assertSame('delivery:abc-123', $event->dedupeKey);
        $this->assertSame(VerifiedEvent::KIND_ORDER, $event->kind);

        $this->rejected(fn () => $c->verifyIncomingEvent($conn, $this->request($body, $this->wooHeaders($body, 'wrong-secret'))), 'invalid_signature');
        $this->rejected(fn () => $c->verifyIncomingEvent($conn, $this->request($body.' ', $this->wooHeaders($body, 'woo-secret-1234567890'))), 'invalid_signature');
        $this->rejected(fn () => $c->verifyIncomingEvent($this->connection([]), $this->request($body, $this->wooHeaders($body, 'x'))), 'not_configured');
    }

    public function test_woocommerce_signed_ping_is_recognised(): void
    {
        $c = new WooCommerceConnector;
        $event = $c->verifyIncomingEvent($this->connection(['webhook_secret' => 's3cr3t-s3cr3t-s3cr3t']),
            $this->request('webhook_id=12', $this->wooHeaders('webhook_id=12', 's3cr3t-s3cr3t-s3cr3t', '', null)));

        $this->assertSame(VerifiedEvent::KIND_PING, $event->kind);
    }

    public function test_woocommerce_normalisation_and_payment_mapping(): void
    {
        $c = new WooCommerceConnector;

        $paid = $c->normalizeOrder($this->wooOrder());
        $this->assertTrue($paid->isComplete());
        $this->assertSame(5995, $paid->amountMinor);
        $this->assertSame('EUR', $paid->currency);
        $this->assertSame(PaymentStatus::Paid, $paid->paymentStatus);
        $this->assertSame('Anna', $paid->firstName);

        // "processing" without a payment date is not treated as paid.
        $this->assertSame(PaymentStatus::Pending, $c->normalizeOrder($this->wooOrder(['date_paid_gmt' => null]))->paymentStatus);
        $this->assertSame(PaymentStatus::Pending, $c->normalizeOrder($this->wooOrder(['status' => 'on-hold']))->paymentStatus);
        $this->assertSame(PaymentStatus::Cancelled, $c->normalizeOrder($this->wooOrder(['status' => 'cancelled']))->paymentStatus);
        $this->assertSame(PaymentStatus::Refunded, $c->normalizeOrder($this->wooOrder(['status' => 'refunded']))->paymentStatus);
        $partial = $c->normalizeOrder($this->wooOrder(['refunds' => [['id' => 1, 'total' => '-10.00']]]));
        $this->assertSame(PaymentStatus::PartiallyRefunded, $partial->paymentStatus);
        $this->assertSame(1000, $partial->refundedMinor);
    }

    public function test_card1_e2e4_missing_mandatory_fields_and_phone(): void
    {
        $c = new WooCommerceConnector;

        $noEmail = $c->normalizeOrder($this->wooOrder(['billing' => ['email' => '']]));
        $this->assertFalse($noEmail->isComplete());
        $this->assertSame(['email'], $noEmail->missingFields());

        // Missing phone keeps the order complete: valid but not callable.
        $noPhone = $c->normalizeOrder($this->wooOrder(['billing' => ['phone' => '']]));
        $this->assertTrue($noPhone->isComplete());
        $this->assertFalse($noPhone->hasPhone());

        // Unparseable or over-precise amounts are never guessed.
        $this->assertNull($c->normalizeOrder($this->wooOrder(['total' => '59.955']))->amountMinor);
        $this->assertContains('amount_minor', $c->normalizeOrder($this->wooOrder(['total' => 'abc']))->missingFields());
        $this->assertContains('currency', $c->normalizeOrder($this->wooOrder(['currency' => 'euro']))->missingFields());
    }

    // ---------------------------------------------------------------- Shopify

    public function test_shopify_signature_shop_and_topics(): void
    {
        $c = new ShopifyConnector;
        $conn = $this->connection(['signing_secret' => 'shpss_0123456789abcdef'], 'fietsen.myshopify.com');
        $body = json_encode($this->shopifyOrder());

        $event = $c->verifyIncomingEvent($conn, $this->request($body, $this->shopifyHeaders($body, 'shpss_0123456789abcdef', 'wid-9')));
        $this->assertSame('webhook:wid-9', $event->dedupeKey);
        $this->assertSame(VerifiedEvent::KIND_ORDER, $event->kind);

        $refund = $c->verifyIncomingEvent($conn, $this->request($body, $this->shopifyHeaders($body, 'shpss_0123456789abcdef', 'wid-10', 'refunds/create')));
        $this->assertSame(VerifiedEvent::KIND_REFUND, $refund->kind);

        $this->rejected(fn () => $c->verifyIncomingEvent($conn, $this->request($body,
            $this->shopifyHeaders($body, 'shpss_0123456789abcdef', 'wid-11', 'orders/paid', 'other.myshopify.com'))), 'shop_mismatch');
        $this->rejected(fn () => $c->verifyIncomingEvent($conn, $this->request($body, $this->shopifyHeaders($body, 'nope'))), 'invalid_signature');
    }

    public function test_card1_e2e3_shopify_redacted_customer_data_is_incomplete(): void
    {
        $c = new ShopifyConnector;

        $full = $c->normalizeOrder($this->shopifyOrder());
        $this->assertTrue($full->isComplete());
        $this->assertSame(PaymentStatus::Paid, $full->paymentStatus);
        $this->assertSame('#1001', $full->reference);
        $this->assertSame('+31612345678', $full->phone);

        $redacted = $c->normalizeOrder($this->shopifyOrder(['email' => null, 'customer' => ['first_name' => null, 'email' => null, 'phone' => null]]));
        $this->assertEqualsCanonicalizing(['first_name', 'email'], $redacted->missingFields());

        $this->assertSame(PaymentStatus::Pending, $c->normalizeOrder($this->shopifyOrder(['financial_status' => 'authorized']))->paymentStatus);
        $this->assertSame(PaymentStatus::Cancelled, $c->normalizeOrder($this->shopifyOrder(['cancelled_at' => '2026-10-23T13:00:00Z']))->paymentStatus);
        $this->assertSame(PaymentStatus::Refunded, $c->normalizeOrder($this->shopifyOrder(['financial_status' => 'refunded']))->paymentStatus);
    }

    // ---------------------------------------------------------------- Generic

    public function test_generic_signature_replay_window_and_rotation_overlap(): void
    {
        $c = new GenericSignedWebhookConnector(EcommercePlatform::PrestaShop);
        $conn = $this->connection(['signing_secret' => 'sb_secret_0123456789']);
        $body = json_encode($this->genericOrder());

        $event = $c->verifyIncomingEvent($conn, $this->request($body, $this->genericHeaders($body, 'sb_secret_0123456789', 'evt-1')));
        $this->assertSame('evt:evt-1', $event->dedupeKey);

        $this->rejected(fn () => $c->verifyIncomingEvent($conn, $this->request($body,
            $this->genericHeaders($body, 'sb_secret_0123456789', 'evt-2', 'order.paid', time() - 301))), 'timestamp_out_of_tolerance');
        $this->rejected(fn () => $c->verifyIncomingEvent($conn, $this->request($body,
            $this->genericHeaders($body, 'wrong_secret_012345', 'evt-3'))), 'invalid_signature');
        $this->rejected(fn () => $c->verifyIncomingEvent($conn, $this->request($body,
            ['X-Shopbonus-Signature' => 'garbage', 'X-Shopbonus-Event-Id' => 'e', 'X-Shopbonus-Topic' => 'order.paid'])), 'invalid_signature');
        $this->rejected(fn () => $c->verifyIncomingEvent($conn, $this->request($body,
            $this->genericHeaders($body, 'sb_secret_0123456789', 'evt-4', 'order.exploded'))), 'invalid_event_headers');

        // Several v1 signatures are allowed (sender signing with old and new secret during rotation).
        $t = time();
        $multi = ['X-Shopbonus-Signature' => "t={$t},v1=deadbeef,v1=".hash_hmac('sha256', $t.'.'.$body, 'sb_secret_0123456789'),
            'X-Shopbonus-Event-Id' => 'evt-5', 'X-Shopbonus-Topic' => 'order.paid'];
        $this->assertSame('evt:evt-5', $c->verifyIncomingEvent($conn, $this->request($body, $multi))->dedupeKey);
    }

    public function test_generic_normalisation_uses_integer_minor_units_only(): void
    {
        $c = new GenericSignedWebhookConnector;

        $order = $c->normalizeOrder($this->genericOrder());
        $this->assertTrue($order->isComplete());
        $this->assertSame(5995, $order->amountMinor);

        $this->assertNull($c->normalizeOrder($this->genericOrder(['order' => ['total_minor' => 59.95]]))->amountMinor);
        $this->assertNull($c->normalizeOrder($this->genericOrder(['order' => ['total_minor' => '5995']]))->amountMinor);
        $this->assertSame(PaymentStatus::Unknown, $c->normalizeOrder($this->genericOrder(['order' => ['payment_status' => 'kinda']]))->paymentStatus);
    }
}
