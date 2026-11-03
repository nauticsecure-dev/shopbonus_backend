<?php

namespace Tests\Unit\Support;

use App\Integrations\LineItems;
use PHPUnit\Framework\TestCase;

class LineItemsTest extends TestCase
{
    public function test_native_snapshots_preserve_parentage_precision_and_unknowns(): void
    {
        $row = ['item_id' => 1, 'product_id' => 20, 'qty_ordered' => '2.000', 'row_total' => '19.99'];
        $rows = [$row, [...$row, 'item_id' => 2, 'parent_item_id' => 1, 'row_total' => '0']];
        $mapped = LineItems::magento($rows, 'EUR');
        $this->assertSame(1999, $mapped[0]['amount_minor']);
        $this->assertSame('1', $mapped[1]['parent_id']);
        $this->assertNull($mapped[0]['eligible_amount_minor']);
        $this->assertNull(LineItems::magento([[$row][0], $row], 'EUR'));
        $this->assertNull(LineItems::magento([[...$row, 'qty_ordered' => '1.5']], 'EUR'));
        $this->assertNull(LineItems::magento([[...$row, 'row_total' => '1.001']], 'EUR'));
        $this->assertNull(LineItems::magento(null, 'EUR'));
        $this->assertSame([], LineItems::magento([], 'EUR'));

        $line = ['id' => str_repeat('a', 32), 'quantity' => 1, 'totalPrice' => '-2.00'];
        $mapped = LineItems::shopware([$line], 'EUR', 'gross');
        $this->assertSame(-200, $mapped[0]['amount_minor']);
        $this->assertSame('provider_total_inc_tax', $mapped[0]['amount_basis']);
        $this->assertNull($mapped[0]['eligible_amount_minor']);
        $this->assertNull(LineItems::shopware([$line, $line], 'EUR', 'gross'));
        $this->assertNull(LineItems::shopware(array_fill(0, 101, $line), 'EUR', 'gross'));
        $this->assertNull(LineItems::shopware(null, 'EUR', null));
        $this->assertSame([], LineItems::shopware([], 'EUR', null));
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
}
