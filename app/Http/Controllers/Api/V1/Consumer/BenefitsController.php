<?php

namespace App\Http\Controllers\Api\V1\Consumer;

use App\Enums\VoucherStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Voucher;
use App\Models\WebshopPublicProfile;
use App\Services\Discovery\PublicCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * My Vouchers and the personal dashboard (Trello #18 "Consumer dashboard",
 * #20 "My Vouchers"). Read from the one voucher model and the central
 * wallet; the consumer sees only their own vouchers and balances.
 */
class BenefitsController extends Controller
{
    private const OWNED = [VoucherStatus::Issued, VoucherStatus::Redeemed, VoucherStatus::Expired];

    public function vouchers(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['sometimes', Rule::in(array_map(fn ($s) => $s->value, self::OWNED))]]);

        /** @var User $user */
        $user = $request->user();
        $vouchers = Voucher::with('code')->where('consumer_user_id', $user->id)
            ->whereIn('status', isset($data['status']) ? [$data['status']] : self::OWNED)
            ->orderByDesc('issued_at')->orderByDesc('id')->limit(200)->get();

        return response()->json(['data' => $this->present($vouchers->all())]);
    }

    public function dashboard(Request $request, PublicCatalog $catalog): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $owned = Voucher::where('consumer_user_id', $user->id);
        $recent = (clone $owned)->with('code')->whereIn('status', self::OWNED)->orderByDesc('issued_at')->limit(3)->get();
        $favorites = DB::table('consumer_favorite_webshops')->where('user_id', $user->id)->pluck('webshop_id')->map(fn ($id) => (int) $id)->all();

        return response()->json(['data' => [
            'first_name' => $user->first_name,
            'email_verified' => $user->email_verified_at !== null,
            'wallet' => WalletController::summary($user)['balances'],
            'vouchers' => [
                'issued' => (clone $owned)->where('status', VoucherStatus::Issued)->count(),
                'redeemed' => (clone $owned)->where('status', VoucherStatus::Redeemed)->count(),
                'expiring_within_30_days' => (clone $owned)->where('status', VoucherStatus::Issued)->whereNotNull('expires_at')
                    ->whereBetween('expires_at', [now(), now()->addDays(30)])->count(),
            ],
            'recent_vouchers' => $this->present($recent->all()),
            'favorites' => array_slice($catalog->cardsFor($favorites), 0, 4),
        ]]);
    }

    /**
     * @param  list<Voucher>  $vouchers
     * @return list<array<string, mixed>>
     */
    private function present(array $vouchers): array
    {
        $profiles = WebshopPublicProfile::whereIn('webshop_id', array_unique(array_map(fn (Voucher $v) => $v->webshop_id, $vouchers)))->get()->keyBy('webshop_id');
        $names = DB::table('webshops')->whereIn('id', array_map(fn (Voucher $v) => $v->webshop_id, $vouchers))->pluck('name', 'id');

        return array_map(fn (Voucher $v) => [
            'id' => $v->public_id,
            'status' => $v->status->value,
            'origin' => $v->origin->value,
            'code' => $v->code->code,
            'webshop' => ['name' => $profiles[$v->webshop_id]->display_name ?? $names[$v->webshop_id] ?? null, 'slug' => $profiles[$v->webshop_id]->slug ?? null],
            'title' => $v->terms_snapshot['name'] ?? null,
            'summary' => $v->terms_snapshot['summary'] ?? null,
            'min_order_minor' => $v->terms_snapshot['min_order_minor'] ?? null,
            'issued_at' => $v->issued_at?->toIso8601String(),
            'expires_at' => $v->expires_at?->toIso8601String(),
            'redeemed_at' => $v->redeemed_at?->toIso8601String(),
        ], $vouchers);
    }
}
