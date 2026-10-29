<?php

namespace App\Http\Controllers\Api\V1\Workspaces;

use App\Enums\AuditCategory;
use App\Enums\VoucherStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\Consumer\DisputeController;
use App\Http\Controllers\Controller;
use App\Models\Voucher;
use App\Models\VoucherCode;
use App\Models\VoucherDispute;
use App\Services\Audit\AuditLogger;
use App\Services\Disputes\DisputeService;
use App\Services\Vouchers\VoucherInventory;
use App\Services\Workspaces\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Merchant voucher operations (Trello #20 "Redemption & validation",
 * disputes): verify a redemption, see and answer reports about this
 * webshop's vouchers. Never balances, never other webshops.
 */
class VoucherOperationsController extends Controller
{
    public function redeem(Request $request, CurrentWorkspace $current, VoucherInventory $inventory, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:100'], 'reference' => ['sometimes', 'nullable', 'string', 'max:120']]);
        $webshop = $current->webshop();

        $codeId = VoucherCode::where('webshop_id', $webshop->id)->where('code_hash', VoucherCode::hashFor($webshop->id, $data['code']))->value('id');
        // One code can back several vouchers (reusable): take the oldest one still issued.
        $voucher = $codeId === null ? null : Voucher::where('voucher_code_id', $codeId)->whereIn('status', [VoucherStatus::Issued, VoucherStatus::Redeemed, VoucherStatus::Expired])
            ->orderByRaw("CASE WHEN status = 'issued' THEN 0 ELSE 1 END")->orderBy('id')->first();
        if ($voucher === null) {
            $audit->recordSafely('voucher.redeem_unknown_code', category: AuditCategory::Voucher, webshopId: $webshop->id, actorUserId: $current->membership()->user_id);

            throw new ApiException('No issued voucher of this webshop has that code.', 404, 'voucher_not_found');
        }

        $voucher = $inventory->redeemByMerchant($voucher, $current->membership()->user_id, $data['reference'] ?? null);

        return response()->json(['data' => ['voucher_id' => $voucher->public_id, 'status' => $voucher->status->value, 'redeemed_at' => $voucher->redeemed_at?->toIso8601String(),
            'source' => $voucher->redemption_source, 'reference' => $voucher->redemption_reference]]);
    }

    public function disputes(Request $request, CurrentWorkspace $current): JsonResponse
    {
        $data = $request->validate(['status' => ['sometimes', Rule::in(['open', 'under_review', 'waiting_for_merchant', 'resolved', 'rejected'])]]);

        return response()->json(['data' => VoucherDispute::where('webshop_id', $current->webshop()->id)->when(isset($data['status']), fn ($q) => $q->where('status', $data['status']))
            ->orderByDesc('id')->limit(200)->get()->map(fn (VoucherDispute $d) => self::merchantView($d))->all()]);
    }

    public function respond(Request $request, CurrentWorkspace $current, string $dispute, DisputeService $disputes): JsonResponse
    {
        $data = $request->validate(['message' => ['required', 'string', 'min:5', 'max:2000', 'not_regex:/<[^>]*>/']]);
        $d = VoucherDispute::where('public_id', $dispute)->where('webshop_id', $current->webshop()->id)->first() ?? throw new ApiException('Report not found.', 404, 'not_found');

        return response()->json(['data' => self::merchantView($disputes->merchantRespond($d, $current->membership()->user_id, $data['message']), withTimeline: true)]);
    }

    /**
     * The consumer's description and timeline, but no consumer identity or screenshots.
     *
     * @return array<string, mixed>
     */
    private static function merchantView(VoucherDispute $d, bool $withTimeline = false): array
    {
        $view = DisputeController::present($d, $withTimeline);
        $voucher = $d->voucher_id === null ? null : Voucher::with('code')->find($d->voucher_id);
        $view['code_last4'] = $voucher?->code->code_last4;
        unset($view['checkout_id']);
        // Screenshots can show consumer details; merchants only learn that evidence exists.
        $view['screenshots'] = count($view['screenshots']);

        return $view;
    }
}
