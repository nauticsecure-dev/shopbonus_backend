<?php

namespace App\Http\Controllers\Api\V1\Consumer;

use App\Http\Controllers\Controller;
use App\Models\Voucher;
use App\Models\VoucherClaim;
use App\Services\Answer24\ClaimLinks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Consumer activation invitations (Trello #18).
 *
 * A short-lived, single-use token from an eligible webshop event. Preview
 * (`verify`) is public and never issues a voucher; `complete` requires a
 * verified session and associates the reserved benefit exactly once.
 *
 * Same underlying claim tokens as POST /api/v1/claims/* (Answer24 handoff).
 */
class ActivationController extends Controller
{
    /** Consumer-facing subset of frozen terms (no inventory internals). */
    private const PUBLIC_TERMS = [
        'name', 'summary', 'public_description', 'discount_type', 'discount_value', 'currency',
        'min_order_minor', 'max_discount_minor', 'ends_at', 'redemption_validity_days',
        'categories', 'products', 'combinable',
    ];

    /**
     * Validate an invitation token and return a safe public preview.
     * Opening the link never creates an account or allocates a voucher.
     */
    public function verify(Request $request, ClaimLinks $claims): JsonResponse
    {
        $claim = $claims->findOpen($this->token($request));

        return response()->json(['data' => [
            'status' => 'eligible',
            'claim_status' => $claim->status,
            'expires_at' => $claim->expires_at->toIso8601String(),
            'webshop' => ['name' => $claim->webshop->name],
            'offer' => $this->terms($claim->voucher),
            'requirements' => [
                'verified_account' => true,
                'email_matches_order' => (bool) config('shopbonus.answer24.claim_requires_email_match', true),
            ],
            'explanation' => 'ShopBonus beloning gekoppeld aan een eerdere aankoop bij deze webshop. Log in of maak een account met het e-mailadres van de bestelling om te activeren.',
        ]]);
    }

    /**
     * Associate the eligible event with the verified session user exactly once.
     */
    public function complete(Request $request, ClaimLinks $claims): JsonResponse
    {
        $voucher = $claims->accept($this->token($request), $request->user());
        $voucher->loadMissing('code');
        $claim = VoucherClaim::where('voucher_id', $voucher->id)->sole();

        return response()->json(['data' => [
            'status' => 'activated',
            'voucher_id' => $voucher->public_id,
            'voucher_status' => $voucher->status->value,
            'code' => $voucher->code->code,
            'issued_at' => $voucher->issued_at?->toIso8601String(),
            'expires_at' => $voucher->expires_at?->toIso8601String(),
            'webshop' => ['name' => $claim->webshop->name],
            'offer' => $this->terms($voucher),
            'dashboard_path' => '/mijn-voordelen',
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
