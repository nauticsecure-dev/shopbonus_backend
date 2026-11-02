<?php

namespace App\Http\Controllers\Api\V1\Consumer;

use App\Http\Controllers\Controller;
use App\Models\AffiliateTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The consumer's own attributed purchases (Trello #19 "Affiliate activity").
 * Never network commission data or anyone else's transactions.
 */
class AffiliateActivityController extends Controller
{
    private const EXPLAIN = [
        'nl' => [
            'pending' => 'Deze aankoop is in behandeling. Cashback wacht op goedkeuring en ontvangen netwerkbetaling.',
            'approved' => 'De aankoop is goedgekeurd. Cashback wordt beschikbaar na ontvangen netwerkbetaling en eventuele controle.',
            'rejected' => 'De webshop heeft deze aankoop niet bevestigd (bijvoorbeeld een retour of annulering).',
            'reversed' => 'De aankoop is later geannuleerd.',
            'tracked' => 'We hebben een signaal van deze aankoop ontvangen en wachten op de netwerkdetails.',
        ],
        'en' => [
            'pending' => 'This purchase is pending. Cashback awaits approval and receipt of the network payment.',
            'approved' => 'The purchase was approved. Cashback becomes available after receipt of the network payment and any required review.',
            'rejected' => 'The webshop did not confirm this purchase (for example a return or cancellation).',
            'reversed' => 'The purchase was later cancelled.',
            'tracked' => 'We received a signal of this purchase and are waiting for the network\'s details.',
        ],
    ];

    public function index(Request $request): JsonResponse
    {
        $locale = in_array($request->user()->locale ?? 'nl', ['nl', 'en'], true) ? ($request->user()->locale ?? 'nl') : 'nl';
        if (in_array($request->query('locale'), ['nl', 'en'], true)) {
            $locale = $request->query('locale');
        }
        $explain = self::EXPLAIN[$locale];

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
                'explanation' => $explain[$t->status] ?? null,
                'reward' => $t->reward_amount === null ? null : ['amount' => $t->reward_amount, 'unit' => $t->reward_unit, 'status' => $t->reward_status],
            ])->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
            'notice' => $locale === 'nl'
                ? 'Cashback wordt beschikbaar na goedkeuring en ontvangen netwerkbetaling. Checkout-reserveringen zijn apart.'
                : 'Cashback becomes available after approval and receipt of the network payment. Checkout reservations are separate.',
        ]);
    }
}
