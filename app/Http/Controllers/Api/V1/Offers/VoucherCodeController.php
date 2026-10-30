<?php

namespace App\Http\Controllers\Api\V1\Offers;

use App\Enums\AuditCategory;
use App\Enums\VoucherCodeStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\Orders\OrderController;
use App\Http\Controllers\Controller;
use App\Http\Resources\VoucherCodeResource;
use App\Models\Offer;
use App\Models\VoucherCode;
use App\Models\VoucherCodeBatch;
use App\Models\VoucherEvent;
use App\Services\Audit\AuditLogger;
use App\Services\Vouchers\CodeImporter;
use App\Services\Vouchers\PlatformCouponVerification;
use App\Services\Workspaces\CurrentWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Per-offer code sheet, import and code actions (Trello #7 §5–7).
 */
class VoucherCodeController extends Controller
{
    public function __construct(
        private readonly CodeImporter $importer,
        private readonly AuditLogger $audit,
        private readonly PlatformCouponVerification $platformCoupons,
    ) {}

    public function index(Request $request, CurrentWorkspace $current, string $offer): JsonResponse
    {
        $model = $this->offer($current, $offer);
        $filters = $this->filters($request);
        $page = $this->query($model, $filters)->with(['batch', 'currentVoucher.consumer'])->paginate($filters['per_page'] ?? 50)->withQueryString();

        return VoucherCodeResource::collection($page)->additional(['meta' => ['counters' => $this->counters($model)]])->response()->setStatusCode(200);
    }

    public function import(Request $request, CurrentWorkspace $current, string $offer): JsonResponse
    {
        $model = $this->offer($current, $offer);
        $data = $request->validate([
            'codes' => ['required_without:file', 'string', 'max:3500000'],
            'file' => ['required_without:codes', 'file', 'max:5120'],
        ]);

        [$lines, $source] = isset($data['file'])
            ? [$this->importer->linesFromFile($request->file('file')), 'file']
            : [preg_split('/\r\n|\r|\n/', (string) $data['codes']) ?: [], 'paste'];

        return $this->batchResponse($this->importer->preview($current->membership(), $model, $lines, $source), 201);
    }

    public function showBatch(CurrentWorkspace $current, string $batch): JsonResponse
    {
        return $this->batchResponse($this->batch($current, $batch), 200);
    }

    public function commitBatch(CurrentWorkspace $current, string $batch): JsonResponse
    {
        $committed = $this->importer->commit($current->membership(), $this->batch($current, $batch));

        return $this->batchResponse($committed, $committed->status === 'committing' ? 202 : 200);
    }

    public function reveal(CurrentWorkspace $current, string $code): JsonResponse
    {
        $model = $this->code($current, $code);
        $this->audit->record('codes.revealed', category: AuditCategory::Voucher, resource: $model, webshopId: $model->webshop_id);

        return response()->json(['data' => ['id' => $model->public_id, 'code' => $model->code]]);
    }

    public function invalidate(Request $request, CurrentWorkspace $current, string $code): JsonResponse
    {
        $reason = (string) $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']])['reason'];
        $model = $this->code($current, $code);

        // Only unused codes: reserved/issued codes belong to a consumer flow and redeemed ones are history.
        $updated = VoucherCode::whereKey($model->id)->where('status', VoucherCodeStatus::Available)
            ->update(['status' => VoucherCodeStatus::Invalid->value, 'invalidated_at' => now(), 'invalid_reason' => $reason, 'updated_at' => now()]);

        if ($updated !== 1) {
            throw ApiException::conflict('Only available codes can be invalidated.', 'code_not_available', ['status' => $model->status->value]);
        }

        VoucherEvent::create(['webshop_id' => $model->webshop_id, 'voucher_code_id' => $model->id, 'type' => 'code_invalidated',
            'from_status' => 'available', 'to_status' => 'invalid', 'actor_user_id' => $current->membership()->user_id, 'summary' => ['reason' => $reason]]);
        $this->audit->record('codes.invalidated', category: AuditCategory::Voucher, resource: $model, webshopId: $model->webshop_id, metadata: ['reason' => $reason]);

        return VoucherCodeResource::make($model->refresh()->load(['batch', 'currentVoucher.consumer']))->response()->setStatusCode(200);
    }

    public function history(CurrentWorkspace $current, string $code): JsonResponse
    {
        $model = $this->code($current, $code);
        $events = VoucherEvent::where('voucher_code_id', $model->id)->orderBy('id')->get()->map(fn (VoucherEvent $e) => [
            'type' => $e->type, 'from_status' => $e->from_status, 'to_status' => $e->to_status, 'summary' => $e->summary,
            'recorded_at' => $e->created_at->toIso8601String(),
        ]);

        return response()->json(['data' => $events]);
    }

    /**
     * Provider coupon check or controlled merchant confirmation (Trello #7 §8).
     * Never claims Verified unless the platform matched or the merchant confirmed.
     */
    public function verifyPlatform(Request $request, CurrentWorkspace $current, string $code): JsonResponse
    {
        $data = $request->validate([
            'mode' => ['required', Rule::in(['provider_check', 'manual_confirm'])],
            'note' => ['required_if:mode,manual_confirm', 'nullable', 'string', 'min:3', 'max:255'],
        ]);
        $model = $this->code($current, $code)->load('offer.currentVersion');

        $actor = $current->membership()->user()->firstOrFail();
        $result = $data['mode'] === 'manual_confirm'
            ? $this->platformCoupons->confirmManually($model, $actor, (string) $data['note'])
            : $this->platformCoupons->verifyAgainstProvider($model, $model->offer?->currentVersion);

        return VoucherCodeResource::make($model->refresh()->load(['batch', 'currentVoucher.consumer']))
            ->additional(['verification' => $result])
            ->response()
            ->setStatusCode(200);
    }

    public function importTemplate(CurrentWorkspace $current, string $offer): StreamedResponse
    {
        $this->offer($current, $offer);

        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['code'], escape: '');
            fputcsv($out, ['SHOP-CODE-0001'], escape: '');
            fclose($out);
        }, 'code-import-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    public function export(Request $request, CurrentWorkspace $current, string $offer): StreamedResponse
    {
        $model = $this->offer($current, $offer);
        $filters = $this->filters($request);
        $query = $this->query($model, $filters)->with('batch');

        $this->audit->record('codes.exported', category: AuditCategory::Voucher, resource: $model, webshopId: $model->webshop_id,
            metadata: ['rows' => (clone $query)->count(), 'filters' => array_diff_key($filters, ['search' => true])]);

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['code', 'status', 'batch', 'platform_validation', 'added_at', 'issued_at', 'redeemed_at'], escape: '');
            $query->chunkById(500, function ($codes) use ($out) {
                foreach ($codes as $c) {
                    fputcsv($out, array_map([OrderController::class, 'csvSafe'], [$c->code, $c->status->value, $c->batch?->public_id,
                        $c->platform_validation, $c->created_at->toIso8601String(), $c->issued_at?->toIso8601String(), $c->redeemed_at?->toIso8601String()]), escape: '');
                }
            });
            fclose($out);
        }, 'codes-'.$model->public_id.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    /**
     * Counters over every status, so they always reconcile with the total.
     *
     * @return array<string, int>
     */
    private function counters(Offer $offer): array
    {
        $counts = VoucherCode::where('offer_id', $offer->id)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $all = collect(VoucherCodeStatus::cases())->mapWithKeys(fn ($s) => [$s->value => (int) ($counts[$s->value] ?? 0)])->all();

        return ['total' => array_sum($all), ...$all];
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        return $request->validate([
            'status' => ['sometimes', Rule::enum(VoucherCodeStatus::class)],
            'batch' => ['sometimes', 'string', 'size:26'],
            'search' => ['sometimes', 'string', 'max:64'],
            'assigned' => ['sometimes', 'boolean'],
            'platform_validation' => ['sometimes', 'string', 'max:64'],
            'added_from' => ['sometimes', 'date'],
            'added_to' => ['sometimes', 'date', 'after_or_equal:added_from'],
            'sort' => ['sometimes', Rule::in(['created_at', '-created_at', 'status', '-status'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<VoucherCode>
     */
    private function query(Offer $offer, array $filters): Builder
    {
        $sort = $filters['sort'] ?? '-created_at';
        $batchId = isset($filters['batch']) ? VoucherCodeBatch::where('offer_id', $offer->id)->where('public_id', Str::lower($filters['batch']))->value('id') : null;

        return VoucherCode::query()->where('offer_id', $offer->id)
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when(isset($filters['batch']), fn ($q) => $q->where('batch_id', $batchId ?? 0))
            ->when(isset($filters['search']), fn ($q) => $q->where('code_hash', VoucherCode::hashFor($offer->webshop_id, (string) $filters['search'])))
            ->when(isset($filters['assigned']), function ($q) use ($filters) {
                $live = fn ($v) => $v->whereIn('status', ['reserved', 'issued', 'redeemed']);

                return filter_var($filters['assigned'], FILTER_VALIDATE_BOOLEAN) ? $q->whereHas('currentVoucher', $live) : $q->whereDoesntHave('currentVoucher', $live);
            })
            ->when($filters['platform_validation'] ?? null, fn ($q, $v) => $q->where('platform_validation', $v))
            ->when($filters['added_from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', $v))
            ->when($filters['added_to'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', $v))
            ->orderBy(ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc')
            ->orderBy('id', str_starts_with($sort, '-') ? 'desc' : 'asc');
    }

    private function batchResponse(VoucherCodeBatch $batch, int $status): JsonResponse
    {
        return response()->json(['data' => [
            'id' => $batch->public_id,
            'status' => $batch->status,
            'source' => $batch->source,
            'totals' => $batch->totals,
            'rejections' => $batch->rejections ?? [],
            'expires_at' => $batch->status === 'preview' ? $batch->expires_at->toIso8601String() : null,
            'committed_at' => $batch->committed_at?->toIso8601String(),
        ]], $status);
    }

    private function offer(CurrentWorkspace $current, string $publicId): Offer
    {
        return (Str::isUlid($publicId)
            ? Offer::with('currentVersion')->where('webshop_id', $current->webshop()->id)->where('public_id', Str::lower($publicId))->first()
            : null) ?? throw new NotFoundHttpException;
    }

    private function batch(CurrentWorkspace $current, string $publicId): VoucherCodeBatch
    {
        return (Str::isUlid($publicId)
            ? VoucherCodeBatch::where('webshop_id', $current->webshop()->id)->where('public_id', Str::lower($publicId))->first()
            : null) ?? throw new NotFoundHttpException;
    }

    private function code(CurrentWorkspace $current, string $publicId): VoucherCode
    {
        return (Str::isUlid($publicId)
            ? VoucherCode::where('webshop_id', $current->webshop()->id)->where('public_id', Str::lower($publicId))->first()
            : null) ?? throw new NotFoundHttpException;
    }
}
