<?php

namespace Tests\Feature\Integrations;

use App\Integrations\Connectors\BigCommerceConnector;
use App\Integrations\Data\PaymentStatus;
use App\Integrations\Exceptions\InvalidWebhook;
use App\Models\WebshopConnection;
use Illuminate\Http\Request;
use Tests\TestCase;

class BigCommerceConnectorTest extends TestCase
{
    private function payload(): array
    {
        return ['order' => ['id' => 250, 'currency_code' => 'EUR', 'default_currency_code' => 'EUR', 'total_inc_tax' => '25.0000', 'refunded_amount' => '0.0000', 'payment_status' => 'captured', 'status' => 'Completed', 'date_created' => '2026-10-28T09:00:00Z', 'date_modified' => '2026-10-28T10:00:00Z'], 'transactions' => [['id' => 1, 'order_id' => '250', 'event' => 'capture', 'status' => 'ok', 'test' => false, 'fraud_review' => false, 'currency' => 'EUR', 'amount' => '25.00', 'date_created' => '2026-10-28T09:10:00Z']], 'refunds' => []];
    }

    public function test_only_actual_successful_non_test_captures_prove_full_payment(): void
    {
        $a = new BigCommerceConnector;
        $p = $this->payload();
        $this->assertSame(PaymentStatus::Paid, $a->normalizeOrder($p)->paymentStatus);
        $p['transactions'][0]['event'] = 'authorization';
        $this->assertSame(PaymentStatus::Pending, $a->normalizeOrder($p)->paymentStatus);
        $p['transactions'][0]['event'] = 'capture';
        $p['transactions'][0]['test'] = true;
        $this->assertSame(PaymentStatus::Pending, $a->normalizeOrder($p)->paymentStatus);
    }

    public function test_display_currency_mismatch_and_duplicate_transactions_cannot_create_payment(): void
    {
        $a = new BigCommerceConnector;
        $p = $this->payload();
        $p['order']['currency_code'] = 'USD';
        $this->assertNull($a->normalizeOrder($p)->amountMinor);
        $p = $this->payload();
        $p['transactions'][] = $p['transactions'][0];
        $this->assertSame(PaymentStatus::Unknown, $a->normalizeOrder($p)->paymentStatus);
    }

    public function test_standard_signature_covers_raw_body_timestamp_and_id_and_refund_targets_order(): void
    {
        $c = new WebshopConnection(['external_shop_ref' => 'abc123', 'credentials' => ['client_secret' => 'SYNTHETIC_bigcommerce_secret']]);
        $p = ['producer' => 'stores/abc123', 'scope' => 'store/order/refund/created', 'data' => ['type' => 'order', 'id' => 250, 'refund' => ['refund_id' => 99]]];
        $raw = json_encode($p);
        $time = (string) now()->getTimestamp();
        $r = Request::create('/', 'POST', [], [], [], [], $raw);
        $r->headers->set('webhook-id', 'msg_SYNTHETIC');
        $r->headers->set('webhook-timestamp', $time);
        $r->headers->set('webhook-signature', 'v1,'.base64_encode(hash_hmac('sha256', 'msg_SYNTHETIC.'.$time.'.'.$raw, 'SYNTHETIC_bigcommerce_secret', true)));
        $a = new BigCommerceConnector;
        $this->assertSame('order_lookup', $a->verifyIncomingEvent($c, $r)->kind);
        $this->assertSame('250', $a->notificationOrderId($c, $raw));
        $r->headers->set('webhook-timestamp', (string) (now()->getTimestamp() - 301));
        $this->expectException(InvalidWebhook::class);
        $a->verifyIncomingEvent($c, $r);
    }
}
