<?php

namespace Tests\Concerns;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Real concurrency for tests: forks OS processes (each with its own DB
 * connection) that run the same work at the same instant. Needs MySQL or
 * PostgreSQL with committed rows: use with DatabaseTruncation and call
 * truncateAfterRace() in tearDown.
 */
trait RacesProcesses
{
    protected function skipUnlessRealDatabase(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb', 'pgsql'], true)) {
            $this->markTestSkipped('Needs MySQL/PostgreSQL (run with DB_CONNECTION=mysql); SQLite memory DBs are per process.');
        }
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Needs the pcntl extension.');
        }
    }

    /**
     * @param  Closure(int): string  $work  receives the worker number, returns a result label
     * @return list<string> one result per worker; ApiExceptions become "err:<code>"
     */
    protected function race(int $workers, Closure $work): array
    {
        $dir = sys_get_temp_dir().'/shopbonus-race-'.uniqid();
        mkdir($dir);
        $startAt = microtime(true) + 0.5;
        DB::disconnect(); // children must not share the parent's PDO socket

        $pids = [];
        for ($w = 0; $w < $workers; $w++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                try {
                    DB::reconnect();
                    usleep((int) max(0, ($startAt - microtime(true)) * 1_000_000));
                    $result = $work($w);
                } catch (ApiException $e) {
                    $result = 'err:'.$e->errorCode;
                } catch (Throwable $e) {
                    $result = 'crash:'.$e::class.':'.$e->getMessage();
                }
                file_put_contents("{$dir}/{$w}", $result);
                posix_kill(posix_getpid(), SIGKILL); // skip PHPUnit shutdown in the child
            }
            $pids[] = $pid;
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }
        DB::reconnect();

        $results = array_map(fn ($w) => (string) @file_get_contents("{$dir}/{$w}"), range(0, $workers - 1));
        array_map('unlink', glob("{$dir}/*") ?: []);
        rmdir($dir);

        return $results;
    }

    /** Committed rows must not leak into later transactional tests. */
    protected function truncateAfterRace(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::reconnect();
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
            foreach (DB::select('SHOW TABLES') as $row) {
                $table = array_values((array) $row)[0];
                if ($table !== 'migrations') {
                    DB::table($table)->truncate();
                }
            }
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }
}
