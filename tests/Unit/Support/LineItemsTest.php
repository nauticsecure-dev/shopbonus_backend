<?php

namespace Tests\Unit\Support;

use App\Integrations\LineItems;
use PHPUnit\Framework\TestCase;

class LineItemsTest extends TestCase
{
    public function test_native_composite_and_negative_rows_cannot_become_flat_product_snapshots(): void
    {
        $row = ['item_id' => 1, 'order_id' => 42, 'product_id' => 20, 'product_type' => 'simple', 'qty_ordered' => '2.000', 'row_total_incl_tax' => '19.99'];
        $this->assertSame(1999, LineItems::magento([$row], 'EUR', 42)[0]['amount_minor']);
        $this->assertNull(LineItems::magento([$row, [...$row, 'item_id' => 2, 'parent_item_id' => 1]], 'EUR', 42));
        $this->assertNull(LineItems::magento([[...$row, 'row_total_incl_tax' => '-1']], 'EUR', 42));
        $line = ['id' => str_repeat('a', 32), 'orderId' => str_repeat('b', 32), 'type' => 'product', 'parentId' => null, 'quantity' => 1, 'totalPrice' => '-2.00'];
        $this->assertNull(LineItems::shopware([$line], 'EUR', str_repeat('b', 32)));
        $this->assertSame([], LineItems::shopware([], 'EUR', str_repeat('b', 32)));
    }

    public function test_exact_currency_precision_and_unknown_eligibility(): void
    {
        $rows = [['id' => 1, 'product_id' => 55, 'sku' => 'SYNTHETIC-SKU', 'quantity' => 2, 'total' => '19.9900']];
        $v = LineItems::woo($rows, 'EUR');
        $this->assertSame(1999, $v[0]['amount_minor']);
        $this->assertNull($v[0]['eligible_amount_minor']);
        $this->assertSame(2, $v[0]['quantity']);
        $rows[0]['total'] = '19.999';
        $this->assertNull(LineItems::woo($rows, 'EUR'));
        $this->assertSame([], LineItems::woo([], 'EUR'));
        $this->assertNull(LineItems::woo(null, 'EUR'));
    }

    public function test_partial_graphql_connection_is_never_a_complete_snapshot(): void
    {
        $this->assertNull(LineItems::shopify(['nodes' => [], 'pageInfo' => ['hasNextPage' => true]], 'EUR'));
        $p = ['nodes' => [['id' => 'gid://shopify/LineItem/1', 'product' => ['id' => 'gid://shopify/Product/55'], 'sku' => 'SYNTHETIC', 'quantity' => 1, 'discountedTotalSet' => ['shopMoney' => ['amount' => '12.00', 'currencyCode' => 'EUR']]]], 'pageInfo' => ['hasNextPage' => false]];
        $this->assertSame(1200, LineItems::shopify($p, 'EUR')[0]['amount_minor']);
        $p['nodes'][0]['discountedTotalSet']['shopMoney']['currencyCode'] = 'USD';
        $this->assertNull(LineItems::shopify($p, 'EUR'));
    }

    public function test_native_flat_lines_are_exact_bound_and_never_infer_eligibility(): void
    {
        $order = str_repeat('a', 32);
        $row = ['id' => str_repeat('b', 32), 'orderId' => $order, 'parentId' => null, 'type' => 'product', 'quantity' => 2, 'totalPrice' => '19.99'];
        $lines = LineItems::shopware([$row], 'EUR', $order);
        $this->assertSame(1999, $lines[0]['amount_minor']);
        $this->assertNull($lines[0]['eligible_amount_minor']);
        $this->assertNull(LineItems::shopware([$row, $row], 'EUR', $order));
        foreach (['orderId' => str_repeat('c', 32), 'type' => 'promotion', 'parentId' => str_repeat('c', 32), 'totalPrice' => '19.999'] as $key => $value) {
            $this->assertNull(LineItems::shopware([array_replace($row, [$key => $value])], 'EUR', $order));
        }
        $this->assertNull(LineItems::shopware(array_fill(0, 101, $row), 'EUR', $order));
        $item = ['item_id' => 1, 'order_id' => 42, 'product_type' => 'simple', 'qty_ordered' => '2.0000', 'row_total_incl_tax' => '19.99'];
        $lines = LineItems::magento([$item], 'EUR', 42);
        $this->assertSame(1999, $lines[0]['amount_minor']);
        $this->assertSame(2, $lines[0]['quantity']);
        $this->assertNull($lines[0]['eligible_amount_minor']);
        $this->assertNull(LineItems::magento([$item, $item], 'EUR', 42));
        foreach (['order_id' => 43, 'product_type' => 'configurable', 'parent_item_id' => 2, 'qty_ordered' => '1.5', 'row_total_incl_tax' => '19.999'] as $key => $value) {
            $this->assertNull(LineItems::magento([array_replace($item, [$key => $value])], 'EUR', 42));
        }
        $this->assertNull(LineItems::magento(array_fill(0, 101, $item), 'EUR', 42));
    }
}
