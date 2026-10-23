<?php

namespace App\Http\Controllers\Api\V1\Claims;

use App\Http\Controllers\Controller;
use App\Models\Voucher;
use App\Models\VoucherClaim;
use App\Services\Answer24\ClaimLinks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Consumer side of a claim link (Trello #23 "Consumer verifies identity and
 * claims the reserved voucher"). Preview is public and shows only what the
 * consumer needs to decide; accepting needs a verified account.
 */
class ClaimController extends Controller
{
    /** Consumer-facing subset of the frozen terms (no inventory internals). */
    private const PUBLIC_TERMS = ['name', 'summary', 'public_description', 'discount_type', 'discount_value', 'currency',
        'min_order_minor', 'max_discount_minor', 'ends_at', 'redemption_validity_days', 'categories', 'products', 'combinable'];

    public function preview(Request $request, ClaimLinks $claims): JsonResponse
    {
        $claim = $claims->findOpen($this->token($request));

        return response()->json(['data' => [
            'status' => $claim->status,
            'expires_at' => $claim->expires_at->toIso8601String(),
            'webshop' => ['name' => $claim->webshop->name],
            'offer' => $this->terms($claim->voucher),
            'requirements' => ['verified_account' => true, 'email_matches_order' => (bool) config('shopbonus.answer24.claim_requires_email_match', true)],
        ]]);
    }

    public function accept(Request $request, ClaimLinks $claims): JsonResponse
    {
        $voucher = $claims->accept($this->token($request), $request->user());
        $voucher->loadMissing('code');

        return response()->json(['data' => [
            'voucher_id' => $voucher->public_id,
            'status' => $voucher->status->value,
            'code' => $voucher->code->code,
            'issued_at' => $voucher->issued_at?->toIso8601String(),
            'expires_at' => $voucher->expires_at?->toIso8601String(),
            'webshop' => ['name' => VoucherClaim::where('voucher_id', $voucher->id)->sole()->webshop->name],
            'offer' => $this->terms($voucher),
        ]]);
    }

    private function token(Request $request): string
    {
        return (string) $request->validate(['token' => ['required', 'string', 'max:64']])['token'];
    }

    /** @return array<string, mixed> */
    private function terms(Voucher $voucher): array
    {
        return array_intersect_key($voucher->terms_snapshot, array_flip(self::PUBLIC_TERMS));
    }
}
