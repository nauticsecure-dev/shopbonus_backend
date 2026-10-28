<?php

namespace Tests\Feature\Integrations;

use App\Integrations\Connectors\PrestaShopConnector;
use App\Integrations\Data\PaymentStatus;
use Tests\TestCase;

class PrestaShopConnectorTest extends TestCase
{
    private function payload(): array
    {
        return ['presta_order' => ['id' => 4711, 'id_currency' => 1, 'reference' => 'ABCD12345', 'valid' => 1, 'current_state' => 2, 'total_paid_tax_incl' => '25.00', 'date_add' => '2026-10-28 10:00:00', 'date_upd' => '2026-10-28 11:00:00'], 'currency' => ['iso_code' => 'EUR'], 'customer' => ['firstname' => 'Synthetic', 'email' => 'synthetic@example.test'], 'address' => [], 'payments' => [['id' => 1, 'id_currency' => 1, 'order_reference' => 'ABCD12345', 'amount' => '25.00', 'date_add' => '2026-10-28 10:10:00']], 'credit_slips' => [], 'store_timezone' => 'Europe/Amsterdam'];
    }

    public function test_valid_state_is_not_payment_and_native_payment_records_have_explicit_timezone(): void
    {
        $p = $this->payload();
        $a = new PrestaShopConnector;
        $o = $a->normalizeOrder($p);
        $this->assertSame(PaymentStatus::Paid, $o->paymentStatus);
        $this->assertSame('2026-10-28T09:10:00+00:00', $o->paidAt->toIso8601String());
        $p['payments'] = [];
        $this->assertSame(PaymentStatus::Pending, $a->normalizeOrder($p)->paymentStatus);
    }

    public function test_credit_document_is_not_cash_refund_and_mismatched_currency_or_reference_fails_closed(): void
    {
        $p = $this->payload();
        $a = new PrestaShopConnector;
        $p['credit_slips'] = [['id' => 1]];
        $this->assertSame(PaymentStatus::Unknown, $a->normalizeOrder($p)->paymentStatus);
        $this->assertNull($a->normalizeOrder($p)->refundedMinor);
        $p = $this->payload();
        $p['payments'][0]['id_currency'] = 2;
        $this->assertNull($a->normalizeOrder($p)->amountMinor);
    }

    public function test_ambiguous_autumn_payment_time_is_not_assigned_an_invented_offset(): void
    {
        $p = $this->payload();
        $p['payments'][0]['date_add'] = '2026-10-25 02:30:00';
        $o = (new PrestaShopConnector)->normalizeOrder($p);
        $this->assertNull($o->paidAt);
        $this->assertSame(PaymentStatus::Unknown, $o->paymentStatus);
    }
}
