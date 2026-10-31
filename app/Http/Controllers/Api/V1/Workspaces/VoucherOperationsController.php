<?php

namespace App\Http\Controllers\Api\V1\Workspaces;

use App\Enums\AuditCategory;
use App\Enums\VoucherOrigin;
use App\Enums\VoucherStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\Consumer\DisputeController;
use App\Http\Controllers\Api\V1\Orders\OrderController;
use App\Http\Controllers\Controller;
use App\Models\Offer;
use App\Models\Voucher;
use App\Models\VoucherCode;
use App\Models\VoucherDispute;
use App\Services\Audit\AuditLogger;
use App\Services\Disputes\DisputeService;
use App\Services\Vouchers\VoucherInventory;
use App\Services\Workspaces\CurrentWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Merchant voucher operations (Trello #20 "Redemption & validation",
 * disputes): verify a redemption, see and answer reports about this
 * webshop's vouchers. Never balances, never other webshops.
 */
class VoucherOperationsController extends Controller
{
    public function redeem(Request $request, CurrentWorkspace $current, VoucherInventory $inventory, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:100'],
            'voucher_id' => ['sometimes', 'nullable', 'string', 'max:40'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);
        $webshop = $current->webshop();

        $code = VoucherCode::where('webshop_id', $webshop->id)->where('code_hash', VoucherCode::hashFor($webshop->id, $data['code']))->first(['id', 'reusable']);
        // A reusable code backs many consumers' vouchers and cannot say whose was used:
        // the merchant names the voucher the customer showed.
        if ($code?->reusable && ($data['voucher_id'] ?? null) === null) {
            throw new ApiException('This code is shared by several customers; give the voucher ID the customer shows.', 422, 'voucher_id_required');
        }
        $voucher = $code === null ? null : Voucher::where('voucher_code_id', $code->id)->whereIn('status', [VoucherStatus::Issued, VoucherStatus::Redeemed, VoucherStatus::Expired])
            ->when($code->reusable, fn ($q) => $q->where('public_id', $data['voucher_id']))->first();
        if ($voucher === null) {
            $audit->recordSafely('voucher.redeem_unknown_code', category: AuditCategory::Voucher, webshopId: $webshop->id, actorUserId: $current->membership()->user_id);

            throw new ApiException('No issued voucher of this webshop has that code.', 404, 'voucher_not_found');
        }

        $voucher = $inventory->redeemByMerchant($voucher, $current->membership()->user_id, $data['reference'] ?? null);

        return response()->json(['data' => ['voucher_id' => $voucher->public_id, 'status' => $voucher->status->value, 'redeemed_at' => $voucher->redeemed_at?->toIso8601String(),
            'source' => $voucher->redemption_source, 'reference' => $voucher->redemption_reference]]);
    }

    public function index(Request $request, CurrentWorkspace $current): JsonResponse
    {
        $filters = $this->voucherFilters($request);
        $page = $this->voucherQuery($current, $filters)->with(['code', 'offer.currentVersion'])->latest('id')->paginate((int) ($filters['per_page'] ?? 25));

        return response()->json([
            'data' => collect($page->items())->map(fn (Voucher $v) => $this->presentVoucher($v))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function show(CurrentWorkspace $current, string $voucher): JsonResponse
    {
        return response()->json(['data' => $this->presentVoucher($this->findVoucher($current, $voucher), detailed: true)]);
    }

    public function export(Request $request, CurrentWorkspace $current, AuditLogger $audit): StreamedResponse
    {
        $filters = $this->voucherFilters($request);
        $query = $this->voucherQuery($current, $filters)->with(['code', 'offer'])->orderBy('id');
        $webshop = $current->webshop();
        $audit->record('vouchers.exported', category: AuditCategory::Voucher, webshopId: $webshop->id, actorUserId: $current->membership()->user_id,
            metadata: ['rows' => (clone $query)->count()]);

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['voucher_id', 'status', 'origin', 'code', 'offer_id', 'issued_at', 'expires_at', 'redeemed_at'], escape: '');
            $query->chunkById(500, function ($vouchers) use ($out) {
                foreach ($vouchers as $voucher) {
                    $row = $this->presentVoucher($voucher);
                    fputcsv($out, array_map([OrderController::class, 'csvSafe'], [
                        $row['id'], $row['status'], $row['origin'], $row['code'], $row['offer_id'], $row['issued_at'], $row['expires_at'], $row['redeemed_at'],
                    ]), escape: '');
                }
            });
            fclose($out);
        }, 'vouchers-'.$webshop->public_id.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    public function dispute(Request $request, CurrentWorkspace $current, string $voucher, DisputeService $disputes): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', Rule::in(VoucherDispute::REASONS)],
            'description' => ['required', 'string', 'min:10', 'max:2000', 'not_regex:/<[^>]*>/'],
        ]);
        $model = $this->findVoucher($current, $voucher);

