<?php

namespace App\Services\Qa;

use Symfony\Component\Process\Process;
use Throwable;

/** Read-only build comparison; scan order is never Git ancestry proof. */
class QaBuildProvenance
{
    private readonly string $directory;

    /** @var array<string, 'same'|'descendant'|'not_contains'|'unknown'> */
    private array $cache = [];

    public function __construct(?string $directory = null)
    {
        $this->directory = $directory ?? base_path();
    }

    /** @return 'same'|'descendant'|'not_contains'|'unknown' */
    public function compare(string $required, string $tested): string
    {
        if (! preg_match('/\A[0-9a-f]{7,64}\z/D', $required) || ! preg_match('/\A[0-9a-f]{7,64}\z/D', $tested)) {
            return 'unknown';
        }
        if ($required === $tested || str_starts_with($required, $tested) || str_starts_with($tested, $required)) {
            return 'same';
        }
        $key = $required.':'.$tested;
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }
        try {
            $fix = $this->resolve($required);
            $run = $this->resolve($tested);
            if ($fix === null || $run === null) {
                return $this->cache[$key] = 'unknown';
            }
            $process = new Process(['git', 'merge-base', '--is-ancestor', $fix, $run], $this->directory,
                ['GIT_DIR' => false, 'GIT_WORK_TREE' => false]);
            $process->setTimeout(2)->run();

            return $this->cache[$key] = match ($process->getExitCode()) {
                0 => 'descendant',
                1 => 'not_contains',
                default => 'unknown',
            };
        } catch (Throwable) {
            return $this->cache[$key] = 'unknown';
        }
    }

    private function resolve(string $sha): ?string
    {
        $process = new Process(['git', 'rev-parse', '--verify', $sha.'^{commit}'], $this->directory,
            ['GIT_DIR' => false, 'GIT_WORK_TREE' => false]);
        $process->setTimeout(2)->run();
        $value = trim($process->getOutput());

        return $process->isSuccessful() && preg_match('/\A[0-9a-f]{40,64}\z/D', $value) ? $value : null;
    }
}
