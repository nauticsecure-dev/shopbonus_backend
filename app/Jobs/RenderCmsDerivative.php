<?php

namespace App\Jobs;

use App\Models\CmsMediaDerivative;
use App\Services\Cms\CmsMedia;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RenderCmsDerivative implements ShouldQueue
{
    use Dispatchable,InteractsWithQueue,Queueable,SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public int $derivativeId)
    {
        $this->onConnection((string) config('cms.media_queue_connection', 'database'));
        $this->onQueue('cms-media');
        $this->afterCommit();
    }

    public function handle(CmsMedia $media): void
    {
        $media->render($this->derivativeId);
    }

    public function failed(?\Throwable $e): void
    {
        CmsMediaDerivative::whereKey($this->derivativeId)->where('status', 'processing')->update(['status' => 'failed', 'failure_code' => 'render_interrupted']);
    }
}
