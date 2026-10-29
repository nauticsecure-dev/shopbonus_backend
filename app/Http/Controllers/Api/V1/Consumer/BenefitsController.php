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
    private const FILTERS = [
        'active' => [VoucherStatus::Issued],
        'used' => [VoucherStatus::Redeemed],
        'expired' => [VoucherStatus::Expired],
        'cancelled' => [VoucherStatus::Cancelled],
    ];

    private const STATUS_LABEL = [
        'issued' => 'ACTIVE',
        'redeemed' => 'USED',
        'expired' => 'EXPIRED',
        'cancelled' => 'CANCELLED',
    ];

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
        $usable = $v->status === VoucherStatus::Issued && ($v->expires_at === null || $v->expires_at->isFuture());

        return response()->json(['data' => $this->present([$v])[0] + [
            'code' => $usable || $v->status === VoucherStatus::Redeemed ? $v->code->code : null,
            'code_visible' => $usable,
            'can_copy_code' => $usable,
            'expires_soon' => $usable && $v->expires_at !== null && $v->expires_at->lessThan(now()->addDays(14)),
            'instructions' => 'Voer de code in bij het afrekenen op de webshop. Minimale bestelling en voorwaarden hieronder gelden.',
            'website_url' => $profile?->website_url,
            'terms' => array_intersect_key($terms, array_flip(['name', 'summary', 'public_description', 'discount_type', 'discount_value', 'currency',
                'min_order_minor', 'max_discount_minor', 'categories', 'products', 'combinable', 'version'])),
            'purchase' => $checkout === null ? null : ['checkout_id' => $checkout->public_id, 'price_minor' => $checkout->price_minor,
                'wallet_part_minor' => $checkout->wallet_part_minor, 'payment_part_minor' => $checkout->payment_part_minor],
            'redemption' => $v->redeemed_at === null ? null : ['at' => $v->redeemed_at->toIso8601String(), 'source' => $v->redemption_source],
            'gift_supported' => false,
        ]]);
    }

    public function dashboard(Request $request, PublicCatalog $catalog): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $owned = Voucher::where('consumer_user_id', $user->id);
        $recent = (clone $owned)->with('code')->whereIn('status', self::OWNED)->orderByDesc('issued_at')->limit(3)->get();
        $favorites = DB::table('consumer_favorite_webshops')->where('user_id', $user->id)->pluck('webshop_id')->map(fn ($id) => (int) $id)->all();
        $wallet = WalletController::summary($user);

        return response()->json(['data' => [
            'first_name' => $user->first_name,
            'email_verified' => $user->email_verified_at !== null,
            'wallet' => $wallet['balances'],
            'available_minor' => $wallet['available_minor'],
            'total_earned_minor' => $wallet['total_earned_minor'],
            'total_spent_minor' => $wallet['total_spent_minor'],
            'vouchers' => [
                'issued' => (clone $owned)->where('status', VoucherStatus::Issued)->count(),
                'redeemed' => (clone $owned)->where('status', VoucherStatus::Redeemed)->count(),
                'expiring_within_30_days' => (clone $owned)->where('status', VoucherStatus::Issued)->whereNotNull('expires_at')
                    ->whereBetween('expires_at', [now(), now()->addDays(30)])->count(),
            ],
            'recent_vouchers' => $this->present($recent->all()),
            'favorites' => array_slice($catalog->cardsFor($favorites), 0, 4),
            'savings_goal' => null,
        ]]);
    }

    /**
     * @param  list<Voucher>  $vouchers
     * @return list<array<string, mixed>>
     */
    private function present(array $vouchers): array
    {
        if ($vouchers === []) {
            return [];
        }

        $profiles = WebshopPublicProfile::whereIn('webshop_id', array_unique(array_map(fn (Voucher $v) => $v->webshop_id, $vouchers)))->get()->keyBy('webshop_id');
        $names = DB::table('webshops')->whereIn('id', array_map(fn (Voucher $v) => $v->webshop_id, $vouchers))->pluck('name', 'id');

        return array_map(function (Voucher $v) use ($profiles, $names) {
            $profile = $profiles[$v->webshop_id] ?? null;
            $terms = $v->terms_snapshot;
            $discountType = $terms['discount_type'] ?? null;
            $discountValue = $terms['discount_value'] ?? null;
            // Offer versions store percentage values in basis points (1000 = 10%).
            $displayDiscount = $discountType === 'percentage' && is_numeric($discountValue)
                ? ((int) $discountValue >= 100 ? (int) $discountValue / 100 : (float) $discountValue)
                : $discountValue;
            $usable = $v->status === VoucherStatus::Issued && ($v->expires_at === null || $v->expires_at->isFuture());
            $daysLeft = $usable && $v->expires_at !== null ? max(0, (int) now()->diffInDays($v->expires_at, false)) : null;

            return [
                'id' => $v->public_id,
                'voucher_id' => $v->public_id,
                'status' => self::STATUS_LABEL[$v->status->value] ?? strtoupper($v->status->value),
                'status_raw' => $v->status->value,
                'origin' => $v->origin->value,
                'code_masked' => '••••'.$v->code->code_last4,
                'can_copy_code' => $usable,
                'can_report_problem' => true,
                'gift_supported' => false,
                'webshop' => [
                    'name' => $profile->display_name ?? $names[$v->webshop_id] ?? null,
                    'slug' => $profile->slug ?? null,
                    'logo_url' => $profile?->logo_path === null || $profile?->slug === null
                        ? null
                        : route('api.v1.public.webshops.logo', ['slug' => $profile->slug, 'v' => $profile->updated_at->getTimestamp()]),
                    'categories' => $profile?->categories() ?? [],
                ],
                'merchant_name' => $profile->display_name ?? $names[$v->webshop_id] ?? null,
                'title' => $terms['name'] ?? null,
                'summary' => $terms['summary'] ?? null,
                'discount_type' => $discountType,
                'discount_value' => $displayDiscount,
                'minimum_order_minor' => $terms['min_order_minor'] ?? null,
                'min_order_minor' => $terms['min_order_minor'] ?? null,
                'maximum_discount_minor' => $terms['max_discount_minor'] ?? null,
                'max_discount_minor' => $terms['max_discount_minor'] ?? null,
                'issued_at' => $v->issued_at?->toIso8601String(),
                'expires_at' => $v->expires_at?->toIso8601String(),
                'redeemed_at' => $v->redeemed_at?->toIso8601String(),
                'days_left' => $daysLeft,
            ];
        }, $vouchers);
    }
}
