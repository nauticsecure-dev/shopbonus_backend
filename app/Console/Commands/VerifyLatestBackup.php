<?php

namespace App\Console\Commands;

use App\Services\Reliability\Recovery\Backups;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class VerifyLatestBackup extends Command
{
    protected $signature = 'shopbonus:verify-latest-backup';

    protected $description = 'Test the latest successful backup in an isolated target and remove private verification files';

    public function handle(Backups $backups): int
    {
        $last = DB::table('recovery_events')->where('kind', 'backup')->where('status', 'succeeded')->latest('id')->first();
        if ($last === null) {
            $this->error('No successful backup is recorded.');

            return self::FAILURE;
        }
        $result = $backups->restore($last->archive_ref);
        File::deleteDirectory($result['isolated_directory']);
        $this->info('Latest backup restored and verified in isolation.');

        return self::SUCCESS;
    }
}
