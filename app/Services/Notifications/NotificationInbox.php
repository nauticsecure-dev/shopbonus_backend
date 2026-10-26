<?php

namespace App\Services\Notifications;

use App\Enums\MembershipStatus;
use App\Enums\WebshopStatus;
use App\Events\NotificationInboxChanged;
use App\Exceptions\ApiException;
use App\Models\CentralNotification;
use App\Models\NotificationDelivery;
use App\Models\SupportConversation;
use App\Models\SupportReply;
use App\Models\User;
use App\Models\Webshop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class NotificationInbox
{
    /** Current permissions always apply to historical inbox entries, including counts and read-all.
     * @return Builder<CentralNotification>
     */
    public function visible(User $user): Builder
    {
        return CentralNotification::query()->where('in_app_enabled', true)->where('notifiable_type', $user->getMorphClass())->where('notifiable_id', $user->id)
            ->where(function (Builder $scope) use ($user) {
                $scope->where('recipient_type', 'consumer')->whereNull('webshop_id')
                    ->orWhere(function (Builder $workspace) use ($user) {
                        $workspace->where('recipient_type', 'workspace')->whereExists(function ($q) use ($user) {
                            $q->selectRaw('1')->from('webshop_memberships as member')->join('webshops as shop', 'shop.id', '=', 'member.webshop_id')
                                ->whereColumn('member.webshop_id', 'notifications.webshop_id')->where('member.user_id', $user->id)
                                ->where('member.status', MembershipStatus::Active->value)->where('shop.status', '!=', WebshopStatus::Suspended->value);
                        });
                    });
                if ($user->isSuperAdmin()) {
                    $scope->orWhere(fn (Builder $q) => $q->where('recipient_type', 'admin')->whereNull('webshop_id'));
                }
            });
    }

    /** Called inside the human reply's transaction: notification and source commit/rollback together. */
    public function supportReply(SupportConversation $conversation, SupportReply $reply): void
    {
        $user = User::whereKey($conversation->user_id)->lockForUpdate()->firstOrFail();
        if (! $user->isActive() || $user->email_verified_at === null) {
            return;
        }
        $event = $conversation->webshop_id === null ? 'support_reply' : 'workspace_support_reply';
        if ($conversation->webshop_id !== null) {
            $allowed = $user->memberships()->active()->where('webshop_id', $conversation->webshop_id)
                ->whereHas('webshop', fn ($q) => $q->where('status', '!=', WebshopStatus::Suspended->value))->exists();
            if (! $allowed) {
                return;
            }
        }
        $locale = $user->preferredLocale();
        $key = 'support_reply:'.$reply->public_id;
        if ($user->notifications()->where('event_key', $key)->exists()) {
            return;
        }
        $n = $user->notifications()->create([
            'id' => (string) Str::uuid(), 'type' => SupportReply::class, 'event_type' => $event, 'event_key' => $key,
            'recipient_type' => $conversation->webshop_id === null ? 'consumer' : 'workspace', 'webshop_id' => $conversation->webshop_id,
            'data' => [...app(NotificationTemplates::class)->resolve($event, $locale),
                'workspace_id' => $conversation->webshop_id === null ? null : Webshop::whereKey($conversation->webshop_id)->value('public_id'),
                'action_route' => '/app/support?conversation='.$conversation->public_id,
                'delivery_channels' => ['database' => 'delivered', 'email' => 'pending']],
        ]);
        $this->signal($user, 'NotificationCreated', $n->id);
        $this->signal($user, 'NotificationCountUpdated');
    }

    /** @return array<string, mixed> */
    public function read(User $user, string $id): array
    {
        return DB::transaction(function () use ($user, $id) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $n = $this->visible($user)->whereKey($id)->lockForUpdate()->first()
                ?? throw new ApiException('Notification not found.', 404, 'not_found');
            if ($n->read_at === null) {
                $n->forceFill(['read_at' => now()])->save();
                $this->signal($user, 'NotificationRead', $n->id);
                $this->signal($user, 'NotificationCountUpdated');
            }

            return $this->present($n);
        });
    }

    public function readAll(User $user): int
    {
        return DB::transaction(function () use ($user) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $count = $this->visible($user)->whereNull('read_at')->update(['read_at' => now(), 'updated_at' => now()]);
            if ($count > 0) {
                $this->signal($user, 'NotificationReadAll');
                $this->signal($user, 'NotificationCountUpdated');
            }

            return $count;
        });
    }

    /** Legacy shared state stays intact; this compatibility action marks only the acting user's personal projection.
     * @param  list<int>  $ids
     */
    public function readLegacy(User $user, array $ids): void
    {
        DB::transaction(function () use ($user, $ids) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            foreach ($ids as $legacyId) {
                DB::table('notification_legacy_reads')->insertOrIgnore(['legacy_notification_id' => $legacyId, 'user_id' => $user->id, 'read_at' => now()]);
            }
            if ($this->visible($user)->whereIn('legacy_notification_id', $ids)->whereNull('read_at')->update(['read_at' => now(), 'updated_at' => now()]) > 0) {
                $this->signal($user, 'NotificationReadAll');
                $this->signal($user, 'NotificationCountUpdated');
            }
        });
    }

    /** @return array<string, mixed> */
    public function present(CentralNotification $n): array
    {
        $data = $n->data;
        $delivery = NotificationDelivery::where('notification_id', $n->id)->first();
        $data['delivery_channels']['email'] = $delivery->status ?? 'not_requested';
        unset($data['voucher_ref'], $data['offer_ref'], $data['reminder_stage'], $data['reminder_expires_at']);

        return ['id' => $n->id, 'type' => $n->event_type, 'recipient_type' => $n->recipient_type,
            ...$data, 'read_at' => $n->read_at?->toIso8601String(), 'created_at' => $n->created_at->toIso8601String()];
    }

    public function signal(User $user, string $event, ?string $id = null): void
    {
        DB::afterCommit(function () use ($user, $event, $id) {
            try {
                event(new NotificationInboxChanged($user->public_id, $event, $id));
            } catch (Throwable $e) {
                // A queue/WebSocket outage must not roll back committed inbox/read state.
                report($e);
            }
        });
    }
}
