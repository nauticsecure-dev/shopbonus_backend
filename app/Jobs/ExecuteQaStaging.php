<?php

namespace App\Jobs;

use App\Enums\AuditCategory;
use App\Models\QaExecutionRequest;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Qa\QaExecutions;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Throwable;

/** One explicit intent, one attempt; the existing runner owns its global staging lock and gates. */
class ExecuteQaStaging implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 3900;

    public function __construct(public int $executionId)
    {
        $this->onConnection((string) config('qa.execution_queue_connection', 'qa-staging'));
        $this->onQueue('qa-staging');
        $this->afterCommit();
    }

    public function handle(QaExecutions $service): void
    {
        $row = DB::transaction(function () use ($service) {
            $row = QaExecutionRequest::whereKey($this->executionId)->lockForUpdate()->firstOrFail();
            if ($row->status !== 'queued') {
                return null;
            }
            try {
                $service->authorize(User::findOrFail($row->created_by));
                $service->available($row->deployment_sha);
            } catch (Throwable) {
                $row->forceFill(['status' => 'blocked', 'failure_code' => 'execution_authority_or_deployment_changed', 'completed_at' => now()])->save();

                return null;
            }
            $row->forceFill(['status' => 'running', 'started_at' => now()])->save();

            return $row;
        });
        if ($row === null) {
            return;
        }
        // Never expose console logs or provider credentials in browser responses.
        $exit = Artisan::call('shopbonus:qa-staging-run', ['trigger' => $row->trigger, '--sha' => $row->deployment_sha]);
        $row->forceFill(['status' => $exit === 0 ? 'completed' : 'blocked', 'evidence_complete' => $exit === 0,
            'failure_code' => $exit === 0 ? null : 'staging_execution_or_acceptance_incomplete', 'completed_at' => now()])->save();
        app(AuditLogger::class)->record('qa.execution_finished', category: AuditCategory::Admin, actorUserId: $row->created_by, metadata: ['execution' => $row->public_id, 'status' => $row->status, 'evidence_complete' => $row->evidence_complete, 'release_approved' => false]);
    }

    public function failed(?Throwable $error): void
    {
        QaExecutionRequest::whereKey($this->executionId)->whereIn('status', ['queued', 'running'])->update(['status' => 'failed', 'failure_code' => 'execution_interrupted', 'completed_at' => now()]);
    }
}
