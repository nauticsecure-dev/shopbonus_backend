<?php

namespace App\Services\Qa;

use RuntimeException;
use Symfony\Component\Process\Process;

/** Match deployed bytes to Git, not only an operator-provided hash. Never runs script content. */
class QaRunnerSource
{
    public function __construct(private readonly ?string $root = null) {}

    public function verify(string $relative, string $hash, string $sha): string
    {
        if (! preg_match('~\A(?:scripts/qa-runners/[A-Za-z0-9_/-]+\.php|requirements/QA_RUNNERS\.json)\z~D', $relative)
            || str_contains($relative, '..') || ! preg_match('/\A[0-9a-f]{64}\z/D', $hash)) {
            throw new RuntimeException('Invalid reviewed source reference.');
        }
        $root = $this->root ?? base_path();
        $path = realpath($root.'/'.$relative);
        if (! $path || ! str_starts_with($path, realpath($root).DIRECTORY_SEPARATOR) || ! is_file($path) || filesize($path) > 262144
            || ! hash_equals($hash, (string) hash_file('sha256', $path))) {
            throw new RuntimeException('Reviewed source unavailable or changed.');
        }
        foreach ([['git', 'rev-parse', 'HEAD'], ['git', 'show', $sha.':'.$relative]] as $index => $argv) {
            $process = new Process($argv, $root, null, null, 10);
            $process->run();
            if (! $process->isSuccessful() || ($index === 0 ? trim($process->getOutput()) !== $sha : ! hash_equals($hash, hash('sha256', $process->getOutput())))) {
                throw new RuntimeException('Reviewed source does not match the deployed Git revision.');
            }
        }

        return $path;
    }
}