        return response()->json(['data' => self::merchantView($disputes->openByMerchant($current->membership()->user_id, $model, $data['reason'], $data['description']), withTimeline: true)], 201);
    }

    public function disputes(Request $request, CurrentWorkspace $current): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(['open', 'under_review', 'waiting_for_merchant', 'resolved', 'rejected'])],
            'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $page = VoucherDispute::where('webshop_id', $current->webshop()->id)->when(isset($data['status']), fn ($q) => $q->where('status', $data['status']))
            ->orderByDesc('id')->paginate((int) ($data['per_page'] ?? 25));

        return response()->json([
            'data' => collect($page->items())->map(fn (VoucherDispute $d) => self::merchantView($d))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
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

    /**
     * Merchant voucher row. Masked code only; no consumer identity.
     *
     * @return array<string, mixed>
     */
    private function presentVoucher(Voucher $voucher, bool $detailed = false): array
    {
        $row = [
            'id' => $voucher->public_id,
            'status' => $voucher->status->value,
            'origin' => $voucher->origin->value,
            'code' => '****'.$voucher->code->code_last4,
            'offer_id' => $voucher->offer?->public_id,
            // The name the voucher was issued under, not a later edit of the offer.
            'offer_name' => $voucher->terms_snapshot['name'] ?? $voucher->offer?->currentVersion?->name,
            'issued_at' => $voucher->issued_at?->toIso8601String(),
            'expires_at' => $voucher->expires_at?->toIso8601String(),
            'redeemed_at' => $voucher->redeemed_at?->toIso8601String(),
            'reserved_at' => $voucher->reserved_at?->toIso8601String(),
        ];
        if ($detailed) {
            $row['terms'] = $voucher->terms_snapshot;
            $row['redemption_source'] = $voucher->redemption_source;
        }

        return $row;
    }

    /** @return array<string, mixed> */
    private function voucherFilters(Request $request): array
    {
        return $request->validate([
            'status' => ['sometimes', Rule::enum(VoucherStatus::class)],
            'origin' => ['sometimes', Rule::enum(VoucherOrigin::class)],
            'offer' => ['sometimes', 'string', 'size:26'],
            'search' => ['sometimes', 'string', 'max:100'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
    }

    /** @param  array<string, mixed>  $filters
     * @return Builder<Voucher>
     */
    private function voucherQuery(CurrentWorkspace $current, array $filters): Builder
    {
        $offerId = isset($filters['offer']) && Str::isUlid($filters['offer'])
            ? Offer::where('webshop_id', $current->webshop()->id)->where('public_id', Str::lower($filters['offer']))->value('id')
            : null;

        return Voucher::query()->where('webshop_id', $current->webshop()->id)
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['origin'] ?? null, fn ($q, $o) => $q->where('origin', $o))
            ->when(isset($filters['offer']), fn ($q) => $q->where('offer_id', $offerId ?? 0))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('issued_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('issued_at', '<=', $to))
            ->when($filters['search'] ?? null, function ($q, $search) {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $q->where(fn ($w) => $w->whereHas('code', fn ($c) => $c->where('code_last4', $search))
                    ->orWhereHas('offer.currentVersion', fn ($v) => $v->where('name', 'like', $like)));
            });
    }

    private function findVoucher(CurrentWorkspace $current, string $publicId): Voucher
    {
        return (Str::isUlid($publicId)
            ? Voucher::with(['code', 'offer.currentVersion'])->where('webshop_id', $current->webshop()->id)->where('public_id', Str::lower($publicId))->first()
            : null) ?? throw new ApiException('Voucher not found.', 404, 'voucher_not_found');
    }
}
