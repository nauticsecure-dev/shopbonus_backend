<?php

namespace App\Console\Commands;

use App\Services\Reliability\Recovery\Backups;
use Illuminate\Console\Command;

class TestRestore extends Command
{
    protected $signature = 'shopbonus:restore-isolated {archive : Backup public ULID}';

    protected $description = 'Authenticate and verify a backup in a fresh isolated target; never restore the active database';

    public function handle(Backups $backups): int
    {
        $this->line(json_encode($backups->restore($this->argument('archive')), JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
