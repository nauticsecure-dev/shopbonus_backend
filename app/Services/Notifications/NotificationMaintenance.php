<?php

namespace App\Services\Notifications;

use App\Models\CentralNotification;
use App\Models\NotificationDelivery;
use App\Models\NotificationEvent;
use App\Models\Voucher;
use Illuminate\Support\Facades\DB;

final class NotificationMaintenance
{
    public function reminders(): int
    {
        $count = 0;
        $tz = (string) config('shopbonus.business_timezone');
        $windows = app(NotificationPolicy::class)->reminderDays();
        Voucher::where('status', 'issued')->whereNotNull('consumer_user_id')->where('expires_at', '>', now())->where('expires_at', '<=', now()->addDays(max($windows) + 1))
            ->whereHas('code', fn ($q) => $q->whereNotIn('status', ['invalid', 'expired', 'redeemed']))->orderBy('id')->chunkById(100, function ($rows) use (&$count, $tz, $windows) {
                foreach ($rows as $v) {
                    $days = (int) now()->setTimezone($tz)->startOfDay()->diffInDays($v->expires_at->setTimezone($tz)->startOfDay(), false);
                    if (! in_array($days, $windows, true)) {
                        continue;
                    }
                    $created = app(NotificationSources::class)->capture('voucher_expiring', 'voucher-reminder:'.$v->public_id.':'.$v->expires_at->getTimestamp().':'.$days,
                        [$v->consumer_user_id], ['voucher_id' => $v->id, 'voucher_ref' => $v->public_id, 'reminder_stage' => $days, 'expires_at' => $v->expires_at->toIso8601String()]);
                    $count += (int) $created;
                }
            });

        return $count;
    }

    /** @return array<string, int> */
    public function recoverAndPrune(): array
    {
        $uncertain = 0;
        foreach (NotificationDelivery::where('status', 'sending')->where('claimed_at', '<', now()->subMinutes(5))->orderBy('id')->limit(100)->pluck('id') as $id) {
            $uncertain += DB::transaction(function () use ($id) {
                $d = NotificationDelivery::whereKey($id)->lockForUpdate()->firstOrFail();
                if ($d->status !== 'sending' || $d->claimed_at->greaterThanOrEqualTo(now()->subMinutes(5))) {
                    return 0;
                }
                $d->forceFill(['status' => 'uncertain', 'error_code' => 'worker_outcome_unknown'])->save();
                $n = CentralNotification::find($d->notification_id);
                if ($n !== null && ! in_array($n->event_type, ['critical_notification_failure', 'notification_delivery_failed'], true)) {
                    app(NotificationSources::class)->administrators(NotificationCatalogue::EVENTS[$n->event_type][1] ? 'critical_notification_failure' : 'notification_delivery_failed', 'delivery-failed:'.$d->public_id);
                }

                return 1;
            });
        }
        DB::table('notification_template_generations')->whereIn('status', ['reserved', 'processing'])->where('updated_at', '<', now()->subMinutes(5))->update(['status' => 'failed', 'error_code' => 'worker_outcome_unknown', 'updated_at' => now()]);
        NotificationDelivery::whereIn('status', ['pending', 'retry'])->where('created_at', '<', now()->subDays(7))->orderBy('id')->chunkById(100, function ($rows) {
            foreach ($rows as $d) {
                DB::transaction(function () use ($d) {
                    $locked = NotificationDelivery::whereKey($d->id)->lockForUpdate()->firstOrFail();
                    if (! in_array($locked->status, ['pending', 'retry'], true)) {
                        return;
                    }
                    $n = CentralNotification::find($locked->notification_id);
                    $mandatory = $n !== null && (NotificationCatalogue::EVENTS[$n->event_type][1] ?? false);
                    $locked->forceFill(['status' => $mandatory ? 'failed' : 'skipped', 'error_code' => 'delivery_expired'])->save();
                    if ($mandatory && ! in_array($n->event_type, ['notification_delivery_failed', 'critical_notification_failure'], true)) {
                        app(NotificationSources::class)->administrators('critical_notification_failure', 'delivery-failed:'.$locked->public_id);
                    }
                });
            }
        });
        $cutoff = now()->subDays(app(NotificationPolicy::class)->retentionDays());
        $deleted = 0;
        CentralNotification::where('created_at', '<', $cutoff)->orderBy('id')->chunkById(100, function ($rows) use (&$deleted) {
            foreach ($rows as $n) {
                $deleted += DB::transaction(function () use ($n) {
                    $locked = CentralNotification::whereKey($n->id)->lockForUpdate()->first();
                    $delivery = NotificationDelivery::where('notification_id', $n->id)->lockForUpdate()->first();
                    if ($locked === null || ($delivery !== null && in_array($delivery->status, ['sending', 'uncertain', 'failed', 'pending', 'retry'], true))) {
                        return 0;
                    }
                    $locked->delete();

                    return 1;
                });
            }
        });
        // Keep dedupe tombstones; remove recipient snapshots only after completed retention.
        NotificationEvent::where('status', 'processed')->where('processed_at', '<', $cutoff)->orderBy('id')->chunkById(100, function ($rows) {
            foreach ($rows as $e) {
                if ($e->payload['recipients'] === []) {
                    continue;
                }
                $e->forceFill(['payload' => ['recipients' => [], 'facts' => []]])->save();
            }
        });

        return ['uncertain' => $uncertain, 'pruned' => $deleted];
    }
}
