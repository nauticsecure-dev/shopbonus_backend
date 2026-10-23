<?php

namespace App\Listeners;

use App\Services\Errors\ErrorRecorder;
use Illuminate\Queue\Events\JobFailed;

/**
 * Records a job's final failure (after all retries) in application_errors,
 * correlated to the originating request via Context. Laravel still writes
 * the payload to failed_jobs for retrying.
 */
class RecordFailedJob
{
    public function __construct(private readonly ErrorRecorder $errors) {}

    public function handle(JobFailed $event): void
    {
        $this->errors->recordJobFailure(
            $event->exception,
            $event->job->resolveName(),
            $event->job->getQueue(),
            $event->connectionName,
            [
                'job_uuid' => $event->job->uuid(),
                'attempts' => $event->job->attempts(),
            ],
        );
    }
}
