<?php

namespace App\Jobs;

use App\Models\VoucherExport;
use App\Services\Vouchers\VoucherExports;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/** Builds a large merchant voucher CSV outside the request (Trello #17). */
class BuildVoucherExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    public function __construct(public readonly int $exportId)
    {
        $this->afterCommit();
    }

    public function handle(VoucherExports $exports): void
    {
        $export = VoucherExport::find($this->exportId);

        if ($export !== null) {
            $exports->build($export);
        }
    }

    public function failed(Throwable $e): void
    {
        VoucherExport::whereKey($this->exportId)->where('status', 'queued')->update(['status' => 'failed']);
    }
}
