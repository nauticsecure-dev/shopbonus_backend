<?php

namespace App\Services\Notifications;

use App\Enums\MembershipStatus;
use App\Enums\WebshopStatus;
use App\Events\NotificationInboxChanged;
use App\Exceptions\ApiException;
use App\Models\CentralNotification;
use App\Models\SupportConversation;
use App\Models\SupportReply;
use App\Models\User;
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
        return CentralNotification::query()->where('notifiable_type', $user->getMorphClass())->where('notifiable_id', $user->id)
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
            'data' => ['title' => $locale === 'nl' ? 'Nieuw antwoord van support' : 'New support reply',
                'body' => $locale === 'nl' ? 'Er staat een antwoord voor je klaar in je supportgesprek.' : 'A reply is available in your support conversation.',
                'locale' => $locale, 'action_label' => $locale === 'nl' ? 'Bekijk gesprek' : 'View conversation',
                'action_route' => '/app/support?conversation='.$conversation->public_id, 'template_version' => 'support-reply-v1',
                'delivery_channels' => ['database' => 'delivered', 'email' => 'not_implemented']],
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

    /** @return array<string, mixed> */
    public function present(CentralNotification $n): array
    {
        return ['id' => $n->id, 'type' => $n->event_type, 'recipient_type' => $n->recipient_type,
            ...$n->data, 'read_at' => $n->read_at?->toIso8601String(), 'created_at' => $n->created_at->toIso8601String()];
    }

    private function signal(User $user, string $event, ?string $id = null): void
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
