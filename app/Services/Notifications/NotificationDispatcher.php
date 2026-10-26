<?php

namespace App\Services\Notifications;

use App\Enums\ConsentPurpose;
use App\Models\AffiliateTransaction;
use App\Models\MerchantNotification;
use App\Models\NotificationEvent;
use App\Models\Offer;
use App\Models\User;
use App\Models\Voucher;
use App\Models\WalletLedgerEntry;
use App\Models\Webshop;
use App\Services\Consumer\CommunicationPreferences;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** Durable event outbox. Financial source transactions never wait on recipient fanout/network. */
final class NotificationDispatcher
{
    public function drain(int $limit = 100): int
    {
        $count = 0;
        foreach (NotificationEvent::where('status', 'pending')->where('available_at', '<=', now())->orderBy('id')->limit(min(500, max(1, $limit)))->pluck('id') as $id) {
            try {
                $count += $this->process((int) $id);
            } catch (Throwable $e) {
                // No source/recipient data or exception text in delivery failure records.
                DB::transaction(function () use ($id) {
                    $event = NotificationEvent::whereKey($id)->lockForUpdate()->firstOrFail();
                    if ($event->status !== 'pending') {
                        return;
                    }
                    $attempts = $event->attempts + 1;
                    $event->forceFill(['attempts' => $attempts, 'status' => $attempts >= 3 ? 'failed' : 'pending', 'error_code' => 'inbox_processing_failed', 'available_at' => now()->addMinutes(5)])->save();
                    if ($attempts >= 3 && ! in_array($event->event_type, ['notification_delivery_failed', 'critical_notification_failure'], true)) {
                        app(NotificationSources::class)->administrators('critical_notification_failure', 'failed-event:'.$event->public_id);
                    }
                });
                report($e);
            }
        }

        return $count;
    }

    public function process(int $id): int
    {
        return DB::transaction(function () use ($id) {
            $event = NotificationEvent::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($event->status !== 'pending') {
                return 0;
            }
            $count = 0;
            foreach ($event->payload['recipients'] as $userId) {
                $user = User::whereKey($userId)->lockForUpdate()->first();
                if ($user === null || ! $user->isActive() || ($user->email_verified_at === null && $event->event_type !== 'account_welcome')) {
                    continue;
                }
                if ($event->recipient_type === 'admin' && ! $user->isSuperAdmin()) {
                    continue;
                }
                if ($event->recipient_type === 'workspace' && ! $user->memberships()->active()->where('webshop_id', $event->webshop_id)->whereHas('webshop', fn ($q) => $q->where('status', '!=', 'suspended'))->exists()) {
                    continue;
                }
                $facts = $event->payload['facts'];
                if ($event->event_type === 'voucher_expiring') {
                    $v = Voucher::find($facts['voucher_id']);
                    if (! in_array($facts['reminder_stage'], app(NotificationPolicy::class)->reminderDays(), true) || $v === null || $v->consumer_user_id !== $user->id || $v->status->value !== 'issued' || $v->expires_at === null || $v->expires_at->isPast()
                        || $v->expires_at->toIso8601String() !== $facts['expires_at']
                        || ! in_array($v->code->status->value, ['issued', 'available'], true)
                        || (int) now()->setTimezone(config('shopbonus.business_timezone'))->startOfDay()->diffInDays($v->expires_at->setTimezone(config('shopbonus.business_timezone'))->startOfDay(), false) !== $facts['reminder_stage']) {
                        continue;
                    }
                }
                if ($event->event_type === 'cashback_credited') {
                    $entry = WalletLedgerEntry::find($facts['entry_id']);
                    if ($entry === null || ! AffiliateTransaction::where('public_id', $entry->source_ref)->where('user_id', $user->id)->where('wallet_entry_id', $entry->id)->exists()) {
                        continue;
                    }
                }
                $inApp = true;
                if ($event->event_type === 'favorite_merchant_offer') {
                    $offer = Offer::find($facts['offer_id']);
                    if ($offer === null || ! app(NotificationEligibility::class)->favorite($user, $offer)
                        || ! app(CommunicationPreferences::class)->allows($user, ConsentPurpose::MarketingInspiration)) {
                        continue;
                    }
                    $prefs = app(NotificationPreferences::class)->current($user);
                    $inApp = (bool) $prefs['preferences']['favorite_merchant_offer']['in_app'];
                    if (! $inApp && ! ($prefs['email_enabled'] && $prefs['preferences']['favorite_merchant_offer']['email'])) {
                        continue;
                    }
                }
                $key = 'event:'.$event->public_id;
                if ($user->notifications()->where('event_key', $key)->exists()) {
                    continue;
                }
                $locale = $user->preferredLocale();
                $amount = null;
                if (isset($facts['amount_minor'], $facts['unit'])) {
                    $minor = abs((int) $facts['amount_minor']);
                    $amount = $facts['unit'] === 'eur_credit' ? '€'.intdiv($minor, 100).($locale === 'nl' ? ',' : '.').str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT) : $minor.($locale === 'nl' ? ' punten' : ' points');
                }
                $content = app(NotificationTemplates::class)->resolve($event->event_type, $locale, $amount);
                $workspaceRef = $event->webshop_id === null ? null : Webshop::whereKey($event->webshop_id)->value('public_id');
                $path = match ($event->recipient_type) {
                    'admin' => '/app/platform', 'workspace' => '/app/workspaces?workspace='.$workspaceRef, default => '/app/benefits'
                };
                $legacy = isset($facts['legacy_id']) ? MerchantNotification::find($facts['legacy_id']) : null;
                $legacyRead = $legacy === null ? null : DB::table('notification_legacy_reads')->where('legacy_notification_id', $legacy->id)->where('user_id', $user->id)->value('read_at');
                $n = $user->notifications()->create(['id' => (string) Str::uuid(), 'type' => NotificationEvent::class, 'event_type' => $event->event_type,
                    'event_key' => $key, 'in_app_enabled' => $inApp, 'recipient_type' => $event->recipient_type, 'webshop_id' => $event->webshop_id, 'legacy_notification_id' => $legacy?->id,
                    'read_at' => $legacyRead ?? ($facts['legacy_read_at'] ?? null), 'data' => [...$content, 'workspace_id' => $workspaceRef, 'action_route' => $path,
                        'voucher_ref' => $facts['voucher_ref'] ?? null, 'reminder_stage' => $facts['reminder_stage'] ?? null, 'reminder_expires_at' => $facts['expires_at'] ?? null, 'offer_ref' => isset($facts['offer_id']) ? Offer::whereKey($facts['offer_id'])->value('public_id') : null, 'delivery_channels' => ['database' => $inApp ? 'delivered' : 'skipped', 'email' => 'pending']]]);
                if ($inApp) {
                    app(NotificationInbox::class)->signal($user, 'NotificationCreated', $n->id);
                    app(NotificationInbox::class)->signal($user, 'NotificationCountUpdated');
                }
                $count++;
            }
            $event->forceFill(['status' => 'processed', 'processed_at' => now()])->save();

            return $count;
        }, 3);
    }
}
