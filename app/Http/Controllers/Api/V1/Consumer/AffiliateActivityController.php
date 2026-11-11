<?php

namespace App\Http\Controllers\Api\V1\Consumer;

use App\Http\Controllers\Controller;
use App\Models\AffiliateTransaction;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The consumer's own attributed purchases (Trello #19 "Affiliate activity").
 * Never network commission data or anyone else's transactions.
 */
class AffiliateActivityController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $locale = $request->query('locale', $request->hasHeader('Accept-Language') ? app()->getLocale() : $request->user()->preferredLocale());
        if (! in_array($locale, User::SUPPORTED_LOCALES, true)) {
            $locale = 'nl';
        }
        $page = AffiliateTransaction::where('user_id', $request->user()->id)->whereNotIn('status', ['disputed', 'unmatched'])
            ->orderByDesc('transaction_at')->paginate(25);
        $names = DB::table('webshops')->whereIn('id', collect($page->items())->pluck('webshop_id'))->pluck('name', 'id');

        return response()->json([
            'data' => collect($page->items())->map(fn (AffiliateTransaction $t) => [
                'id' => $t->public_id,
                'webshop' => $names[$t->webshop_id] ?? null,
                'purchased_at' => $t->transaction_at->toIso8601String(),
                'order_amount_minor' => $t->order_amount_minor,
                'currency' => $t->currency,
                'status' => $t->status,
                'explanation' => $t->reward_status === 'credited' && $t->reward_timing === 'on_validated_attribution'
                    ? __('benefits.affiliate_credited', [], $locale)
                    : (in_array($t->status, ['pending', 'approved', 'rejected', 'reversed', 'tracked'], true)
                        ? __('benefits.affiliate_'.$t->status, [], $locale) : null),
                'reward' => $t->reward_amount === null ? null : ['amount' => $t->reward_amount, 'unit' => $t->reward_unit, 'status' => $t->reward_status],
            ])->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
            'notice' => __('benefits.affiliate_notice', [], $locale),
        ]);
    }
}
