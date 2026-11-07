<?php

namespace App\Console\Commands;

use App\Enums\AuditCategory;
use App\Models\QaEndpoint;
use App\Services\Audit\AuditLogger;
use App\Services\Qa\QaAcceptance;
use App\Services\Qa\QaInventory;
use App\Services\Qa\QaStatus;
use Illuminate\Console\Command;
use Throwable;

/** Read-only evidence evaluation. Missing mapping/proof blocks deployment promotion. */
class QaAcceptanceCheck extends Command
{
    protected $signature = 'shopbonus:qa-acceptance-check {--sha= : Exact staging deployment commit SHA} {--manifest= : Reviewed acceptance JSON (default requirements/QA_ACCEPTANCE.json)} {--discover : Staging deployment hook: scan this configured deployed SHA and require complete unmocked route coverage}';

    protected $description = 'Fail closed unless the complete source-mapped staging acceptance evidence is independently reviewed';

    public function handle(QaAcceptance $acceptance, AuditLogger $audit): int
    {
        $path = (string) ($this->option('manifest') ?: base_path('requirements/QA_ACCEPTANCE.json'));
        try {
            if (! is_file($path) || filesize($path) > 2_000_000) {
                throw new \RuntimeException('Invalid manifest file.');
            }
            $manifest = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
            if (! is_array($manifest)) {
                throw new \RuntimeException('Invalid manifest.');
            }
            $sha = (string) ($this->option('sha') ?: config('qa.release_sha'));
            if ($this->option('discover') && (! app()->environment('staging') || $sha !== config('qa.release_sha') || ! preg_match('/\A[0-9a-f]{40,64}\z/D', $sha))) {
                $this->line(json_encode(['evidence_complete' => false, 'release_approved' => false, 'blockers' => ['configured_staging_deployment_required']]));

                return self::FAILURE;
            }
            if ($this->option('discover')) {
                app(QaInventory::class)->scan($sha);
            }
            $report = $acceptance->evaluate($manifest, $sha);
            if ($this->option('discover')) {
                $endpoints = QaEndpoint::whereNull('removed_at')->get();
                $statuses = app(QaStatus::class)->forEndpoints($endpoints, $manifest);
                $unproven = $endpoints->filter(fn ($endpoint) => ! $endpoint->documented || ($statuses[$endpoint->id]['status'] ?? null) !== 'pass' || $statuses[$endpoint->id]['mocked']);
                $report['route_coverage'] = ['total' => $endpoints->count(), 'unproven' => $unproven->count()];
                if ($endpoints->isEmpty() || $unproven->isNotEmpty()) {
                    $report['blockers'][] = 'route_coverage_incomplete';
                    $report['evidence_complete'] = false;
                }
            }
            $audit->record('qa.acceptance_evaluated', category: AuditCategory::Admin, metadata: ['deployment_sha' => $report['deployment_sha'],
                'evidence_complete' => $report['evidence_complete'], 'manifest_sha256' => hash_file('sha256', $path)]);
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return $report['evidence_complete'] ? self::SUCCESS : self::FAILURE;
        } catch (Throwable) {
            $this->line(json_encode(['evidence_complete' => false, 'release_approved' => false, 'blockers' => ['manifest_or_evaluation_unavailable']]));

            return self::FAILURE;
        }
    }
}
