<?php

namespace App\Services\Qa;

use App\Enums\AuditCategory;
use App\Exceptions\ApiException;
use App\Logging\SensitiveDataProcessor;
use App\Models\QaComponent;
use App\Models\QaEndpoint;
use App\Models\QaEvidence;
use App\Models\QaTestCase;
use App\Models\QaTestResult;
use App\Models\QaTestRun;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use SimpleXMLElement;
use Throwable;

/**
 * Imports executed results into the Quality Center (Trello #37 §3–§4). Tests
 * themselves run in CI/staging from Git; this service only records what ran.
 *
 * JUnit mapping: <failure>/<error> → fail; <skipped> with a message starting
 * "BLOCKED:" → blocked, "NOT_IMPLEMENTED:" → not_implemented, otherwise
 * skipped; no child element → pass only with assertions > 0 (a test without
 * assertions is skipped, never PASS). A test whose name contains "MOCKED", or
 * every test in a run imported with mocked=true, is labelled mocked.
 * Unknown test keys are registered as draft cases; only approved cases count.
 */
class QaRuns
{
    public const EVIDENCE_DIR = 'qa-evidence';

    private const EVIDENCE_MIMES = ['image/png', 'image/jpeg', 'image/webp', 'application/pdf', 'application/json', 'text/plain', 'application/xml', 'text/xml'];

    public function __construct(private readonly AuditLogger $audit, private readonly SensitiveDataProcessor $redactor) {}

    /**
     * `executed_at` is when CI ran the suite; without it the import time stands in (and freshness says so).
     *
     * @param  array{suite: string, environment: string, git_sha: string, source?: string, mocked?: bool, schedule_id?: int|null, executed_at?: string|null}  $meta
     */
    public function importJunit(string $xml, array $meta, ?int $actorId): QaTestRun
    {
        $hash = hash('sha256', $xml);
        $existing = QaTestRun::where('report_hash', $hash)->first();
        if ($existing !== null) {
            throw ApiException::conflict('This report was already imported.', 'run_duplicate', ['run' => $existing->public_id]);
        }
        $cases = $this->parseJunit($xml, (bool) ($meta['mocked'] ?? false));
        if ($cases === []) {
            throw new ApiException('The report contains no test cases.', 422, 'validation_failed', ['errors' => ['report' => ['The report contains no test cases.']]]);
        }

        try {
            $run = DB::transaction(function () use ($cases, $meta, $hash, $actorId) {
                $known = [];
                foreach (array_chunk(array_unique(array_column($cases, 'key')), 500) as $chunk) {
                    $known += QaTestCase::whereIn('key', $chunk)->pluck('id', 'key')->all();
                }
                foreach (array_unique(array_column($cases, 'key')) as $key) {
                    if (! isset($known[$key])) {
                        $known[$key] = QaTestCase::create(['key' => $key, 'title' => Str::limit(Str::afterLast($key, '::'), 250), 'source' => $meta['source'] ?? 'phpunit',
                            'status' => 'draft', 'created_by' => $actorId])->id;
                    }
                }
                $totals = ['total' => count($cases), 'assertions' => array_sum(array_column($cases, 'assertions'))]
                    + array_fill_keys(['pass', 'fail', 'blocked', 'not_implemented', 'skipped'], 0)
                    + ['mocked' => count(array_filter($cases, fn ($c) => $c['mocked']))];
                foreach ($cases as $c) {
                    $totals[$c['status']]++;
                }
                $run = QaTestRun::create(['schedule_id' => $meta['schedule_id'] ?? null, 'suite' => $meta['suite'], 'environment' => $meta['environment'],
                    'git_sha' => $meta['git_sha'], 'source' => 'junit', 'report_hash' => $hash, 'totals' => $totals,
                    'duration_ms' => array_sum(array_column($cases, 'duration_ms')), 'created_by' => $actorId,
                    'started_at' => $started = isset($meta['executed_at']) ? Carbon::parse($meta['executed_at']) : now(),
                    'finished_at' => isset($meta['executed_at']) ? $started->copy()->addMilliseconds(array_sum(array_column($cases, 'duration_ms'))) : now()]);
                $now = now();
                foreach (array_chunk($cases, 500) as $chunk) {
                    QaTestResult::insert(array_map(fn ($c) => ['public_id' => strtolower((string) Str::ulid()), 'run_id' => $run->id, 'test_case_id' => $known[$c['key']],
                        'key' => $c['key'], 'status' => $c['status'], 'assertions' => $c['assertions'], 'mocked' => $c['mocked'],
                        'duration_ms' => $c['duration_ms'], 'message' => $c['message'], 'created_at' => $now], $chunk));
                }

                return $run;
            });
        } catch (UniqueConstraintViolationException) {
            throw ApiException::conflict('This report was already imported.', 'run_duplicate');
        }
        $this->audit->record('qa.run_imported', category: AuditCategory::Admin, actorUserId: $actorId,
            metadata: ['run' => $run->public_id, 'suite' => $run->suite, 'environment' => $run->environment, 'git_sha' => $run->git_sha, 'totals' => $run->totals]);

        return $run;
    }

