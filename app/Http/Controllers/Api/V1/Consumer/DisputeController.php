<?php

namespace App\Http\Controllers\Api\V1\Consumer;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherCheckout;
use App\Models\VoucherDispute;
use App\Models\VoucherDisputeEvent;
use App\Services\Disputes\DisputeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * "Report voucher problem" (Trello #20) for the consumer's own vouchers
 * and purchases only.
 */
class DisputeController extends Controller
{
    public function store(Request $request, DisputeService $disputes): JsonResponse
    {
        $data = $request->validate([
            'voucher_id' => ['required_without:checkout_id', 'nullable', 'string', 'max:40'],
            'checkout_id' => ['required_without:voucher_id', 'nullable', 'string', 'max:40'],
            'reason' => ['required', Rule::in(VoucherDispute::REASONS)],
            'description' => ['required', 'string', 'min:10', 'max:2000', 'not_regex:/<[^>]*>/'],
        ]);
        /** @var User $user */
        $user = $request->user();

        $voucher = isset($data['voucher_id']) ? (Voucher::where('public_id', $data['voucher_id'])->where('consumer_user_id', $user->id)->first()
            ?? throw new ApiException('Voucher not found.', 404, 'not_found')) : null;
        $checkout = isset($data['checkout_id']) ? (VoucherCheckout::where('public_id', $data['checkout_id'])->where('user_id', $user->id)->first()
            ?? throw new ApiException('Purchase not found.', 404, 'not_found')) : null;

        return response()->json(['data' => self::present($disputes->open($user, $voucher, $checkout, $data['reason'], $data['description']))], 201);
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => VoucherDispute::where('user_id', $request->user()->id)->orderByDesc('id')->limit(100)->get()
            ->map(fn (VoucherDispute $d) => self::present($d))->all()]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $d = VoucherDispute::where('public_id', $id)->where('user_id', $request->user()->id)->first() ?? throw new ApiException('Report not found.', 404, 'not_found');

        return response()->json(['data' => self::present($d, withTimeline: true)]);
    }

    /** @return array<string, mixed> */
    public static function present(VoucherDispute $d, bool $withTimeline = false): array
    {
        $out = [
            'id' => $d->public_id, 'status' => $d->status, 'reason' => $d->reason, 'description' => $d->description,
            'webshop' => DB::table('webshops')->where('id', $d->webshop_id)->value('name'),
            'voucher_id' => $d->voucher_id === null ? null : Voucher::whereKey($d->voucher_id)->value('public_id'),
            'checkout_id' => $d->checkout_id === null ? null : VoucherCheckout::whereKey($d->checkout_id)->value('public_id'),
            'resolution' => in_array($d->status, VoucherDispute::CLOSED, true) ? ['note' => $d->resolution_note, 'compensation' => $d->compensation,
                'amount_minor' => $d->compensation_amount_minor, 'at' => $d->resolved_at?->toIso8601String()] : null,
            'created_at' => $d->created_at->toIso8601String(),
        ];
        if ($withTimeline) {
            $out['timeline'] = VoucherDisputeEvent::where('dispute_id', $d->id)->orderBy('id')->get()
                ->map(fn (VoucherDisputeEvent $e) => ['by' => $e->actor_role, 'type' => $e->type, 'note' => $e->note, 'at' => $e->created_at->toIso8601String()])->all();
        }

        return $out;
    }
}
