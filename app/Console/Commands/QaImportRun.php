<?php

namespace App\Console\Commands;

use App\Exceptions\ApiException;
use App\Models\QaInventorySnapshot;
use App\Models\QaSchedule;
use App\Models\QaTestRun;
use App\Services\Qa\QaRuns;
use Illuminate\Console\Command;

/** For CI and staging runners: imports a JUnit report (and optional coverage map) without a browser session. */
class QaImportRun extends Command
{
    protected $signature = 'shopbonus:qa-import {report : JUnit XML file} {--suite=full} {--environment=staging} {--sha= : Tested Git SHA}
        {--schedule= : Schedule id} {--coverage= : JSON map {test_key: ["GET /api/v1/..."]}} {--mocked : Label every result MOCKED}
        {--executed-at= : When CI ran the suite (ISO 8601); defaults to the import time}
        {--output= : New JSON artifact path; also writes a sanitized sibling .junit.xml}';

    protected $description = 'Import executed test results into the Quality Center';

    public function handle(QaRuns $runs): int
    {
        $sha = (string) ($this->option('sha') ?: config('qa.release_sha'));
        $suite = (string) $this->option('suite');
        $environment = (string) $this->option('environment');
        if (preg_match('/^[0-9a-f]{7,64}$/', $sha) !== 1 || ! in_array($suite, QaSchedule::SUITES, true) || ! in_array($environment, QaTestRun::ENVIRONMENTS, true)) {
            $this->error('Invalid --sha, --suite or --environment.');

            return self::FAILURE;
        }
        $path = (string) $this->argument('report');
        if (! is_file($path) || filesize($path) > (int) config('qa.max_report_kb') * 1024) {
            $this->error('Report file missing or too large.');

            return self::FAILURE;
        }
        $executedAt = $this->option('executed-at');
        if ($executedAt !== null && (strtotime((string) $executedAt) === false || strtotime((string) $executedAt) > time() || strtotime((string) $executedAt) < strtotime('-90 days'))) {
            $this->error('Invalid --executed-at (ISO 8601, not in the future, at most 90 days old).');

            return self::FAILURE;
        }
        $output = $this->option('output');
        $junitOutput = $output === null ? null : preg_replace('/\.json$/', '', (string) $output).'.junit.xml';
        if ($output !== null && (! str_ends_with((string) $output, '.json') || ! is_dir(dirname((string) $output))
            || ! is_writable(dirname((string) $output)) || file_exists((string) $output) || is_link((string) $output)
            || file_exists((string) $junitOutput) || is_link((string) $junitOutput))) {
            $this->error('--output requires a new .json file in a writable directory (no overwrite).');

            return self::FAILURE;
        }
        $schedule = $this->option('schedule') ? QaSchedule::where('public_id', $this->option('schedule'))->first() : null;
        if (($this->option('schedule') && $schedule === null) || ($schedule !== null && ($schedule->environment !== $environment || $schedule->suite !== $suite))) {
            $this->error('The schedule must exist and match the tested suite/environment.');

            return self::FAILURE;
        }

        try {
            $run = $runs->importJunit((string) file_get_contents($path), ['suite' => $suite, 'environment' => $environment, 'git_sha' => $sha,
                'mocked' => $environment === 'ci' || (bool) $this->option('mocked'), 'schedule_id' => $schedule?->id, 'executed_at' => $executedAt], null);
            $schedule?->forceFill(['last_run_at' => now()])->save();
            if ($output !== null) {
                [$json] = $runs->export($run, 'json');
                $artifact = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
                $artifact['execution'] = ['started_at' => $run->started_at?->toIso8601String(), 'finished_at' => $run->finished_at?->toIso8601String(),
                    'proves' => $environment === 'ci' ? 'Isolated backend regression execution; no deployed provider or browser acceptance.' : 'Imported execution; inspect result evidence and mocked flags.'];
                $snapshot = QaInventorySnapshot::where('git_sha', $sha)->latest('id')->first();
                $artifact['inventory'] = $snapshot === null ? null : ['git_sha' => $snapshot->git_sha, 'totals' => $snapshot->totals, 'diff' => $snapshot->diff];
                [$junit] = $runs->export($run, 'junit');
                foreach ([(string) $output => json_encode($artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), (string) $junitOutput => $junit] as $target => $bytes) {
                    $stream = @fopen($target, 'x');
                    if ($stream === false) {
                        $this->error('Artifact could not be created; the imported run is retained.');

                        return self::FAILURE;
                    }
                    try {
                        chmod($target, 0600);
                        if (fwrite($stream, $bytes) !== strlen($bytes)) {
                            $this->error('Artifact write was incomplete; the imported run is retained.');

                            return self::FAILURE;
                        }
                    } finally {
                        fclose($stream);
                    }
                }
            }

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
