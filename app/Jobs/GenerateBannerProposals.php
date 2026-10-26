<?php

namespace App\Jobs;

use App\Services\Banners\BannerAi;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class GenerateBannerProposals implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public readonly int $requestId) {}

    public function handle(BannerAi $ai): void
    {
        $ai->execute($this->requestId);
    }

    public function failed(?\Throwable $exception): void
    {
        app(BannerAi::class)->fail($this->requestId, 'job_failed');
    }
}
