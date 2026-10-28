<?php

namespace App\Console\Commands;

use App\Services\Reliability\Recovery\Backups;
use Illuminate\Console\Command;

class CreateBackup extends Command
{
    protected $signature = 'shopbonus:backup';

    protected $description = 'Create an authenticated encrypted database/media backup on the configured private disk';

    public function handle(Backups $backups): int
    {
        if (! config('recovery.enabled')) {
            $this->error('Backups are disabled; configure an independent encryption key and private destination first.');

            return self::FAILURE;
        }
        $this->line(json_encode($backups->create(), JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