    /**
     * @return list<array{key: string, status: string, assertions: int, mocked: bool, duration_ms: int, message: string|null}>
     */
    private function parseJunit(string $xml, bool $mockedRun): array
    {
        $previous = libxml_use_internal_errors(true);
        try {
            if (preg_match('/<!DOCTYPE/i', $xml) === 1) {
                throw new ApiException('DOCTYPE declarations are not accepted.', 422, 'validation_failed', ['errors' => ['report' => ['DOCTYPE declarations are not accepted.']]]);
            }
            $root = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
            if ($root === false) {
                throw new ApiException('The report is not valid JUnit XML.', 422, 'validation_failed', ['errors' => ['report' => ['The report is not valid JUnit XML.']]]);
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $out = [];
        foreach ($root->xpath('//testcase') ?: [] as $tc) {
            $class = (string) ($tc['class'] ?? str_replace('.', '\\', (string) ($tc['classname'] ?? '')));
            $name = (string) $tc['name'];
            $key = Str::limit(($class !== '' ? $class.'::' : '').$name, 250, '');
            if ($name === '') {
                continue;
            }
            $assertions = max(0, (int) ($tc['assertions'] ?? 0));
            $message = null;
            if (isset($tc->failure) || isset($tc->error)) {
                $node = isset($tc->failure) ? $tc->failure : $tc->error;
                $status = 'fail';
                $message = trim((string) ($node['message'] ?? '')."\n".(string) $node);
            } elseif (isset($tc->skipped)) {
                $message = trim((string) ($tc->skipped['message'] ?? '').' '.(string) $tc->skipped);
                $status = match (true) {
                    str_starts_with($message, 'BLOCKED:') => 'blocked',
                    str_starts_with($message, 'NOT_IMPLEMENTED:') => 'not_implemented',
                    default => 'skipped',
                };
            } elseif ($assertions > 0) {
                $status = 'pass';
            } else {
                $status = 'skipped';
                $message = 'No assertions executed; not counted as PASS.';
            }
            $out[] = ['key' => $key, 'status' => $status, 'assertions' => $assertions, 'mocked' => $mockedRun || stripos($name, 'mocked') !== false,
                'duration_ms' => (int) round(((float) ($tc['time'] ?? 0)) * 1000), 'message' => $message === null || $message === '' ? null : $this->redact($message)];
        }

        return $out;
    }

    /** Masks contact data, bearer tokens, IBANs and configured secrets; bounded length. */
    public function redact(string $text): string
    {
        $text = $this->redactor->redactText($text);
        foreach (['app.key', 'qa.api_key', 'cms.api_key', 'shopbonus.payments.mollie.key'] as $secret) {
            $value = (string) config($secret);
            if (strlen($value) >= 8) {
                $text = str_replace($value, SensitiveDataProcessor::MASK, $text);
            }
        }

        return Str::limit($text, 2000);
    }

    /**
     * Adds function-to-test links from a coverage map {test_key: ["GET /api/v1/...", "job:app/Jobs/X", ...]}.
     * Unknown keys or targets are reported, never invented.
     *
     * @param  array<string, list<string>>  $map
     * @return array{linked: int, unknown_tests: list<string>, unknown_targets: list<string>}
     */
    public function importCoverage(array $map, ?int $actorId): array
    {
        $endpoints = QaEndpoint::whereNull('removed_at')->get()->keyBy(fn ($e) => $e->method.' '.$e->path);
        $components = QaComponent::whereNull('removed_at')->get()->keyBy(fn ($c) => $c->kind.':'.$c->name);
        $cases = QaTestCase::whereIn('key', array_keys($map))->pluck('id', 'key');
        $rows = [];
        $unknownTests = [];
        $unknownTargets = [];
        foreach ($map as $key => $targets) {
            if (! $cases->has($key)) {
                $unknownTests[] = $key;

                continue;
            }
            foreach (array_unique($targets) as $t) {
                [$type, $target] = $endpoints->has($t) ? ['endpoint', $endpoints[$t]] : ($components->has($t) ? ['component', $components[$t]] : [null, null]);
                if ($target === null) {
                    $unknownTargets[] = $t;

                    continue;
                }
                $rows[] = ['test_case_id' => $cases[$key], 'target_type' => $type, 'target_id' => $target->id, 'created_at' => now(), 'updated_at' => now()];
            }
        }
        $linked = 0;
        foreach (array_chunk($rows, 500) as $chunk) {
            $linked += DB::table('qa_test_coverage')->insertOrIgnore($chunk);
        }
        $this->audit->record('qa.coverage_imported', category: AuditCategory::Admin, actorUserId: $actorId,
            metadata: ['linked' => $linked, 'unknown_tests' => count($unknownTests), 'unknown_targets' => count(array_unique($unknownTargets))]);

        return ['linked' => $linked, 'unknown_tests' => array_slice($unknownTests, 0, 100), 'unknown_targets' => array_slice(array_values(array_unique($unknownTargets)), 0, 100)];
    }

    /** Stores an encrypted private artifact; the same file for the same run is stored once. */
    public function storeEvidence(QaTestRun $run, UploadedFile $file, ?QaTestResult $result, ?string $requestId, int $actorId): QaEvidence
    {
        $bytes = (string) $file->get();
        $mime = (string) $file->getMimeType();
        if (! in_array($mime, self::EVIDENCE_MIMES, true)) {
            throw new ApiException('This file type is not accepted as evidence.', 422, 'validation_failed', ['errors' => ['file' => ['Upload PNG, JPEG, WebP, PDF, JSON, XML or plain text.']]]);
        }
        $sha = hash('sha256', $bytes);
        $existing = QaEvidence::where('run_id', $run->id)->where('sha256', $sha)->first();
        if ($existing !== null) {
            return $existing;
        }
        $id = (string) Str::uuid();
        $path = self::EVIDENCE_DIR."/{$run->public_id}/{$id}.enc";
        if (! Storage::disk('local')->put($path, Crypt::encryptString(base64_encode($bytes)))) {
            throw new ApiException('The evidence could not be stored. Please try again.', 503, 'storage_unavailable');
        }
        try {
            $evidence = QaEvidence::create(['run_id' => $run->id, 'result_id' => $result?->id, 'path' => $path,
                'name' => Str::limit(preg_replace('/[^A-Za-z0-9._-]/', '_', $file->getClientOriginalName()) ?: 'evidence', 100, ''),
                'mime' => $mime, 'size' => strlen($bytes), 'sha256' => $sha, 'request_id' => $requestId,
                'retain_until' => CarbonImmutable::now()->addDays(max(1, (int) config('qa.evidence_retention_days'))), 'uploaded_by' => $actorId]);
        } catch (Throwable $e) {
            Storage::disk('local')->delete($path);
            $race = QaEvidence::where('run_id', $run->id)->where('sha256', $sha)->first();
            if ($race !== null) {
                return $race;
            }
            throw $e;
        }
        $this->audit->record('qa.evidence_uploaded', category: AuditCategory::Admin, actorUserId: $actorId,
            metadata: ['evidence' => $evidence->public_id, 'run' => $run->public_id, 'sha256' => $sha, 'size' => $evidence->size]);

        return $evidence;
    }

    /** Current proof must still exist, decrypt and match its checksum. */
    public function evidenceAvailable(QaEvidence $evidence): bool
    {
        if ($evidence->retain_until->lessThanOrEqualTo(now())) {
            return false;
        }
        try {
            $this->evidenceBytes($evidence);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** Decrypted bytes, verified against the stored checksum. */
    public function evidenceBytes(QaEvidence $evidence): string
    {
        $raw = Storage::disk('local')->get($evidence->path);
        $bytes = $raw === null ? null : base64_decode(Crypt::decryptString($raw));
        if (! is_string($bytes) || ! hash_equals($evidence->sha256, hash('sha256', $bytes))) {
            throw new ApiException('The evidence file is missing or does not match its checksum.', 410, 'evidence_unavailable');
        }

        return $bytes;
    }

    /**
     * Deletes evidence past retention and probe runs older than 30 days.
     *
     * @return array{evidence: int, probe_runs: int}
     */
    public function prune(): array
    {
        $evidence = 0;
        QaEvidence::where('retain_until', '<', now())->orderBy('id')->chunkById(200, function ($rows) use (&$evidence) {
            foreach ($rows as $row) {
                Storage::disk('local')->delete($row->path);
                $row->delete();
                $evidence++;
            }
        });
        $probes = QaTestRun::where('source', 'probe')->where('created_at', '<', now()->subDays(30))->whereDoesntHave('evidence')->delete();

        return ['evidence' => $evidence, 'probe_runs' => $probes];
    }

    /** @return array{0: string, 1: string, 2: string} content, mime, file name */
    public function export(QaTestRun $run, string $format): array
    {
        $results = QaTestResult::where('run_id', $run->id)->orderBy('id')->get();
        $name = 'qa-run-'.$run->public_id;
        if ($format === 'json') {
            return [json_encode(['run' => self::runArray($run), 'results' => $results->map(fn ($r) => self::resultArray($r))->all()],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'application/json', $name.'.json'];
        }
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_XML1);
        if ($format === 'junit') {
            $t = $run->totals;
            $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<testsuites><testsuite name="'.$e($run->suite.'@'.$run->environment).'" tests="'.$t['total'].'" failures="'.$t['fail']
                .'" skipped="'.($t['blocked'] + $t['not_implemented'] + $t['skipped']).'" assertions="'.$t['assertions'].'" time="'.round($run->duration_ms / 1000, 3).'">'."\n";
            foreach ($results as $r) {
                [$class, $test] = str_contains($r->key, '::') ? explode('::', $r->key, 2) : ['', $r->key];
                $xml .= '<testcase classname="'.$e($class).'" name="'.$e($test).'" assertions="'.$r->assertions.'" time="'.round($r->duration_ms / 1000, 3).'">'
                    .match ($r->status) {
                        'fail' => '<failure message="'.$e(Str::limit((string) $r->message, 200)).'"/>',
                        'blocked' => '<skipped message="BLOCKED: '.$e(Str::after((string) $r->message, 'BLOCKED:')).'"/>',
                        'not_implemented' => '<skipped message="NOT_IMPLEMENTED: '.$e(Str::after((string) $r->message, 'NOT_IMPLEMENTED:')).'"/>',
                        'skipped' => '<skipped message="'.$e($r->message).'"/>',
                        default => '',
                    }."</testcase>\n";
            }

            return [$xml."</testsuite></testsuites>\n", 'application/xml', $name.'.xml'];
        }
        $h = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_HTML5);
        $rows = $results->map(fn ($r) => '<tr><td>'.$h($r->key).'</td><td>'.strtoupper($h($r->status)).($r->mocked ? ' (MOCKED)' : '').'</td><td>'.$r->assertions
            .'</td><td>'.$h($r->message).'</td></tr>')->implode("\n");
        $totals = collect($run->totals)->map(fn ($v, $k) => $h($k).': '.(int) $v)->implode(' · ');

        return ["<!doctype html>\n<html lang=\"en\"><head><meta charset=\"utf-8\"><meta name=\"robots\" content=\"noindex\"><title>QA run ".$h($run->public_id).'</title></head><body>'
            .'<h1>QA run '.$h($run->public_id).'</h1><p>Suite '.$h($run->suite).' · environment '.$h($run->environment).' · Git '.$h($run->git_sha).' · '.$h($run->created_at->toIso8601String()).'</p>'
            .'<p>'.$totals.'</p><table><thead><tr><th>Test</th><th>Status</th><th>Assertions</th><th>Message (redacted)</th></tr></thead><tbody>'."\n".$rows."\n</tbody></table></body></html>\n",
            'text/html', $name.'.html'];
    }

    /** @return array<string, mixed> */
    public static function runArray(QaTestRun $run): array
    {
        return ['id' => $run->public_id, 'suite' => $run->suite, 'environment' => $run->environment, 'git_sha' => $run->git_sha, 'source' => $run->source,
            'totals' => $run->totals, 'duration_ms' => $run->duration_ms, 'report_sha256' => $run->report_hash, 'created_at' => $run->created_at->toIso8601String()];
    }

    /** @return array<string, mixed> */
    public static function resultArray(QaTestResult $r): array
    {
        return ['id' => $r->public_id, 'key' => $r->key, 'status' => $r->status, 'assertions' => $r->assertions, 'mocked' => $r->mocked,
            'duration_ms' => $r->duration_ms, 'message' => $r->message];
    }
}
