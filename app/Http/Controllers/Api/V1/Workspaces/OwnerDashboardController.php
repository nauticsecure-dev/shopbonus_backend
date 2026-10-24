<?php

namespace App\Http\Controllers\Api\V1\Workspaces;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Webshop;
use App\Models\WebshopMembership;
use App\Services\Analytics\MerchantAnalytics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Combined dashboard for an owner of several webshops (Trello #3 §17,
 * moved here from B03): the same KPI definitions per webshop, plus totals.
 * Only webshops the user actively owns appear.
 */
class OwnerDashboardController extends Controller
{
    public function show(Request $request, MerchantAnalytics $analytics): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $ids = WebshopMembership::where('user_id', $user->id)->where('role', MembershipRole::Owner)->where('status', MembershipStatus::Active)->pluck('webshop_id');

        $rows = Webshop::whereIn('id', $ids)->orderBy('name')->get()->map(fn (Webshop $w) => [
            'id' => $w->public_id, 'name' => $w->name, 'status' => $w->status->value, 'kpis' => $analytics->kpis($w->id),
        ])->values();

        $totals = [];
        foreach ($rows as $row) {
            foreach ($row['kpis'] as $k => $v) {
                $totals[$k] = ($totals[$k] ?? 0) + $v;
            }
        }

        return response()->json(['data' => ['webshops' => $rows->all(), 'totals' => $totals ?: array_fill_keys(array_keys(MerchantAnalytics::DEFINITIONS), 0),
            'definitions' => MerchantAnalytics::DEFINITIONS]]);
    }
}
