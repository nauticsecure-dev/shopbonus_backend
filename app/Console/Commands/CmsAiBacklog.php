<?php

namespace App\Console\Commands;

use App\Exceptions\ApiException;
use App\Models\CmsAiBulkItem;
use App\Models\CmsAiBulkRun;
use App\Models\User;
use App\Services\Cms\CmsAiBacklog as Backlogs;
use Illuminate\Console\Command;

class CmsAiBacklog extends Command
{
    protected $signature = 'shopbonus:cms-ai-backlog {action : plan, advance, submit or show} {--run= : Existing run ULID} {--actor= : Current Super Admin numeric user ID} {--kind=translate} {--source=nl} {--target=de} {--limit=1000} {--batch=100} {--steps=1}';

    protected $description = 'Plan and submit bounded version-pinned saved-page AI backlogs; never apply or publish';

    public function handle(Backlogs $service): int
    {
        $actor = User::find((int) $this->option('actor'));
        if (! $actor?->isActive() || ! $actor->isSuperAdmin()) {
            $this->error('Pass an existing active Super Admin --actor ID.');

            return self::FAILURE;
        }
        $action = (string) $this->argument('action');
        $batch = filter_var($this->option('batch'), FILTER_VALIDATE_INT);
        $steps = filter_var($this->option('steps'), FILTER_VALIDATE_INT);
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if (! in_array($action, ['plan', 'advance', 'submit', 'show'], true) || $batch === false || $batch < 1 || $batch > 200 || $steps === false || $steps < 1 || $steps > 20 || $limit === false || $limit < 1 || $limit > 100000) {
            $this->error('Invalid action or bounds.');

            return self::FAILURE;
        }
        try {
            $run = $action === 'plan' ? $service->plan($actor, (string) $this->option('kind'), (string) $this->option('source'), (string) $this->option('target'), $limit) : CmsAiBulkRun::where('public_id', $this->option('run'))->first();
            if ($run === null || $run->created_by !== $actor->id) {
                $this->error('Run not found for this actor.');

                return self::FAILURE;
            }
            for ($step = 0; $step < $steps; $step++) {
                if (in_array($action, ['plan', 'advance'], true)) {
                    $run = $service->advancePlan($run, $batch);
                    if ($run->status !== 'planning') {
                        break;
                    }
                }
                if ($action === 'submit') {
                    $run = $service->submit($run, $batch);
                    if ($run->status !== 'planned') {
                        break;
                    }
                }
            }
            $this->line(json_encode(['run' => $run->public_id, 'status' => $run->status, 'source_cursor' => $run->source_cursor, 'source_ceiling' => $run->source_ceiling, 'counts' => CmsAiBulkItem::where('run_id', $run->id)->selectRaw('status,count(*) as total')->groupBy('status')->pluck('total', 'status')->all(), 'scope' => 'Saved CMS pages only. Submission/worker completion is not reviewed translation, publication or staging acceptance.'], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (ApiException $e) {
            $this->error($e->errorCode.': '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
