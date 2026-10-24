<?php

namespace App\Console\Commands;

use App\Services\Banners\BannerService;
use Illuminate\Console\Command;

class PublishScheduledBanners extends Command
{
    protected $signature = 'shopbonus:publish-scheduled-banners';

    protected $description = 'Publish banner campaigns whose scheduled time has come';

    public function handle(BannerService $banners): int
    {
        $this->info('Published '.$banners->publishDue().' banner(s).');

        return self::SUCCESS;
    }
}
