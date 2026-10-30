<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\AuditCategory;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\MerchantCommissionAgreement;
use App\Models\Webshop;
use App\Services\Audit\AuditLogger;
use App\Services\Cashback\DirectMerchantCashback;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Super Admin only: versioned direct-merchant commission agreements. */
class MerchantCommissionController extends Controller
{
    public function index(string $webshop): JsonResponse
    {
        $shop = $this->shop($webshop);

        return response()->json([
            'data' => MerchantCommissionAgreement::where('webshop_id', $shop->id)->orderByDesc('version')->get()->map(fn ($a) => $this->present($a))->all(),
            'current' => MerchantCommissionAgreement::current($shop->id)?->version,
        ]);
    }

    public function store(Request $request, string $webshop, AuditLogger $audit): JsonResponse
    {
        $shop = $this->shop($webshop);
        $data = $request->validate([
            'commission_type' => ['required', Rule::in(['percentage', 'fixed'])],
            'commission_rate_bps' => ['required_if:commission_type,percentage', 'nullable', 'integer', 'min:1', 'max:10000'],
            'commission_fixed_minor' => ['required_if:commission_type,fixed', 'nullable', 'integer', 'min:1'],
            'consumer_share_bps' => ['required', 'integer', 'min:0', 'max:10000'],
            'shopbonus_share_bps' => ['required', 'integer', 'min:0', 'max:10000'],
            'cashback_approved' => ['sometimes', 'boolean'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date', 'after:starts_at'],
        ]);
        if ($data['consumer_share_bps'] + $data['shopbonus_share_bps'] !== 10_000) {
            throw new ApiException('Consumer and ShopBonus shares must total 100%.', 422, 'invalid_commission_split');
        }

        $agreement = DB::transaction(function () use ($shop, $data, $request) {
            $version = (int) MerchantCommissionAgreement::where('webshop_id', $shop->id)->lockForUpdate()->max('version') + 1;

            return MerchantCommissionAgreement::create($data + [
                'webshop_id' => $shop->id,
                'version' => $version,
                'active' => true,
                'cashback_approved' => $data['cashback_approved'] ?? true,
                'created_by' => $request->user()->id,
            ]);
        });
        $audit->record('merchant.commission_agreement_created', category: AuditCategory::Financial, resource: $agreement, actorUserId: $request->user()->id, webshopId: $shop->id, metadata: $this->present($agreement));

        return response()->json(['data' => $this->present($agreement)], 201);
    }

    public function deactivate(Request $request, string $webshop, string $version, AuditLogger $audit): JsonResponse
    {
        $shop = $this->shop($webshop);
        $agreement = MerchantCommissionAgreement::where('webshop_id', $shop->id)->where('version', (int) $version)->first()
            ?? throw new ApiException('Agreement not found.', 404, 'not_found');
        $agreement->forceFill(['active' => false])->save();
        $audit->record('merchant.commission_agreement_deactivated', category: AuditCategory::Financial, resource: $agreement, actorUserId: $request->user()->id, webshopId: $shop->id);

        return response()->json(['data' => $this->present($agreement)]);
    }

    /** Synthetic verified conversion credit for ops/tests — never from a click alone. */
    public function credit(Request $request, string $webshop, DirectMerchantCashback $cashback, AuditLogger $audit): JsonResponse
    {
        $shop = $this->shop($webshop);
        $data = $request->validate([
            'user_id' => ['required', 'ulid'],
            'external_id' => ['required', 'string', 'max:100'],
            'order_amount_minor' => ['required', 'integer', 'min:1'],
            'commission_minor' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'currency' => ['sometimes', 'string', 'size:3'],
        ]);
        $key = validator(['key' => $request->header('Idempotency-Key')], ['key' => ['required', 'uuid']])->validate()['key'];
        $user = \App\Models\User::where('public_id', $data['user_id'])->first()
            ?? throw new ApiException('User not found.', 404, 'not_found');

        $row = $cashback->creditVerifiedConversion($shop, $user, $key, $data);
        $audit->record('merchant.commission_manual_credit', category: AuditCategory::Financial, resource: $row, actorUserId: $request->user()->id, webshopId: $shop->id);

        return response()->json(['data' => [
            'id' => $row->public_id,
            'status' => $row->status,
            'commission_minor' => $row->commission_minor,
            'consumer_credit_minor' => $row->consumer_credit_minor,
            'shopbonus_revenue_minor' => $row->shopbonus_revenue_minor,
            'exception_reason' => $row->exception_reason,
            'agreement' => $row->agreement_snapshot,
        ]], $row->wasRecentlyCreated ? 201 : 200);
    }

    private function shop(string $id): Webshop
    {
        return Webshop::where('public_id', $id)->first() ?? throw new ApiException('Webshop not found.', 404, 'not_found');
    }

    /** @return array<string, mixed> */
    private function present(MerchantCommissionAgreement $a): array
    {
        return $a->evidence() + [
            'version' => $a->version,
            'active' => $a->active,
            'starts_at' => $a->starts_at?->toIso8601String(),
            'ends_at' => $a->ends_at?->toIso8601String(),
        ];
    }
}
