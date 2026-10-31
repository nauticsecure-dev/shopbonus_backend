<?php

namespace Tests\Unit;

use App\Services\Qa\QaBuildProvenance;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class QaBuildProvenanceTest extends TestCase
{
    public function test_real_git_ancestry_distinguishes_descendants_and_unrelated_branches(): void
    {
        $directory = sys_get_temp_dir().'/shopbonus-build-proof-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $git = function (array $arguments) use ($directory): string {
            $p = new Process(['git', ...$arguments], $directory, ['GIT_DIR' => false, 'GIT_WORK_TREE' => false]);
            $p->mustRun();

            return trim($p->getOutput());
        };
        $commit = fn (string $name) => $git(['-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.test', 'commit', '--allow-empty', '-m', $name]);
        try {
            $git(['init', '-b', 'main']);
            $commit('base');
            $base = $git(['rev-parse', 'HEAD']);
            $commit('fix');
            $fix = $git(['rev-parse', 'HEAD']);
            $commit('descendant');
            $descendant = $git(['rev-parse', 'HEAD']);
            $git(['checkout', '-b', 'unrelated', $base]);
            $commit('other branch');
            $unrelated = $git(['rev-parse', 'HEAD']);
            $proof = new QaBuildProvenance($directory);
            $this->assertSame('same', $proof->compare($fix, $fix));
            $this->assertSame('descendant', $proof->compare($fix, $descendant));
            $this->assertSame('not_contains', $proof->compare($fix, $unrelated));
            $this->assertSame('not_contains', $proof->compare($fix, $base));
            $this->assertSame('unknown', $proof->compare('fffffff', $descendant));
            $this->assertSame('unknown', $proof->compare('--help', $descendant));
        } finally {
            File::deleteDirectory($directory);
        }
    }
}
