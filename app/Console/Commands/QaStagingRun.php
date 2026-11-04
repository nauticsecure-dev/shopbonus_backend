<?php

namespace App\Console\Commands;

use App\Services\Qa\QaStagingRunner;
use Illuminate\Console\Command;
use Throwable;

class QaStagingRun extends Command
{
    protected $signature = 'shopbonus:qa-staging-run {trigger : nightly, weekly or after_deploy} {--sha= : Exact configured deployment SHA}';

    protected $description = 'Execute reviewed staging scripts, import actual results and block unless independent acceptance passes';

    public function handle(QaStagingRunner $runner): int
    {
        $sha = (string) ($this->option('sha') ?: config('qa.release_sha'));
        try {
            $this->line(json_encode($runner->execute((string) $this->argument('trigger'), $sha), JSON_THROW_ON_ERROR));

            // Do not permit a success-only switch: regression execution cannot promote a release.
            $general = $this->call('shopbonus:qa-acceptance-check', ['--sha' => $sha, '--discover' => true]);
            $content = $this->call('shopbonus:cms-content-check', ['--sha' => $sha]);

            return $general === self::SUCCESS && $content === self::SUCCESS ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $e) {
            report($e);
            $this->line(json_encode(['execution_complete' => false, 'evidence_complete' => false, 'release_approved' => false, 'blockers' => ['staging_runner_unavailable_or_failed']]));

            return self::FAILURE;
        }
    }
}
