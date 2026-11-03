<?php

namespace Tests\Feature\Integrations;

use App\Integrations\Connectors\MagentoConnector;
use App\Integrations\Connectors\ShopwareConnector;
use App\Integrations\Data\PaymentStatus;
use Tests\TestCase;

class SelfHostedReadMappingTest extends TestCase
{
    public function test_native_lines_and_monetary_breakdowns_are_mapped_without_changing_payment_proof(): void
    {
        $shopware = (new ShopwareConnector)->normalizeOrder(['shopware_order' => [
            'currency' => ['isoCode' => 'EUR'], 'amountTotal' => '24.20', 'price' => ['taxStatus' => 'gross'],
            'shippingCosts' => ['totalPrice' => '4.20'],
            'lineItems' => [['id' => str_repeat('a', 32), 'quantity' => 2, 'totalPrice' => '20.00']],
        ]]);
        $this->assertSame(2000, $shopware->lineItems[0]['amount_minor']);
        $this->assertSame(420, $shopware->amountBreakdown['shipping_minor']);
        $this->assertNull($shopware->amountBreakdown['tax_minor']);
        $this->assertNull($shopware->refundedMinor);
        $this->assertNull($shopware->paidAt);

        $magento = (new MagentoConnector)->normalizeOrder(['magento_order' => [
            'order_currency_code' => 'EUR', 'grand_total' => '24.20', 'tax_amount' => '4.20', 'discount_amount' => '-2.00',
            'items' => [['item_id' => 3, 'qty_ordered' => 1, 'row_total' => '20.00']],
        ]]);
        $this->assertSame(2000, $magento->lineItems[0]['amount_minor']);
        $this->assertSame(420, $magento->amountBreakdown['tax_minor']);
        $this->assertSame(200, $magento->amountBreakdown['discount_minor']);
        $this->assertNull($magento->amountBreakdown['shipping_minor']);
        $this->assertNull($magento->paidAt);
    }

    public function test_shopware_paid_state_needs_bound_history_not_created_or_updated_time(): void
    {
        $id = str_repeat('a', 32);
        $p = ['shopware_order' => ['id' => str_repeat('b', 32), 'currency' => ['isoCode' => 'EUR'], 'amountTotal' => '25.00', 'transactions' => [['id' => $id, 'stateMachineState' => ['technicalName' => 'paid'], 'amount' => ['totalPrice' => '25.00']]]], 'paid_state_history' => []];
        $a = new ShopwareConnector;
        $this->assertSame(PaymentStatus::Pending, $a->normalizeOrder($p)->paymentStatus);
        $p['paid_state_history'] = [['referencedId' => $id, 'toStateMachineState' => ['technicalName' => 'paid'], 'createdAt' => '2026-10-28T10:00:00Z']];
        $this->assertSame(PaymentStatus::Paid, $a->normalizeOrder($p)->paymentStatus);
        $p['shopware_order']['transactions'][0]['stateMachineState']['technicalName'] = 'refunded_partially';
        $this->assertSame(PaymentStatus::Unknown, $a->normalizeOrder($p)->paymentStatus);
        $this->assertNull($a->normalizeOrder($p)->refundedMinor);
    }

    public function test_magento_capture_and_paid_invoice_are_required_and_authorization_alone_is_not_payment(): void
    {
        $p = ['magento_order' => ['entity_id' => 4711, 'order_currency_code' => 'EUR', 'grand_total' => '25.00', 'total_paid' => '25.00', 'total_refunded' => 0, 'state' => 'processing'], 'invoices' => [['entity_id' => 1, 'order_currency_code' => 'EUR', 'state' => 2, 'grand_total' => '25.00']], 'creditmemos' => [], 'transactions' => [['transaction_id' => 1, 'txn_type' => 'authorization', 'created_at' => '2026-10-28 10:00:00']]];
        $a = new MagentoConnector;
        $this->assertSame(PaymentStatus::Pending, $a->normalizeOrder($p)->paymentStatus);
        $p['transactions'][0]['txn_type'] = 'capture';
        $o = $a->normalizeOrder($p);
        $this->assertSame(PaymentStatus::Paid, $o->paymentStatus);
        $this->assertSame(2500, $o->amountMinor);
        $this->assertSame('2026-10-28T10:00:00+00:00', $o->paidAt->toIso8601String());
        $p['invoices'][0]['state'] = 1;
        $this->assertSame(PaymentStatus::Pending, $a->normalizeOrder($p)->paymentStatus);
    }
}
