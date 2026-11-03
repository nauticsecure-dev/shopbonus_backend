<?php

namespace App\Jobs;

use App\Services\Support\SupportChat;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class ProcessSupportMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly int $messageId) {}

    public function handle(SupportChat $chat): void
    {
        Cache::put('support-ai:last-worker-execution-at', now()->toIso8601String(), 86400);
        $chat->execute($this->messageId);
    }

    public function failed(?Throwable $e): void
    {
        app(SupportChat::class)->failed($this->messageId);
    }
}
