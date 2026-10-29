<?php

namespace Tests\Feature\Integrations;

use App\Enums\EcommercePlatform;
use App\Integrations\Connectors\WixConnector;
use App\Integrations\Data\PaymentStatus;
use App\Integrations\Exceptions\InvalidWebhook;
use App\Integrations\ProviderOrderId;
use App\Models\WebshopConnection;
use Illuminate\Http\Request;
use Tests\TestCase;

class WixConnectorTest extends TestCase
{
    private function encoded(array $v): string
    {
        return rtrim(strtr(base64_encode(json_encode($v)), '+/', '-_'), '=');
    }

    public function test_rsa_signed_notification_requires_fixed_algorithm_and_bound_instance(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $info = openssl_pkey_get_details($key);
        $instance = '11111111-1111-4111-8111-111111111111';
        $c = new WebshopConnection(['external_shop_ref' => $instance, 'credentials' => ['public_key' => $info['key']]]);
        $event = ['id' => '22222222-2222-4222-8222-222222222222', 'entityFqdn' => 'wix.ecom.v1.order', 'entityId' => '33333333-3333-4333-8333-333333333333', 'slug' => 'created'];
        // Real Wix envelope (as parsed by @wix/sdk): `data` is a JSON string whose own `data` is a JSON string.
        $body = $this->encoded(['data' => json_encode(['instanceId' => $instance, 'eventType' => 'wix.ecom.v1.order_created', 'data' => json_encode($event)])]);
        $header = $this->encoded(['alg' => 'RS256', 'typ' => 'JWT']);
        $message = $header.'.'.$body;
        openssl_sign($message, $signature, $key, OPENSSL_ALGO_SHA256);
        $raw = $message.'.'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
        $r = Request::create('/', 'POST', [], [], [], [], $raw);
        $a = new WixConnector;
        $this->assertSame('order_lookup', $a->verifyIncomingEvent($c, $r)->kind);
        $this->assertSame($event['entityId'], $a->notificationOrderId($c, $raw));
        // A claim object instead of the JSON string is not the Wix format.
        $objectBody = $this->encoded(['data' => ['instanceId' => $instance, 'eventType' => 'wix.ecom.v1.order_created', 'data' => json_encode($event)]]);
        openssl_sign($header.'.'.$objectBody, $objectSig, $key, OPENSSL_ALGO_SHA256);
        try {
            $a->verifyIncomingEvent($c, Request::create('/', 'POST', [], [], [], [], $header.'.'.$objectBody.'.'.rtrim(strtr(base64_encode($objectSig), '+/', '-_'), '=')));
            $this->fail('Object data claim accepted.');
        } catch (InvalidWebhook $e) {
            $this->assertSame('invalid_event', $e->reason);
        }
        $bad = Request::create('/', 'POST', [], [], [], [], $this->encoded(['alg' => 'HS256']).'.'.$body.'.'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '='));
        $this->expectException(InvalidWebhook::class);
        $a->verifyIncomingEvent($c, $bad);
    }

    public function test_order_paid_without_recorded_payment_is_pending_and_actual_approved_record_has_exact_minor_amount(): void
    {
        $id = '33333333-3333-4333-8333-333333333333';
        $p = ['order' => ['id' => $id, 'currency' => 'EUR', 'number' => '1042', 'paymentStatus' => 'PAID', 'priceSummary' => ['total' => ['amount' => '17.99']]], 'orderTransactions' => ['payments' => [], 'refunds' => []]];
        $a = new WixConnector;
        $this->assertSame(PaymentStatus::Pending, $a->normalizeOrder($p)->paymentStatus);
        $p['orderTransactions']['payments'][] = ['id' => 'p1', 'status' => 'APPROVED', 'regularPaymentDetails' => ['offlinePayment' => false], 'amount' => ['amount' => '17.99'], 'createdDate' => '2026-10-28T10:00:00Z'];
        $o = $a->normalizeOrder($p);
        $this->assertSame(1799, $o->amountMinor);
        $this->assertSame(PaymentStatus::Paid, $o->paymentStatus);
        $this->assertNull($o->paidAt);
        $this->assertSame($id, ProviderOrderId::validate(EcommercePlatform::Wix, $id));
    }
}
