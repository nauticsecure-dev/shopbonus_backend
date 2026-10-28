<?php

namespace App\Services\Reliability\Recovery;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PDO;
use Pdo\Mysql;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class Backups
{
    public function __construct(private EncryptedArchive $crypto) {}

    /** @return array<string,mixed> */
    public function create(): array
    {
        $id = strtolower((string) Str::ulid());
        $work = $this->directory('recovery-work', $id);
        $started = hrtime(true);
        try {
            $key = $this->key();
            $driver = DB::getDriverName();
            if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) {
                throw new RuntimeException('Unsupported backup database driver.');
            }
            $database = $driver === 'sqlite' ? 'database.sqlite' : 'database.sql';
            $this->dump($work.'/'.$database, $driver, $work);
            $files = [$database => $work.'/'.$database];
            $maxFiles = max(1, min(100000, (int) config('recovery.max_files')));
            foreach (config('recovery.media_roots', []) as $label => $root) {
                if (! preg_match('/\A[a-z]+\z/D', $label) || is_link($root)) {
                    throw new RuntimeException('Unsafe media root.');
                }
                if (! is_dir($root)) {
                    continue;
                }
                foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $entry) {
                    if ($entry->isLink() || ! $entry->isFile()) {
                        throw new RuntimeException('Media links and special files cannot be backed up.');
                    }
                    $relative = substr($entry->getPathname(), strlen(rtrim($root, '/')) + 1);
                    $name = 'media/'.$label.'/'.$relative;
                    $this->path($name);
                    if (count($files) >= $maxFiles) {
                        throw new RuntimeException('Too many backup files.');
                    }
                    $files[$name] = $entry->getPathname();
                }
            }
            if (count($files) > max(1, min(100000, (int) config('recovery.max_files')))) {
                throw new RuntimeException('Too many backup files.');
            }
            $manifest = ['version' => 1, 'created_at' => now()->toIso8601String(), 'driver' => $driver, 'database' => $database,
                'recovery' => 'APP_KEY, backup key, provider credentials and infrastructure configuration must be recovered separately.', 'files' => []];
            $total = 0;
            foreach ($files as $name => $file) {
                $size = filesize($file);
                if ($size === false || ($total += $size) > $this->limit()) {
                    throw new RuntimeException('Backup exceeds configured size limit.');
                }
                $manifest['files'][] = ['path' => $name, 'size' => $size, 'sha256' => hash_file('sha256', $file)];
            }
            $archive = $work.'/archive';
            $out = fopen($archive, 'xb');
            if ($out === false) {
                throw new RuntimeException('Cannot create private archive.');
            }
            chmod($archive, 0600);
            try {
                $json = json_encode($manifest, JSON_THROW_ON_ERROR);
                $this->crypto->write($out, pack('N', strlen($json)).$json);
                foreach ($files as $name => $file) {
                    $in = fopen($file, 'rb');
                    if ($in === false) {
                        throw new RuntimeException('Cannot read backup source.');
                    }
                    try {
                        if (stream_copy_to_stream($in, $out) !== filesize($file)) {
                            throw new RuntimeException('Backup file changed while copying.');
                        }
                    } finally {
                        fclose($in);
                    }
                }
            } finally {
                fclose($out);
            }
            $encrypted = $work.'/encrypted';
            $this->crypto->encrypt($archive, $encrypted, $key);
            // Authenticate and verify every source hash before publishing a success marker.
            $this->crypto->decrypt($encrypted, $work.'/verified', $key, $this->limit() + 16777216);
            $this->unpack($work.'/verified', $work.'/verify');
            $disk = Storage::disk(config('recovery.disk'));
            $name = 'shopbonus-backups/'.$id.'.sbx';
            $in = fopen($encrypted, 'rb');
            try {
                if ($in === false || ! $disk->put($name, $in, ['visibility' => 'private'])) {
                    throw new RuntimeException('Backup storage write failed.');
                }
            } finally {
                if (is_resource($in)) {
                    fclose($in);
                }
            }
            $stored = $disk->readStream($name);
            if (! is_resource($stored)) {
                throw new RuntimeException('Stored backup cannot be verified.');
            }
            try {
                $digest = hash_init('sha256');
                hash_update_stream($digest, $stored);
                if (! hash_equals(hash_file('sha256', $encrypted), hash_final($digest))) {
                    throw new RuntimeException('Stored backup checksum mismatch.');
                }
            } finally {
                fclose($stored);
            }
            $metrics = ['sha256' => hash_file('sha256', $encrypted), 'bytes' => filesize($encrypted), 'files' => count($files),
                'duration_ms' => (int) ((hrtime(true) - $started) / 1000000), 'disk' => config('recovery.disk'), 'driver' => $driver];
            $this->event('backup', 'succeeded', $id, $metrics);
            $this->prune();

            return ['archive' => $id] + $metrics;
        } catch (Throwable $e) {
            $this->event('backup', 'failed', $id, ['failure_class' => $e::class]);
            throw new RuntimeException('Backup failed; inspect configured storage/database access and private operator logs.', 0, $e);
        } finally {
            File::deleteDirectory($work);
        }
    }

    /**
     * Authenticates fully, then restores only into a new private isolated target.
     *
     * @return array<string,mixed>
     */
    public function restore(string $id): array
    {
        if (! Str::isUlid($id)) {
            throw new RuntimeException('Invalid archive reference.');
        }
        $id = strtolower($id);
        $work = $this->directory('recovery-work', strtolower((string) Str::ulid()));
        $target = null;
        $started = hrtime(true);
        try {
            $source = Storage::disk(config('recovery.disk'))->readStream('shopbonus-backups/'.$id.'.sbx');
            $out = fopen($work.'/encrypted', 'xb');
            if (! is_resource($source) || $out === false) {
                throw new RuntimeException('Cannot open stored backup.');
            }
            chmod($work.'/encrypted', 0600);
            try {
                $copied = stream_copy_to_stream($source, $out, $this->limit() + 33554432);
                if ($copied === false || fread($source, 1) !== '') {
                    throw new RuntimeException('Stored backup exceeds size limit.');
                }
            } finally {
                fclose($source);
                fclose($out);
            }
            $this->crypto->decrypt($work.'/encrypted', $work.'/archive', $this->key(), $this->limit() + 16777216);
            $manifest = $this->unpack($work.'/archive', $work.'/extracted');
            $target = $this->directory('recovery-restores', strtolower((string) Str::ulid()));
            if (! File::copyDirectory($work.'/extracted', $target)) {
                throw new RuntimeException('Cannot copy isolated restore files.');
            }
            if ($manifest['driver'] === 'sqlite') {
                $pdo = new PDO('sqlite:'.$target.'/database.sqlite');
                if ($pdo->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
                    throw new RuntimeException('Restored database integrity check failed.');
                }
                $proof = $this->proof(fn ($sql) => $pdo->query($sql)->fetchAll(PDO::FETCH_OBJ));
                if ($pdo->query('PRAGMA foreign_key_check')->fetch() !== false) {
                    throw new RuntimeException('Restored database references failed.');
                }
            } else {
                $proof = $this->restoreMysql($target.'/database.sql', $work);
            }
            $metrics = ['proof' => $proof, 'duration_ms' => (int) ((hrtime(true) - $started) / 1000000), 'files' => count($manifest['files']), 'driver' => $manifest['driver']];
            $this->event('restore', 'succeeded', $id, $metrics);

            return ['archive' => $id, 'isolated_directory' => $target] + $metrics;
        } catch (Throwable $e) {
            if ($target !== null) {
                File::deleteDirectory($target);
            }
            $this->event('restore', 'failed', $id, ['failure_class' => $e::class]);
            throw new RuntimeException('Isolated restore failed; no active application database was selected.', 0, $e);
        } finally {
            File::deleteDirectory($work);
        }
    }

    /** @return array<string,mixed> */
    public function status(): array
    {
        $last = DB::table('recovery_events')->where('kind', 'backup')->latest('id')->first();
        $restore = DB::table('recovery_events')->where('kind', 'restore')->where('status', 'succeeded')->latest('id')->first();
        $latestRestore = DB::table('recovery_events')->where('kind', 'restore')->latest('id')->first();
        $attempts = DB::table('recovery_events')->where('kind', 'backup')->where('observed_at', '>=', now()->subDays(30))->count();
        $successes = DB::table('recovery_events')->where('kind', 'backup')->where('status', 'succeeded')->where('observed_at', '>=', now()->subDays(30))->count();
        $overdue = $last !== null && strtotime($last->observed_at) < now()->subMinutes(max(30, (int) config('recovery.overdue_minutes')))->getTimestamp();

        return ['status' => ! config('recovery.enabled') ? 'UNKNOWN' : ($last === null || $last->status === 'failed' || $latestRestore?->status === 'failed' || $overdue ? 'CRITICAL' : 'WARNING'),
            'backup_success_percent_30d' => $attempts > 0 ? round(100 * $successes / $attempts, 2) : null,
            'enabled' => (bool) config('recovery.enabled'), 'last_backup_at' => $last?->observed_at, 'last_backup_result' => $last?->status,
            'last_verified_restore_at' => $restore?->observed_at, 'offsite_verified' => false,
            'definition' => 'Successful configured-disk write is not independent offsite durability or RPO/RTO proof.'];
    }

    private function key(): string
    {
        $key = base64_decode((string) config('recovery.key'), true);
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('Configure an independent base64 32-byte backup encryption key.');
        }

        return $key;
    }

    private function limit(): int
    {
        return max(1048576, min(5368709120, (int) config('recovery.max_bytes')));
    }

    private function directory(string $kind, string $id): string
    {
        $root = storage_path('app/private/'.$kind);
        if (is_link($root) || (file_exists($root) && ! is_dir($root))) {
            throw new RuntimeException('Unsafe recovery directory.');
        }
        File::ensureDirectoryExists($root, 0700);
        chmod($root, 0700);
        $dir = $root.'/'.$id;
        if (! mkdir($dir, 0700)) {
            throw new RuntimeException('Cannot create isolated recovery directory.');
        }

        return $dir;
    }

    private function path(string $name): void
    {
        if (! preg_match('~\A(?:database\.(?:sqlite|sql)|media/[a-z]+/[A-Za-z0-9_. /-]+)\z~D', $name)
            || str_contains($name, '..') || str_contains($name, '//')) {
            throw new RuntimeException('Unsafe backup member path.');
        }
    }

    /** @return array<string,mixed> */
    private function unpack(string $archive, string $target): array
    {
        File::ensureDirectoryExists($target, 0700);
        $in = fopen($archive, 'rb');
        if ($in === false) {
            throw new RuntimeException('Cannot open authenticated archive.');
        }
        try {
            $length = unpack('Nlength', $this->crypto->read($in, 4))['length'];
            if ($length > 16777216 || $length < 2) {
                throw new RuntimeException('Invalid backup manifest.');
            }
            $manifest = json_decode($this->crypto->read($in, $length), true, 64, JSON_THROW_ON_ERROR);
            if (($manifest['version'] ?? null) !== 1 || ! in_array($manifest['driver'] ?? null, ['sqlite', 'mysql', 'mariadb'], true)
                || ! is_array($manifest['files'] ?? null) || count($manifest['files']) > max(1, min(100000, (int) config('recovery.max_files')))) {
                throw new RuntimeException('Unsupported backup manifest.');
            }
            $seen = [];
            $total = 0;
            foreach ($manifest['files'] as $file) {
                $this->path($file['path']);
                if (isset($seen[$file['path']]) || ! is_int($file['size']) || $file['size'] < 0 || ($total += $file['size']) > $this->limit()) {
                    throw new RuntimeException('Invalid backup member.');
                }
                $seen[$file['path']] = true;
                $dest = $target.'/'.$file['path'];
                File::ensureDirectoryExists(dirname($dest), 0700);
                $out = fopen($dest, 'xb');
                if ($out === false) {
                    throw new RuntimeException('Duplicate backup member.');
                }
                chmod($dest, 0600);
                try {
                    if ($file['size'] > 0 && stream_copy_to_stream($in, $out, $file['size']) !== $file['size']) {
                        throw new RuntimeException('Truncated archive member.');
                    }
                } finally {
                    fclose($out);
                }
                if (! hash_equals($file['sha256'], hash_file('sha256', $dest))) {
                    throw new RuntimeException('Backup member checksum mismatch.');
                }
            }
            $expected = $manifest['driver'] === 'sqlite' ? 'database.sqlite' : 'database.sql';
            if (($manifest['database'] ?? null) !== $expected || ! isset($seen[$expected]) || fread($in, 1) !== '') {
                throw new RuntimeException('Backup database or archive boundary mismatch.');
            }

            return $manifest;
        } finally {
            fclose($in);
        }
    }

    private function dump(string $target, string $driver, string $work): void
    {
        if ($driver === 'sqlite') {
            DB::connection()->getPdo()->exec('VACUUM INTO '.DB::connection()->getPdo()->quote($target));
            chmod($target, 0600);

            return;
        }
        $config = config('database.connections.'.config('database.default'));
        if (DB::table('information_schema.tables')->where('TABLE_SCHEMA', $config['database'])->where('TABLE_TYPE', 'BASE TABLE')->where('ENGINE', '<>', 'InnoDB')->exists()) {
            throw new RuntimeException('Consistent logical backup requires InnoDB tables.');
        }
        $auth = $this->mysqlAuth($config, $work);
        $process = new Process(['mysqldump', '--defaults-extra-file='.$auth, '--single-transaction', '--quick', '--skip-lock-tables', '--no-tablespaces', '--set-gtid-purged=OFF', '--column-statistics=0', '--hex-blob', $config['database']]);
        $out = fopen($target, 'xb');
        if ($out === false) {
            throw new RuntimeException('Cannot open database dump.');
        }
        chmod($target, 0600);
        try {
            $process->setTimeout(600)->run(function ($type, #[\SensitiveParameter] $data) use ($out, $process) {
                if ($type === Process::OUT) {
                    $this->crypto->write($out, $data);
                    $process->clearOutput();
                    if (ftell($out) > $this->limit()) {
                        throw new RuntimeException('Database dump exceeds backup limit.');
                    }
                }
            });
            if (! $process->isSuccessful()) {
                throw new RuntimeException('Database dump process failed.');
            }
        } finally {
            fclose($out);
            unlink($auth);
        }
    }

    /** @param array<string,mixed> $config */
    private function mysqlAuth(#[\SensitiveParameter] array $config, string $work): string
    {
        $file = $work.'/client-'.bin2hex(random_bytes(6)).'.cnf';
        $lines = '[client]'."\n";
        $config['ssl_ca'] = extension_loaded('pdo_mysql') ? ($config['options'][Mysql::ATTR_SSL_CA] ?? '') : '';
        if ($config['ssl_ca'] !== '') {
            $lines .= 'ssl-mode=VERIFY_IDENTITY'."\n";
        }
        foreach (['host' => 'host', 'port' => 'port', 'user' => 'username', 'password' => 'password', 'socket' => 'unix_socket', 'ssl-ca' => 'ssl_ca'] as $option => $field) {
            $value = (string) ($config[$field] ?? '');
            if (str_contains($value, "\n") || str_contains($value, "\r") || str_contains($value, "\0")) {
                throw new RuntimeException('Invalid database client configuration.');
            }
            if ($value !== '') {
                $lines .= $option.'="'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"'."\n";
            }
        }
        file_put_contents($file, $lines, LOCK_EX);
        chmod($file, 0600);

        return $file;
    }

    /** @return array<string,int|string> */
    private function restoreMysql(string $dump, string $work): array
    {
        $connection = config('recovery.restore_connection');
        if (! is_string($connection) || $connection === '' || $connection === config('database.default')) {
            throw new RuntimeException('Explicit isolated restore server connection is required.');
        }
        $config = config('database.connections.'.$connection);
        $active = config('database.connections.'.config('database.default'));
        if (! is_array($config) || ! in_array($config['driver'] ?? null, ['mysql', 'mariadb'], true)
            || (($config['host'] ?? null) === ($active['host'] ?? null) && ($config['port'] ?? null) === ($active['port'] ?? null))) {
            throw new RuntimeException('Restore server must differ from the active database server.');
        }
        if (DB::connection($connection)->selectOne('SELECT @@server_uuid AS uuid')->uuid === DB::selectOne('SELECT @@server_uuid AS uuid')->uuid) {
            throw new RuntimeException('Restore server identity matches active database.');
        }
        $database = 'shopbonus_restore_'.bin2hex(random_bytes(8));
        $db = DB::connection($connection);
        $db->statement('CREATE DATABASE `'.$database.'`');
        $auth = $this->mysqlAuth($config, $work);
        $input = fopen($dump, 'rb');
        try {
            $process = new Process(['mysql', '--defaults-extra-file='.$auth, '--database='.$database]);
            $process->setInput($input)->setTimeout(600)->run();
            if (! $process->isSuccessful()) {
                throw new RuntimeException('Isolated MySQL import failed.');
            }

            // No application/queue/provider process is started against restored data.
            return $this->proof(fn ($sql) => $db->select(str_replace([' wallets', ' wallet_ledger_entries', ' vouchers'], [' `'.$database.'`.wallets', ' `'.$database.'`.wallet_ledger_entries', ' `'.$database.'`.vouchers'], $sql)));
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }
            unlink($auth);
            $db->statement('DROP DATABASE `'.$database.'`');
        }
    }

    /** @return array<string,int|string> */
    private function proof(callable $select): array
    {
        return ['wallets' => (int) $select('SELECT COUNT(*) AS n FROM wallets')[0]->n,
            'ledger_entries' => (int) $select('SELECT COUNT(*) AS n FROM wallet_ledger_entries')[0]->n,
            'vouchers' => (int) $select('SELECT COUNT(*) AS n FROM vouchers')[0]->n,
            'assigned_vouchers' => (int) $select('SELECT COUNT(*) AS n FROM vouchers WHERE consumer_user_id IS NOT NULL')[0]->n,
            'wallet_projection_sha256' => hash('sha256', json_encode($select('SELECT public_id, owner_type, owner_id, kind, unit, available, reserved, pending FROM wallets ORDER BY public_id'), JSON_THROW_ON_ERROR)),
            'voucher_ownership_sha256' => hash('sha256', json_encode($select('SELECT public_id, consumer_user_id, webshop_id, offer_id FROM vouchers ORDER BY public_id'), JSON_THROW_ON_ERROR))];
    }

    /** @param array<string,mixed> $metrics */
    private function event(string $kind, string $status, string $id, array $metrics): void
    {
        try {
            DB::table('recovery_events')->insert(['kind' => $kind, 'status' => $status, 'archive_ref' => $id,
                'metrics' => json_encode($metrics, JSON_THROW_ON_ERROR), 'observed_at' => now()]);
        } catch (Throwable $e) {
            Log::error('Recovery event storage failed', ['exception_class' => $e::class]);
        }
    }

    private function prune(): void
    {
        $before = now()->subDays(max(1, min(365, (int) config('recovery.retention_days', 14))));
        $rows = DB::table('recovery_events')->where('kind', 'backup')->where('status', 'succeeded')->where('observed_at', '<', $before)->whereNotExists(fn ($q) => $q->selectRaw('1')->from('recovery_events as pruned')->whereColumn('pruned.archive_ref', 'recovery_events.archive_ref')->where('pruned.kind', 'pruned'))->orderBy('id')->limit(100)->get();
        foreach ($rows as $row) {
            if (Str::isUlid($row->archive_ref)) {
                if (Storage::disk(config('recovery.disk'))->delete('shopbonus-backups/'.$row->archive_ref.'.sbx')) {
                    $this->event('pruned', 'succeeded', $row->archive_ref, []);
                }
            }
        }
    }
}
