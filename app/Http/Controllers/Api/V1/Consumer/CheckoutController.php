<?php

namespace App\Http\Controllers\Api\V1\Consumer;

use App\Enums\WalletUnit;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Offer;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherCheckout;
use App\Services\Checkout\CheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Voucher checkout for the signed-in consumer (Trello #20). Prices and
 * splits are always computed server-side; codes are never in these
 * responses (they appear in My Vouchers once issued).
 */
class CheckoutController extends Controller
{
    public function __construct(private readonly CheckoutService $checkouts) {}

    public function quote(Request $request): JsonResponse
    {
        $data = $request->validate(['offer' => ['required', 'string', 'max:100'], 'use_wallet' => ['sometimes', 'boolean'], 'wallet_unit' => ['sometimes', Rule::enum(WalletUnit::class)]]);

        return response()->json(['data' => $this->checkouts->quote($request->user(), $this->offer($data['offer']), (bool) ($data['use_wallet'] ?? true), WalletUnit::from($data['wallet_unit'] ?? 'eur_credit'))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['offer' => ['required', 'string', 'max:100'], 'use_wallet' => ['sometimes', 'boolean'], 'wallet_unit' => ['sometimes', Rule::enum(WalletUnit::class)]]);
        $key = (string) $request->header('Idempotency-Key', '');
        if (preg_match('/^[A-Za-z0-9._:-]{8,100}$/', $key) !== 1) {
            throw new ApiException('Send a unique Idempotency-Key header (8–100 characters) with each purchase.', 400, 'idempotency_key_required');
        }

        $checkout = $this->checkouts->start($request->user(), $this->offer($data['offer']), $key, (bool) ($data['use_wallet'] ?? true), WalletUnit::from($data['wallet_unit'] ?? 'eur_credit'));

        return response()->json(['data' => self::present($checkout)], $checkout->wasRecentlyCreated ? 201 : 200);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $checkout = VoucherCheckout::with('payment')->where('public_id', $id)->where('user_id', $user->id)->first()
            ?? throw new ApiException('Checkout not found.', 404, 'not_found');

        return response()->json(['data' => self::present($checkout)]);
    }

    /** @return array<string, mixed> */
    public static function present(VoucherCheckout $c): array
    {
        $c->loadMissing('payment');
        $offer = Offer::with('currentVersion')->find($c->offer_id);
        $voucher = $c->voucher_id === null ? null : Voucher::whereKey($c->voucher_id)->first(['public_id', 'terms_snapshot']);

        return [
            'id' => $c->public_id,
            'state' => $c->state,
            // The title the consumer bought (frozen with the reserved voucher), not a later edit.
            'offer' => ['slug' => $offer?->slug, 'title' => $voucher?->terms_snapshot['name'] ?? $offer?->currentVersion?->name],
            'wallet_unit' => $c->wallet_unit->value, 'price_points' => $c->price_points, 'wallet_part_points' => $c->wallet_part_points,
            'price_minor' => $c->price_minor, 'wallet_part_minor' => $c->wallet_part_minor, 'payment_part_minor' => $c->payment_part_minor, 'currency' => $c->currency,
            'payment' => $c->payment === null ? null : [
                'provider' => 'mollie', 'status' => $c->payment->status, 'test_mode' => $c->payment->test_mode,
                'checkout_url' => $c->state === 'pending_payment' && in_array($c->payment->status, ['open', 'pending'], true) ? $c->payment->checkout_url : null,
            ],
            'voucher_id' => $c->state === 'completed' ? $voucher?->public_id : null,
            'failure_reason' => $c->failure_reason,
            'expires_at' => $c->expires_at->toIso8601String(),
            'completed_at' => $c->completed_at?->toIso8601String(),
        ];
    }

    private function offer(string $slug): Offer
    {
        return Offer::with('currentVersion')->where('slug', $slug)->first() ?? throw new ApiException('This offer is not available.', 404, 'offer_not_found');
    }
}
