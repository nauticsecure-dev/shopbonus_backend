<?php

namespace App\Jobs;

use App\Services\Cms\CmsSuggestions;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class GenerateCmsSuggestion implements ShouldQueue
{
    use Queueable;

    // An ambiguous timeout must not silently cause a second billed provider call.
    public int $tries = 1;

    public int $timeout = 45;

    public function __construct(public readonly int $suggestionId) {}

    public function handle(CmsSuggestions $suggestions): void
    {
        $suggestions->process($this->suggestionId);
    }

    public function failed(?Throwable $exception): void
    {
        app(CmsSuggestions::class)->fail($this->suggestionId);
    }
}
