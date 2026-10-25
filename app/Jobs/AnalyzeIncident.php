<?php

namespace App\Jobs;

use App\Services\Admin\Diagnostics\IncidentDiagnostics;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class AnalyzeIncident implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public readonly int $diagnosticId) {}

    public function handle(IncidentDiagnostics $diagnostics): void
    {
        $diagnostics->execute($this->diagnosticId);
    }

    public function failed(?\Throwable $exception): void
    {
        app(IncidentDiagnostics::class)->fail($this->diagnosticId, 'job_failed');
    }
}
