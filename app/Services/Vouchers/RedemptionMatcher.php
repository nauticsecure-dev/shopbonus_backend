<?php

namespace App\Services\Vouchers;

use App\Enums\VoucherStatus;
use App\Exceptions\ApiException;
use App\Integrations\Data\PaymentStatus;
use App\Models\Voucher;
use App\Models\VoucherCode;
use App\Models\WebshopOrder;

/**
 * Verified redemption (Trello #7 §8 item 4): an issued voucher counts as
 * redeemed only when its code appears in a verified, paid order of the
 * same webshop. Issuance alone is never reported as redemption.
 */
class RedemptionMatcher
{
    public function __construct(private readonly VoucherInventory $inventory) {}

    /**
     * @param  list<string>  $couponCodes
     * @return int vouchers redeemed by this order
     */
    public function match(WebshopOrder $order, array $couponCodes): int
    {
        if ($order->payment_status !== PaymentStatus::Paid || $couponCodes === []) {
            return 0;
        }

        $hashes = array_map(fn (string $c) => VoucherCode::hashFor($order->webshop_id, $c), $couponCodes);
        $codeIds = VoucherCode::where('webshop_id', $order->webshop_id)->whereIn('code_hash', $hashes)->pluck('id');

        $redeemed = 0;
        $vouchers = Voucher::whereIn('voucher_code_id', $codeIds)->where('status', VoucherStatus::Issued)->orderBy('issued_at')->get();
        foreach ($vouchers as $voucher) {
            try {
                $this->inventory->redeem($voucher, $order);
                $redeemed++;
            } catch (ApiException) {
                // Already redeemed by a concurrent/replayed event: idempotent.
            }
        }

        return $redeemed;
    }
}
