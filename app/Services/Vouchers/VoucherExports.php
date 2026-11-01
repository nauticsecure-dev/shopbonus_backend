<?php

namespace App\Services\Vouchers;

use App\Enums\AuditCategory;
use App\Http\Controllers\Api\V1\Orders\OrderController;
use App\Jobs\BuildVoucherExport;
use App\Models\Offer;
use App\Models\Voucher;
use App\Models\VoucherExport;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Merchant voucher CSV (Trello #17). Small exports stream in the request;
 * large ones are built by a queued job into a private file that only the
 * requester can download until it expires. Both paths write the same CSV.
 */
class VoucherExports
{
    public const DIR = 'voucher-exports';

    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array<string, mixed> $filters
     * @return Builder<Voucher>
     */
    public function query(int $webshopId, array $filters): Builder
    {
        $offerId = isset($filters['offer']) && Str::isUlid($filters['offer'])
            ? Offer::where('webshop_id', $webshopId)->where('public_id', Str::lower($filters['offer']))->value('id')
            : null;

        return Voucher::query()->where('webshop_id', $webshopId)
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

    /**
     * @param  Builder<Voucher>  $query
     * @param  resource  $out
     */
    public function write(Builder $query, $out): int
    {
        $rows = 0;
        fputcsv($out, ['voucher_id', 'status', 'origin', 'code', 'offer_id', 'issued_at', 'expires_at', 'redeemed_at'], escape: '');
        $query->with(['code', 'offer'])->chunkById(500, function ($vouchers) use ($out, &$rows) {
            foreach ($vouchers as $v) {
                fputcsv($out, array_map([OrderController::class, 'csvSafe'], [
                    $v->public_id, $v->status->value, $v->origin->value, '****'.$v->code->code_last4, $v->offer?->public_id,
                    $v->issued_at?->toIso8601String(), $v->expires_at?->toIso8601String(), $v->redeemed_at?->toIso8601String(),
                ]), escape: '');
                $rows++;
            }
        });

        return $rows;
    }

    /** @param array<string, mixed> $filters */
    public function start(int $webshopId, int $userId, array $filters, int $expectedRows): VoucherExport
    {
        $export = VoucherExport::create(['webshop_id' => $webshopId, 'requested_by' => $userId, 'filters' => $filters, 'status' => 'queued']);
        $this->audit->record('vouchers.export_queued', category: AuditCategory::Voucher, resource: $export, webshopId: $webshopId,
            actorUserId: $userId, metadata: ['rows' => $expectedRows]);
        BuildVoucherExport::dispatch($export->id);

        return $export;
    }

    /** Job body; a rerun after a crash rebuilds the same file. */
    public function build(VoucherExport $export): void
    {
        if ($export->status !== 'queued') {
            return;
        }
        $path = self::DIR."/{$export->public_id}.csv";
        $tmp = fopen('php://temp', 'w+');
        $rows = $this->write($this->query($export->webshop_id, $export->filters)->orderBy('id'), $tmp);
        rewind($tmp);
        Storage::disk('local')->put($path, $tmp);
        fclose($tmp);

        $export->forceFill(['status' => 'ready', 'path' => $path, 'row_count' => $rows, 'size_bytes' => Storage::disk('local')->size($path),
            'completed_at' => now(), 'expires_at' => now()->addHours((int) config('shopbonus.offers.voucher_export_ttl_hours'))])->save();
    }

    /** Scheduled: removes expired files. Returns the count expired. */
    public function prune(): int
    {
        $count = 0;
        VoucherExport::where('status', 'ready')->where('expires_at', '<=', now())->each(function (VoucherExport $e) use (&$count) {
            if ($e->path !== null) {
                Storage::disk('local')->delete($e->path);
            }
            $e->forceFill(['status' => 'expired', 'path' => null])->save();
            $count++;
        });

        return $count;
    }
}
