<?php

namespace App\Http\Controllers\Api\V1\Support;

use App\Http\Controllers\Controller;
use App\Models\WebshopConnection;
use App\Services\Support\SupportTools;
use App\Services\Support\ToolEvidence;
use App\Services\Workspaces\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thin HTTP aliases for the verified-support tool contracts.
 * Reuses SupportTools / existing services — no duplicate financial logic.
 */
class ToolAliasController extends Controller
{
    public function orderReward(Request $request, string $reference, SupportTools $tools): JsonResponse
    {
        $e = $tools->retrieve($request->user(), 'get_my_order_reward_status', ['order_reference' => $reference]);

        return $this->evidence($e, [
            'order_reference', 'payment_status', 'reward_eligible', 'reward_minor', 'reward_status',
            'currency', 'source_type', 'claimed_at', 'release_date',
        ]);
    }

    public function vouchers(Request $request, SupportTools $tools): JsonResponse
    {
        $e = $tools->retrieve($request->user(), 'get_my_vouchers');
        $items = is_array($e->facts['items'] ?? null) ? $e->facts['items'] : [];

        return response()->json([
            'data' => $items,
            'meta' => [
                'issued' => $e->facts['issued'] ?? 0,
                'redeemed' => $e->facts['redeemed'] ?? 0,
                'expired' => $e->facts['expired'] ?? 0,
                'cancelled' => $e->facts['cancelled'] ?? 0,
                'source_id' => $e->sourceId,
                'checked_at' => $e->retrievedAt->toIso8601String(),
            ],
        ], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function voucherQuote(Request $request, string $offerId, SupportTools $tools): JsonResponse
    {
        $e = $tools->retrieve($request->user(), 'get_voucher_quote', ['offer_id' => strtolower($offerId)]);

        return $this->evidence($e, [
            'price_minor', 'wallet_usable_minor', 'mollie_due_minor', 'stock_available', 'currency', 'purchasable',
        ]);
    }

    public function workspaceWallet(Request $request, CurrentWorkspace $current, SupportTools $tools): JsonResponse
    {
        $e = $tools->retrieve($request->user(), 'get_workspace_wallet', [], $current->webshop()->public_id);

        return $this->evidence($e, ['available_minor', 'reserved_minor', 'currency', 'unit']);
    }

    public function offerCost(Request $request, CurrentWorkspace $current, string $offer, SupportTools $tools): JsonResponse
    {
        $e = $tools->retrieve($request->user(), 'get_offer_cost', ['offer_id' => strtolower($offer)], $current->webshop()->public_id);

        return $this->evidence($e, [
            'consumer_reward_minor', 'shopbonus_fee_rate', 'shopbonus_fee_minor',
            'merchant_total_economic_cost_minor', 'cost_available', 'basis', 'currency',
        ]);
    }

    public function campaignPerformance(Request $request, CurrentWorkspace $current, string $campaign, SupportTools $tools): JsonResponse
    {
        $e = $tools->retrieve($request->user(), 'get_campaign_performance', ['campaign_id' => strtolower($campaign)], $current->webshop()->public_id);

        return $this->evidence($e, [
            'impressions', 'clicks', 'tracked_order_starts', 'attributed_paid_orders', 'from', 'to',
        ]);
    }

    public function integrationHealth(Request $request, CurrentWorkspace $current, SupportTools $tools): JsonResponse
    {
        $workspace = $current->webshop();
        $tools->authorize($request->user(), $workspace->public_id);
        $connectionId = $request->query('connection_id');
        if (is_string($connectionId) && $connectionId !== '') {
            $e = $tools->retrieve($request->user(), 'get_integration_health', ['connection_id' => strtolower($connectionId)], $workspace->public_id);

            return $this->evidence($e, [
                'platform', 'status', 'capabilities', 'last_sync_at', 'last_error_codes',
            ]);
        }
        $rows = WebshopConnection::where('webshop_id', $workspace->id)->orderByDesc('id')->limit(20)->get()->map(function (WebshopConnection $c) use ($request, $tools, $workspace) {
            $e = $tools->retrieve($request->user(), 'get_integration_health', ['connection_id' => $c->public_id], $workspace->public_id);

            return [
                'connection_id' => $c->public_id,
                'platform' => $e->facts['platform'],
                'capabilities' => $e->facts['capabilities'],
                'last_sync_at' => $e->facts['last_sync_at'],
                'last_error_codes' => $e->facts['last_error_codes'],
                'source_id' => $e->sourceId,
                'checked_at' => $e->retrievedAt->toIso8601String(),
            ];
        })->values();

        return response()->json(['data' => $rows], 200, ['Cache-Control' => 'private, no-store']);
    }

    /** @param  list<string>  $keys */
    private function evidence(ToolEvidence $e, array $keys): JsonResponse
    {
        $data = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $e->facts)) {
                $data[$key] = $e->facts[$key];
            }
        }
        $data['source_id'] = $e->sourceId;
        $data['checked_at'] = $e->retrievedAt->toIso8601String();

        return response()->json(['data' => $data], 200, ['Cache-Control' => 'private, no-store']);
    }
}
