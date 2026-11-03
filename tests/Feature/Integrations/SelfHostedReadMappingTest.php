<?php

namespace Tests\Feature\Integrations;

use App\Integrations\Connectors\EcwidConnector;
use App\Integrations\Connectors\LightspeedCConnector;
use App\Integrations\Connectors\MagentoConnector;
use App\Integrations\Connectors\ShopwareConnector;
use App\Integrations\Data\PaymentStatus;
use Tests\TestCase;

class SelfHostedReadMappingTest extends TestCase
{
    public function test_native_lines_and_monetary_breakdowns_are_mapped_without_changing_payment_proof(): void
    {
        $shopware = (new ShopwareConnector)->normalizeOrder(['shopware_order' => [
            'id' => str_repeat('b', 32), 'currency' => ['isoCode' => 'EUR'], 'amountTotal' => '24.20', 'price' => ['taxStatus' => 'gross'],
            'shippingCosts' => ['totalPrice' => '4.20'],
            'lineItems' => [['id' => str_repeat('a', 32), 'orderId' => str_repeat('b', 32), 'type' => 'product', 'parentId' => null, 'quantity' => 2, 'totalPrice' => '20.00']],
        ]]);
        $this->assertSame(2000, $shopware->lineItems[0]['amount_minor']);
        $this->assertSame(420, $shopware->amountBreakdown['shipping_minor']);
        $this->assertNull($shopware->amountBreakdown['tax_minor']);
        $this->assertNull($shopware->refundedMinor);
        $this->assertNull($shopware->paidAt);

        $magento = (new MagentoConnector)->normalizeOrder(['magento_order' => [
            'entity_id' => 42, 'order_currency_code' => 'EUR', 'grand_total' => '24.20', 'tax_amount' => '4.20', 'discount_amount' => '-2.00',
            'items' => [['item_id' => 3, 'order_id' => 42, 'product_type' => 'simple', 'qty_ordered' => 1, 'row_total_incl_tax' => '20.00']],
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

    public function test_native_normalizers_expose_only_bound_flat_lines(): void
    {
        $id = str_repeat('a', 32);
        $payload = ['shopware_order' => ['id' => $id, 'currency' => ['isoCode' => 'EUR'], 'amountTotal' => '19.99', 'lineItems' => [['id' => str_repeat('b', 32), 'orderId' => $id, 'parentId' => null, 'type' => 'product', 'quantity' => 2, 'totalPrice' => '19.99']]]];
        $this->assertSame(1999, (new ShopwareConnector)->normalizeOrder($payload)->lineItems[0]['amount_minor']);
        $payload['shopware_order']['lineItems'][0]['orderId'] = str_repeat('c', 32);
        $this->assertNull((new ShopwareConnector)->normalizeOrder($payload)->lineItems);
        $payload = ['magento_order' => ['entity_id' => 42, 'order_currency_code' => 'EUR', 'grand_total' => '19.99', 'items' => [['item_id' => 1, 'order_id' => 42, 'product_type' => 'simple', 'qty_ordered' => '2.0000', 'row_total_incl_tax' => '19.99']]]];
        $this->assertSame(1999, (new MagentoConnector)->normalizeOrder($payload)->lineItems[0]['amount_minor']);
        $payload['magento_order']['items'][0]['order_id'] = 43;
        $this->assertNull((new MagentoConnector)->normalizeOrder($payload)->lineItems);
    }

    public function test_reported_ecwid_and_lightspeed_totals_do_not_invent_settlement_or_subtract_refunds(): void
    {
        $payload = ['shop_currency' => 'EUR', 'native_order' => ['internalId' => 42, 'total' => '20.00', 'paymentStatus' => 'PARTIALLY_REFUNDED', 'refundedAmount' => '5.00', 'tax' => '2.00', 'shippingOption' => ['shippingRate' => '3.00'], 'discount' => '4.00', 'couponDiscount' => '4.00', 'pricesIncludeTax' => true]];
        $o = (new EcwidConnector)->normalizeOrder($payload);
        $this->assertSame(2000, $o->amountMinor);
        $this->assertSame(500, $o->refundedMinor);
        $this->assertSame(2000, $o->amountBreakdown['current_total_minor']);
        $this->assertSame(400, $o->amountBreakdown['discount_minor']);
        $this->assertSame(300, $o->amountBreakdown['shipping_minor']);
        $this->assertNull($o->amountBreakdown['original_total_minor']);
        $this->assertNull($o->paidAt);
        $this->assertNull($o->refundedAt);
        $payload['native_order']['tax'] = '0.001';
        $this->assertNull((new EcwidConnector)->normalizeOrder($payload)->amountBreakdown['tax_minor']);
        $o = (new LightspeedCConnector)->normalizeOrder(['shop_currency' => 'EUR', 'order' => ['id' => 42, 'priceIncl' => '20.00', 'paymentStatus' => 'paid']]);
        $this->assertSame(2000, $o->amountBreakdown['original_total_minor']);
        $this->assertNull($o->amountBreakdown['tax_minor']);
        $this->assertNull($o->refundedMinor);
        $this->assertNull($o->paidAt);
    }
}
