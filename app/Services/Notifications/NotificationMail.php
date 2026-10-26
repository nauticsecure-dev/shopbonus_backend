<?php

namespace App\Services\Notifications;

use App\Enums\ConsentPurpose;
use App\Models\CentralNotification;
use App\Models\NotificationDelivery;
use App\Models\Offer;
use App\Models\User;
use App\Models\Voucher;
use App\Services\Consumer\CommunicationPreferences;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Throwable;

final class NotificationMail
{
    public static function register(): void
    {
        CentralNotification::created(fn (CentralNotification $n) => NotificationDelivery::create(['notification_id' => $n->id, 'available_at' => now()]));
    }

    public function eligible(User $user, CentralNotification $n): bool
    {
        if ($n->notifiable_type !== $user->getMorphClass()) {
            return false;
        }
        if (! $user->isActive() || ($user->email_verified_at === null && $n->event_type !== 'account_welcome')) {
            return false;
        }
        if ($n->recipient_type === 'workspace' && ! $user->memberships()->active()->where('webshop_id', $n->webshop_id)->whereHas('webshop', fn ($q) => $q->where('status', '!=', 'suspended'))->exists()) {
            return false;
        }
        if ($n->recipient_type === 'admin' && ! $user->isSuperAdmin()) {
            return false;
        }
        if (NotificationCatalogue::EVENTS[$n->event_type][1] ?? false) {
            return true;
        }
        $p = app(NotificationPreferences::class)->current($user);
        if (! $p['email_enabled'] || ! ($p['preferences'][$n->event_type]['email'] ?? false)) {
            return false;
        }
        if ($n->event_type === 'favorite_merchant_offer') {
            $offer = Offer::where('public_id', $n->data['offer_ref'] ?? '')->first();

            return $offer !== null && app(NotificationEligibility::class)->favorite($user, $offer) && app(CommunicationPreferences::class)->allows($user, ConsentPurpose::MarketingInspiration);
        }
        if ($n->event_type === 'voucher_expiring') {
            return app(CommunicationPreferences::class)->allows($user, ConsentPurpose::VoucherReminders) && $this->voucherUsable($n);
        }

        return true;
    }

    public function voucherUsable(CentralNotification $n): bool
    {
        $id = $n->data['voucher_ref'] ?? null;

        $v = ! is_string($id) ? null : Voucher::where('public_id', $id)->where('consumer_user_id', $n->notifiable_id)->where('status', 'issued')->where('expires_at', '>', now())
            ->whereHas('code', fn ($q) => $q->whereNotIn('status', ['invalid', 'expired', 'redeemed']))->first();
        if ($v === null || $v->expires_at->toIso8601String() !== ($n->data['reminder_expires_at'] ?? null)) {
            return false;
        }
        $stage = $n->data['reminder_stage'] ?? null;
        $tz = (string) config('shopbonus.business_timezone');

        return in_array($stage, app(NotificationPolicy::class)->reminderDays(), true)
            && (int) now()->setTimezone($tz)->startOfDay()->diffInDays($v->expires_at->setTimezone($tz)->startOfDay(), false) === $stage;
    }

    public function send(int $deliveryId): void
    {
        if (! app(NotificationPolicy::class)->emailEnabled()) {
            return;
        }
        $claim = DB::transaction(function () use ($deliveryId) {
            $d = NotificationDelivery::whereKey($deliveryId)->lockForUpdate()->first();
            if ($d === null || ! in_array($d->status, ['pending', 'retry'], true) || $d->available_at->isFuture()) {
                return null;
            }
            $n = CentralNotification::find($d->notification_id);
            $u = $n === null ? null : User::find($n->notifiable_id);
            if ($u === null || ! $this->eligible($u, $n)) {
                $d->forceFill(['status' => 'skipped', 'error_code' => 'recipient_or_preferences_changed'])->save();

                return null;
            }
            $token = (string) Str::uuid();
            $d->forceFill(['status' => 'sending', 'claim_token' => $token, 'claimed_at' => now(), 'attempts' => $d->attempts + 1])->save();

            return [$d, $n, $u, $token];
        });
        if ($claim === null) {
            return;
        }
        [$d, $n, $u, $token] = $claim;
        try {
            $views = $this->render($n);
        } catch (Throwable) {
            $this->finish($d, $token, $d->attempts >= 3 ? 'failed' : 'retry', 'render_failed');

            return;
        }
        try {
            $sent = Mail::send(['html' => new HtmlString($views['html']), 'text' => new HtmlString($views['text'])], [], function ($message) use ($u, $n, $d) {
                $message->to($u->email)->subject($n->data['title']);
                $message->getSymfonyMessage()->getHeaders()->addIdHeader('Message-ID', 'notification-'.$d->public_id.'@shopbonus.invalid');
            });
            $this->finish($d, $token, $sent === null ? 'skipped' : 'sent', null);
        } catch (Throwable) {
            // SMTP may have accepted before disconnecting. Never automatically resend an ambiguous delivery.
            $this->finish($d, $token, 'uncertain', 'transport_result_unknown');
        }
    }

    /** @return array{html:string,text:string} */
    public function render(CentralNotification $n): array
    {
        $data = $n->data;
        $path = $data['action_route'];
        if (! is_string($path) || ! str_starts_with($path, '/app/') || str_starts_with($path, '//') || preg_match('/[\\\\\r\n]/', $path)) {
            throw new \LogicException('Unsafe notification action.');
        }
        $url = rtrim((string) config('shopbonus.identity.frontend_url'), '/').$path;
        if (! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            throw new \LogicException('Invalid frontend URL.');
        }
        $vars = [...$data, 'url' => $url];

        return ['html' => view('mail.central-notification', $vars)->render(), 'text' => view('mail.central-notification-text', $vars)->render()];
    }

    private function finish(NotificationDelivery $d, string $token, string $status, ?string $error): void
    {
        DB::transaction(function () use ($d, $token, $status, $error) {
            $updated = NotificationDelivery::whereKey($d->id)->where('status', 'sending')->where('claim_token', $token)->update([
                'status' => $status, 'error_code' => $error, 'sent_at' => $status === 'sent' ? now() : null, 'available_at' => now()->addMinutes(5), 'updated_at' => now(),
            ]);
            if ($updated && in_array($status, ['failed', 'uncertain'], true)) {
                $n = CentralNotification::find($d->notification_id);
                if ($n !== null && ! in_array($n->event_type, ['notification_delivery_failed', 'critical_notification_failure'], true)) {
                    app(NotificationSources::class)->administrators(NotificationCatalogue::EVENTS[$n->event_type][1] ? 'critical_notification_failure' : 'notification_delivery_failed', 'delivery-failed:'.$d->public_id);
                }
            }
        });
    }
}
