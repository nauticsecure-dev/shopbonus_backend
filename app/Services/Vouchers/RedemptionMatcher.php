<?php

namespace App\Services\Vouchers;

use App\Enums\VoucherStatus;
use App\Exceptions\ApiException;
use App\Integrations\Data\PaymentStatus;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherCode;
use App\Models\WebshopOrder;
use Illuminate\Support\Collection;

/**
 * Verified redemption (Trello #7 §8 item 4): an issued voucher counts as
 * redeemed only when its code appears in a verified, paid order of the
 * same webshop. Issuance alone is never reported as redemption. A unique
 * code redeems its voucher; a reusable code redeems only the buyer's own.
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
        $codes = VoucherCode::where('webshop_id', $order->webshop_id)->whereIn('code_hash', $hashes)->get(['id', 'reusable'])->keyBy('id');

        $redeemed = 0;
        $byCode = Voucher::whereIn('voucher_code_id', $codes->keys())->where('status', VoucherStatus::Issued)->orderBy('issued_at')->orderBy('id')->get()->groupBy('voucher_code_id');
        foreach ($byCode as $codeId => $vouchers) {
            $voucher = $codes[$codeId]->reusable ? $this->buyersVoucher($vouchers, $order) : $vouchers->first();
            if ($voucher === null) {
                continue;
            }
            try {
                $this->inventory->redeem($voucher, $order);
                $redeemed++;
            } catch (ApiException) {
                // Already redeemed by a concurrent/replayed event, or expired before the order: not redeemed here.
            }
        }

        return $redeemed;
    }

    /**
     * A shared code proves nothing about who bought: one order redeems at most
     * the earliest issued voucher of the consumer whose email matches the order.
     *
     * @param  Collection<int, Voucher>  $vouchers
     */
    private function buyersVoucher(Collection $vouchers, WebshopOrder $order): ?Voucher
    {
        if ($order->customer_email_hash === null) {
            return null;
        }
        $buyers = User::whereIn('id', $vouchers->pluck('consumer_user_id')->filter())->get(['id', 'email'])
            ->filter(fn (User $u) => hash_equals((string) $order->customer_email_hash, (string) WebshopOrder::emailHash($u->email)))->pluck('id');

        return $vouchers->first(fn (Voucher $v) => $buyers->contains($v->consumer_user_id));
    }
}
