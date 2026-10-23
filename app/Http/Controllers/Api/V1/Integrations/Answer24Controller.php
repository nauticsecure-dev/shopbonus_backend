<?php

namespace App\Http\Controllers\Api\V1\Integrations;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\ContactOpportunity;
use App\Models\VoucherClaim;
use App\Models\Webshop;
use App\Services\Answer24\ClaimLinks;
use App\Services\Answer24\ContactResults;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Answer24 → Shopbonus, server to server (`integration.signed`). Every
 * call names the webshop and the opportunity; a mismatch is the same 404 as
 * an unknown ID, so one tenant's IDs cannot address another's data.
 */
class Answer24Controller extends Controller
{
    public function contactResult(Request $request, ContactResults $results): JsonResponse
    {
        $data = $request->validate([
            'webshop_id' => ['required', 'string', 'max:40'],
            'opportunity_id' => ['required', 'string', 'max:40'],
            'status' => ['required', Rule::in(ContactResults::STATUSES)],
            'occurred_at' => ['required', 'date'],
            'remote_reference' => ['nullable', 'string', 'max:120'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $outcome = $results->apply($this->opportunity($data), $data, (string) $request->header('X-Shopbonus-Event-Id'));

        return response()->json(['data' => [
            'opportunity_id' => $outcome['opportunity']->public_id,
            'status' => $outcome['opportunity']->status,
            'outcome' => $outcome['outcome'],
        ]]);
    }

    public function handoff(Request $request, ClaimLinks $claims): JsonResponse
    {
        $data = $request->validate([
            'webshop_id' => ['required', 'string', 'max:40'],
            'opportunity_id' => ['required', 'string', 'max:40'],
        ]);

        [$claim, $token, $created] = $claims->handoff($this->opportunity($data));

        return response()->json(['data' => $this->claim($claim) + ['claim_url' => ClaimLinks::url($token)]], $created ? 201 : 200);
    }

    public function claimStatus(string $claim): JsonResponse
    {
        return response()->json(['data' => $this->claim($this->findClaim($claim))]);
    }

    public function reminder(Request $request, string $claim, ClaimLinks $claims): JsonResponse
    {
        $data = $request->validate(['day' => ['required', 'integer', Rule::in(config('shopbonus.answer24.reminder_days'))]]);
        $model = $this->findClaim($claim);
        $decision = $claims->reminder($model, (int) $data['day']);

        return response()->json(['data' => ['claim_id' => $model->public_id, 'day' => (int) $data['day']] + $decision
            + ['claim_expires_at' => $model->expires_at->toIso8601String()]]);
    }

    /**
     * @param  array{webshop_id: string, opportunity_id: string}  $data
     */
    private function opportunity(array $data): ContactOpportunity
    {
        $webshopId = Webshop::where('public_id', $data['webshop_id'])->value('id');

        return ContactOpportunity::where('public_id', $data['opportunity_id'])->where('webshop_id', $webshopId ?? 0)->first()
            ?? throw new ApiException('Contact request not found.', 404, 'opportunity_not_found');
    }

    private function findClaim(string $publicId): VoucherClaim
    {
        return VoucherClaim::where('public_id', $publicId)->whereNotNull('opportunity_id')->first()
            ?? throw new ApiException('Claim not found.', 404, 'claim_not_found');
    }

    /** @return array<string, mixed> */
    private function claim(VoucherClaim $claim): array
    {
        $status = $claim->status === 'open' && $claim->expires_at->isPast() ? 'expired' : $claim->status;

        return [
            'claim_id' => $claim->public_id,
            'status' => $status,
            'expires_at' => $claim->expires_at->toIso8601String(),
            'claimed_at' => $claim->claimed_at?->toIso8601String(),
            'reminder_days' => config('shopbonus.answer24.reminder_days'),
        ];
    }
}
