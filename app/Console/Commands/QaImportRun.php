<?php

namespace App\Console\Commands;

use App\Exceptions\ApiException;
use App\Models\QaSchedule;
use App\Services\Qa\QaRuns;
use Illuminate\Console\Command;

/** For CI and staging runners: imports a JUnit report (and optional coverage map) without a browser session. */
class QaImportRun extends Command
{
    protected $signature = 'shopbonus:qa-import {report : JUnit XML file} {--suite=full} {--environment=staging} {--sha= : Tested Git SHA}
        {--schedule= : Schedule id} {--coverage= : JSON map {test_key: ["GET /api/v1/..."]}} {--mocked : Label every result MOCKED}';

    protected $description = 'Import executed test results into the Quality Center';

    public function handle(QaRuns $runs): int
    {
        $sha = (string) ($this->option('sha') ?: config('qa.release_sha'));
        $suite = (string) $this->option('suite');
        $environment = (string) $this->option('environment');
        if (preg_match('/^[0-9a-f]{7,64}$/', $sha) !== 1 || ! in_array($suite, QaSchedule::SUITES, true) || ! in_array($environment, QaSchedule::ENVIRONMENTS, true)) {
            $this->error('Invalid --sha, --suite or --environment.');

            return self::FAILURE;
        }
        $path = (string) $this->argument('report');
        if (! is_file($path) || filesize($path) > (int) config('qa.max_report_kb') * 1024) {
            $this->error('Report file missing or too large.');

            return self::FAILURE;
        }
        $schedule = $this->option('schedule') ? QaSchedule::where('public_id', $this->option('schedule'))->first() : null;
        try {
            $run = $runs->importJunit((string) file_get_contents($path), ['suite' => $suite, 'environment' => $environment, 'git_sha' => $sha,
                'mocked' => (bool) $this->option('mocked'), 'schedule_id' => $schedule?->id], null);
            $schedule?->forceFill(['last_run_at' => now()])->save();
            $this->info('Run '.$run->public_id.': '.json_encode($run->totals));
            if ($coverage = $this->option('coverage')) {
                $map = json_decode((string) file_get_contents((string) $coverage), true, 64, JSON_THROW_ON_ERROR);
                $this->info('Coverage: '.json_encode($runs->importCoverage(is_array($map) ? $map : [], null)));
            }
        } catch (ApiException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
