<?php

namespace App\Jobs;

use App\Models\VoucherCodeBatch;
use App\Services\Vouchers\CodeImporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Commits a large code import outside the request. Idempotent: a batch is
 * inserted only while it is `committing`.
 */
class CommitCodeBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    public function __construct(public readonly int $batchId)
    {
        $this->afterCommit();
    }

    public function handle(CodeImporter $importer): void
    {
        $batch = VoucherCodeBatch::find($this->batchId);

        if ($batch !== null) {
            $importer->insert($batch);
        }
    }

    public function failed(Throwable $e): void
    {
        VoucherCodeBatch::whereKey($this->batchId)->where('status', 'committing')->update(['status' => 'failed']);
    }
}
