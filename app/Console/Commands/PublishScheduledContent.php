<?php

namespace App\Console\Commands;

use App\Services\Cms\CmsScheduler;
use Illuminate\Console\Command;

class PublishScheduledContent extends Command
{
    protected $signature = 'shopbonus:publish-scheduled-content';

    protected $description = 'Publish or archive CMS pages whose scheduled time has come';

    public function handle(CmsScheduler $scheduler): int
    {
        $this->info('Processed '.$scheduler->runDue().' content schedule(s).');

        return self::SUCCESS;
    }
}
