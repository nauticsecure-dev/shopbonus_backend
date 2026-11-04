<?php

namespace App\Console\Commands;

use App\Services\Qa\CmsContentGate;
use Illuminate\Console\Command;
use Throwable;

class CmsContentCheck extends Command
{
    protected $signature = 'shopbonus:cms-content-check {--sha= : Exact configured staging deployment SHA}';

    protected $description = 'Block unless all five recommended CMS denominators and23 staging checks have independent current evidence';

    public function handle(CmsContentGate $gate): int
    {
        $sha = (string) ($this->option('sha') ?: config('qa.release_sha'));
        if (! app()->environment('staging') || $sha !== config('qa.release_sha') || ! preg_match('/\A[0-9a-f]{40,64}\z/D', $sha)) {
            $this->line(json_encode(['evidence_complete' => false, 'release_approved' => false, 'blockers' => ['configured_staging_deployment_required']]));

            return self::FAILURE;
        }
        try {
            $path = base_path('requirements/CMS_ACCEPTANCE.json');
            if (! is_file($path) || filesize($path) > 2000000) {
                throw new \RuntimeException;
            } $m = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
            if (! is_array($m)) {
                throw new \RuntimeException;
            }$report = $gate->evaluate($m, $sha);
            $this->line(json_encode($report, JSON_THROW_ON_ERROR));

            return $report['evidence_complete'] ? self::SUCCESS : self::FAILURE;
        } catch (Throwable) {
            $this->line(json_encode(['evidence_complete' => false, 'release_approved' => false, 'blockers' => ['cms_contract_or_evidence_unavailable']]));

            return self::FAILURE;
        }
    }
}
