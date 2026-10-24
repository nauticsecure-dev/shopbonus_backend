<?php

namespace App\Http\Controllers\Api\V1\Consumer;

use App\Enums\AuditCategory;
use App\Enums\VoucherStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherCheckout;
use App\Models\WebshopPublicProfile;
use App\Services\Audit\AuditLogger;
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
    private const OWNED = [VoucherStatus::Issued, VoucherStatus::Redeemed, VoucherStatus::Expired, VoucherStatus::Cancelled];

    /** Card #20 filters → statuses. */
    private const FILTERS = ['active' => [VoucherStatus::Issued], 'used' => [VoucherStatus::Redeemed], 'expired' => [VoucherStatus::Expired], 'cancelled' => [VoucherStatus::Cancelled]];

    public function vouchers(Request $request): JsonResponse
    {
        $data = $request->validate(['filter' => ['sometimes', Rule::in(['all', ...array_keys(self::FILTERS)])]]);

        /** @var User $user */
        $user = $request->user();
        $vouchers = Voucher::with('code')->where('consumer_user_id', $user->id)
            ->whereIn('status', self::FILTERS[$data['filter'] ?? 'all'] ?? self::OWNED)
            ->orderByDesc('issued_at')->orderByDesc('id')->limit(200)->get();

        return response()->json(['data' => $this->present($vouchers->all())]);
    }

    /**
     * Voucher details with the full code, for its owner only (Trello #20
     * "revealed after authorized access"). Every reveal is audited.
     */
    public function voucher(Request $request, string $id, AuditLogger $audit): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $v = Voucher::with('code')->where('public_id', $id)->where('consumer_user_id', $user->id)->first()
            ?? throw new ApiException('Voucher not found.', 404, 'not_found');
        $audit->recordSafely('voucher.code_revealed', category: AuditCategory::Voucher, resource: $v, webshopId: $v->webshop_id, actorUserId: $user->id);

        $profile = WebshopPublicProfile::where('webshop_id', $v->webshop_id)->first();
        $terms = $v->terms_snapshot;
        $checkout = VoucherCheckout::where('voucher_id', $v->id)->first();

        return response()->json(['data' => $this->present([$v])[0] + [
            'code' => $v->code->code,
            'expires_soon' => $v->status === VoucherStatus::Issued && $v->expires_at !== null && $v->expires_at->lessThan(now()->addDays(14)),
            'instructions' => 'Enter the code in the discount field at checkout on the webshop. Minimum order and conditions below apply.',
            'website_url' => $profile?->website_url,
            'terms' => array_intersect_key($terms, array_flip(['name', 'summary', 'public_description', 'discount_type', 'discount_value', 'currency',
                'min_order_minor', 'max_discount_minor', 'categories', 'products', 'combinable', 'version'])),
            'purchase' => $checkout === null ? null : ['checkout_id' => $checkout->public_id, 'price_minor' => $checkout->price_minor,
                'wallet_part_minor' => $checkout->wallet_part_minor, 'payment_part_minor' => $checkout->payment_part_minor],
            'redemption' => $v->redeemed_at === null ? null : ['at' => $v->redeemed_at->toIso8601String(), 'source' => $v->redemption_source],
        ]]);
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
            'code_masked' => '••••'.$v->code->code_last4,
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
