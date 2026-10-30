<?php

namespace Tests\Feature\Integrations;

use App\Integrations\Connectors\LightspeedCConnector;
use App\Integrations\Data\PaymentStatus;
use App\Integrations\Exceptions\InvalidWebhook;
use App\Models\WebshopConnection;
use Illuminate\Http\Request;
use Tests\TestCase;

class LightspeedCConnectorTest extends TestCase
{
    private function orderPayload(string $paymentStatus = 'paid'): array
    {
        return [
            'order' => [
                'id' => 1623550,
                'number' => 'ORD-1001',
                'status' => $paymentStatus === 'cancelled' ? 'cancelled' : 'processing_awaiting_shipment',
                'createdAt' => '2026-10-28T09:00:00+00:00',
                'updatedAt' => '2026-10-28T10:00:00+00:00',
                'priceIncl' => '50.35',
                'email' => 'buyer@example.test',
                'firstname' => 'Lightspeed',
                'phone' => '+15555555555',
                'paymentStatus' => $paymentStatus,
            ],
            'shop_currency' => 'EUR',
        ];
    }

    public function test_unpaid_created_order_is_pending_and_paid_status_maps_without_invented_paid_at(): void
    {
        $a = new LightspeedCConnector;
        $pending = $a->normalizeOrder($this->orderPayload('not_paid'));
        $this->assertSame(PaymentStatus::Pending, $pending->paymentStatus);
        $this->assertSame(5035, $pending->amountMinor);
        $this->assertSame('EUR', $pending->currency);
        $this->assertNull($pending->paidAt);
        $this->assertNull($pending->refundedMinor);

        $paid = $a->normalizeOrder($this->orderPayload('paid'));
        $this->assertSame(PaymentStatus::Paid, $paid->paymentStatus);
        $this->assertNull($paid->paidAt);
        $this->assertSame('1623550', $paid->externalId);
        $this->assertSame('ORD-1001', $paid->reference);
    }

    public function test_missing_shop_currency_clears_money_instead_of_borrowing_unverified_values(): void
    {
        $a = new LightspeedCConnector;
        $p = $this->orderPayload('paid');
        unset($p['shop_currency']);
        $n = $a->normalizeOrder($p);
        $this->assertNull($n->amountMinor);
        $this->assertNull($n->currency);
        $this->assertSame(PaymentStatus::Paid, $n->paymentStatus);
    }

    public function test_app_webhook_signature_is_md5_of_raw_body_and_secret_and_rejects_forged_shop(): void
    {
        $c = new WebshopConnection([
            'external_shop_ref' => '100001',
            'credentials' => ['cluster' => 'eu1', 'language' => 'en', 'api_key' => 'SYNTHETIC_key_abcdefgh', 'api_secret' => 'SYNTHETIC_secret_abcdefgh'],
        ]);
        $raw = json_encode(['order' => ['id' => 1623550, 'paymentStatus' => 'not_paid']], JSON_THROW_ON_ERROR);
        $r = Request::create('/', 'POST', [], [], [], [], $raw);
        $r->headers->set('x-shop-id', '100001');
        $r->headers->set('x-cluster-id', 'eu1');
        $r->headers->set('x-signature', md5($raw.'SYNTHETIC_secret_abcdefgh'));
        $a = new LightspeedCConnector;
        $event = $a->verifyIncomingEvent($c, $r);
        $this->assertSame('order_lookup', $event->kind);
        $this->assertSame('orders/updated', $event->topic);
        $this->assertSame('1623550', $a->notificationOrderId($c, $raw));

        $r->headers->set('x-shop-id', '999999');
        $this->expectException(InvalidWebhook::class);
        $a->verifyIncomingEvent($c, $r);
    }
}
