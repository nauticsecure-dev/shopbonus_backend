<?php

namespace App\Services\Qa;

use App\Enums\AuditCategory;
use App\Enums\PlatformRole;
use App\Exceptions\ApiException;
use App\Jobs\ExecuteQaStaging;
use App\Models\QaExecutionRequest;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Throwable;

class QaExecutions
{
    public function authorize(User $actor): void
    {
        $user = User::find($actor->id);
        if (! $user?->isActive() || (! $user->isSuperAdmin() && ! $user->hasPlatformRole(PlatformRole::QaTester))) {
            throw new ApiException('Current Quality Center execution permission is required.', 403, 'qa_execution_forbidden');
        }
    }

    public function available(string $sha): void
    {
        if (! app()->environment('staging') || ! config('qa.runner_enabled') || $sha !== config('qa.release_sha')) {
            throw ApiException::conflict('An enabled matching staging deployment is required.', 'qa_execution_unavailable');
        }
        $connection = config('qa.execution_queue_connection');
        $driver = is_string($connection) ? config('queue.connections.'.$connection.'.driver') : null;
        if (! is_string($driver) || in_array($driver, ['sync', 'null'], true) || (int) config('queue.connections.'.$connection.'.retry_after', 0) <= 3900) {
            throw ApiException::conflict('Staging execution requires an asynchronous queue.', 'qa_execution_queue_required');
        }
    }

    public function request(User $actor, string $sha, string $trigger, string $key): QaExecutionRequest
    {
        $this->authorize($actor);
        $this->available($sha);
        $row = DB::transaction(function () use ($actor, $sha, $trigger, $key) {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $this->authorize($actor);
            $row = QaExecutionRequest::firstOrCreate(['created_by' => $actor->id, 'request_key' => $key], ['deployment_sha' => $sha, 'trigger' => $trigger]);
            if ($row->deployment_sha !== $sha || $row->trigger !== $trigger) {
                throw ApiException::conflict('This execution request key belongs to a different plan.', 'qa_execution_key_conflict');
            }
            if ($row->wasRecentlyCreated) {
                app(AuditLogger::class)->record('qa.execution_requested', category: AuditCategory::Admin, actorUserId: $actor->id, metadata: ['execution' => $row->public_id, 'deployment_sha' => $sha, 'trigger' => $trigger]);
            }

            return $row;
        });
        if ($row->wasRecentlyCreated) {
            try {
                ExecuteQaStaging::dispatch($row->id);
            } catch (Throwable $error) {
                report($error);
                $row->forceFill(['status' => 'failed', 'failure_code' => 'queue_dispatch_failed', 'completed_at' => now()])->save();
                throw new ApiException('The staging execution could not be queued.', 503, 'qa_execution_dispatch_failed');
            }
        }

        return $row;
    }

    /** @return array<string,mixed> */
    public function present(QaExecutionRequest $row): array
    {
        return ['id' => $row->public_id, 'deployment_sha' => $row->deployment_sha, 'trigger' => $row->trigger, 'status' => $row->status, 'failure_code' => $row->failure_code,
            'evidence_complete' => $row->evidence_complete, 'release_approved' => false, 'created_at' => $row->created_at?->toIso8601String(),
            'started_at' => $row->started_at?->toIso8601String(), 'completed_at' => $row->completed_at?->toIso8601String()];
    }
}
