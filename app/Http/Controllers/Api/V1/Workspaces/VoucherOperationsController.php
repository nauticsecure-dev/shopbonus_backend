<?php

namespace App\Http\Controllers\Api\V1\Workspaces;

use App\Enums\AuditCategory;
use App\Enums\VoucherOrigin;
use App\Enums\VoucherStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\Consumer\DisputeController;
use App\Http\Controllers\Controller;
use App\Models\Voucher;
use App\Models\VoucherCode;
use App\Models\VoucherDispute;
use App\Models\VoucherExport;
use App\Services\Audit\AuditLogger;
use App\Services\Disputes\DisputeService;
use App\Services\Vouchers\VoucherExports;
use App\Services\Vouchers\VoucherInventory;
use App\Services\Workspaces\CurrentWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
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

    /** Streams the CSV; above the configured size it is built by a queued job instead (202 + export to poll). */
    public function export(Request $request, CurrentWorkspace $current, AuditLogger $audit, VoucherExports $exports): StreamedResponse|JsonResponse
    {
        $filters = $this->voucherFilters($request);
        $webshop = $current->webshop();
        $query = $exports->query($webshop->id, $filters)->orderBy('id');
        $rows = (clone $query)->count();
        if ($rows > (int) config('shopbonus.offers.voucher_export_sync_max_rows')) {
            $export = $exports->start($webshop->id, $current->membership()->user_id, Arr::except($filters, ['page', 'per_page']), $rows);

            return response()->json(['data' => $export->present()], 202);
        }
        $audit->record('vouchers.exported', category: AuditCategory::Voucher, webshopId: $webshop->id, actorUserId: $current->membership()->user_id,
            metadata: ['rows' => $rows]);

        return response()->streamDownload(function () use ($query, $exports) {
            $out = fopen('php://output', 'w');
            $exports->write($query, $out);
            fclose($out);
        }, 'vouchers-'.$webshop->public_id.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    public function showExport(CurrentWorkspace $current, string $export): JsonResponse
    {
        return response()->json(['data' => $this->findExport($current, $export)->present()]);
    }

    public function downloadExport(CurrentWorkspace $current, string $export, AuditLogger $audit): StreamedResponse
    {
        $model = $this->findExport($current, $export);
        if ($model->status === 'expired' || ($model->expires_at !== null && $model->expires_at->isPast())) {
            throw new ApiException('This export has expired; start a new one.', 410, 'export_expired');
        }
        if ($model->status !== 'ready' || $model->path === null || ! Storage::disk('local')->exists($model->path)) {
            throw ApiException::conflict('This export is not ready.', 'export_not_ready', ['status' => $model->status]);
        }
        $audit->record('vouchers.export_downloaded', category: AuditCategory::Voucher, resource: $model, webshopId: $model->webshop_id,
            actorUserId: $current->membership()->user_id, metadata: ['rows' => $model->row_count]);

        return Storage::disk('local')->download($model->path, 'vouchers-'.$current->webshop()->public_id.'.csv',
            ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** Only the member who requested it, in this workspace. */
    private function findExport(CurrentWorkspace $current, string $publicId): VoucherExport
    {
        return (Str::isUlid($publicId) ? VoucherExport::where('webshop_id', $current->webshop()->id)->where('requested_by', $current->membership()->user_id)
            ->where('public_id', Str::lower($publicId))->first() : null) ?? throw new ApiException('Export not found.', 404, 'export_not_found');
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
        return app(VoucherExports::class)->query($current->webshop()->id, $filters);
    }

    private function findVoucher(CurrentWorkspace $current, string $publicId): Voucher
    {
        return (Str::isUlid($publicId)
            ? Voucher::with(['code', 'offer.currentVersion'])->where('webshop_id', $current->webshop()->id)->where('public_id', Str::lower($publicId))->first()
            : null) ?? throw new ApiException('Voucher not found.', 404, 'voucher_not_found');
    }
}
