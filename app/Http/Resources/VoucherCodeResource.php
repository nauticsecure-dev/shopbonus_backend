<?php

namespace App\Http\Resources;

use App\Models\Voucher;
use App\Models\VoucherCode;
use App\Support\Mask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A code-sheet row. The code is masked; the full value needs the audited
 * reveal endpoint (Trello #7 "Protect unused discount codes from
 * unauthorized bulk extraction").
 *
 * @mixin VoucherCode
 */
class VoucherCodeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var VoucherCode $code */
        $code = $this->resource;
        /** @var Voucher|null $voucher */
        $voucher = $code->getRelationValue('currentVoucher');
        // A released (cancelled) reservation no longer assigns the code to anyone.
        $voucher = $voucher !== null && $voucher->status->value !== 'cancelled' ? $voucher : null;
        $consumer = $voucher?->getRelationValue('consumer');

        return [
            'id' => $code->public_id,
            'code' => $code->masked(),
            'status' => $code->status->value,
            'reusable' => $code->reusable,
            'uses_count' => $code->uses_count,
            'platform_validation' => $code->platform_validation,
            'batch_id' => $code->getRelationValue('batch')?->public_id,
            'assigned' => $voucher === null ? null : [
                'voucher_id' => $voucher->public_id,
                'origin' => $voucher->origin->value,
                'voucher_status' => $voucher->status->value,
                'consumer' => $consumer === null ? null : Mask::email($consumer->email),
                'claim_expires_at' => $voucher->claim_expires_at?->toIso8601String(),
                'expires_at' => $voucher->expires_at?->toIso8601String(),
            ],
            'reserved_at' => $code->reserved_at?->toIso8601String(),
            'issued_at' => $code->issued_at?->toIso8601String(),
            'redeemed_at' => $code->redeemed_at?->toIso8601String(),
            'invalidated_at' => $code->invalidated_at?->toIso8601String(),
            'invalid_reason' => $code->invalid_reason,
            'created_at' => $code->created_at->toIso8601String(),
        ];
    }
}
