<?php

namespace App\Console\Commands;

use App\Jobs\DeliverNotificationMail;
use App\Models\MerchantNotification;
use App\Models\NotificationDelivery;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationMaintenance;
use App\Services\Notifications\NotificationPolicy;
use App\Services\Notifications\NotificationSources;
use Illuminate\Console\Command;

class DispatchNotifications extends Command
{
    protected $signature = 'shopbonus:dispatch-notifications {--limit=100} {--bridge : Backfill existing merchant compatibility rows}';

    protected $description = 'Recover durable notification events and project them into owned inboxes';

    public function handle(NotificationDispatcher $dispatcher, NotificationSources $sources): int
    {
        if ($this->option('bridge')) {
            MerchantNotification::orderBy('id')->chunkById(100, function ($rows) use ($sources) {
                foreach ($rows as $row) {
                    $sources->merchant($row);
                }
            });
        }
        app(NotificationMaintenance::class)->reminders();
        app(NotificationMaintenance::class)->recoverAndPrune();
        $this->info('Created '.$dispatcher->drain((int) $this->option('limit')).' inbox notification(s).');

        if (app(NotificationPolicy::class)->emailEnabled()) {
            NotificationDelivery::whereIn('status', ['pending', 'retry'])->where('available_at', '<=', now())->orderBy('id')->limit(100)->pluck('id')
                ->each(fn ($id) => DeliverNotificationMail::dispatch((int) $id));
        }

        return self::SUCCESS;
    }
}
