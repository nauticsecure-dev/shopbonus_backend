<?php

namespace Tests\Unit\Support;

use App\Integrations\LineItems;
use PHPUnit\Framework\TestCase;

class LineItemsTest extends TestCase
{
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
