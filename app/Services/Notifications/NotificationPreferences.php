<?php

namespace App\Services\Notifications;

use App\Enums\AuditCategory;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/** Event-level switches never grant or replace consent in CommunicationPreferences. */
final class NotificationPreferences
{
    public const EVENTS = ['support_reply', 'workspace_support_reply'];

    /** @return array<string, mixed> */
    public function current(User $user): array
    {
        $row = DB::table('notification_preferences')->where('user_id', $user->id)->first();
        $stored = $row === null ? [] : json_decode($row->preferences, true, flags: JSON_THROW_ON_ERROR);
        $events = [];
        foreach (self::EVENTS as $event) {
            $events[$event] = ['in_app' => true, 'email' => (bool) ($stored[$event]['email'] ?? false)];
        }

        return ['locale' => $user->fresh()->preferredLocale(), 'email_enabled' => $row === null || (bool) $row->email_enabled,
            'preferences' => $events, 'email_delivery_available' => false];
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function update(User $user, array $input): array
    {
        return DB::transaction(function () use ($user, $input) {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $current = $this->current($locked);
            $events = $current['preferences'];
            foreach ($input['preferences'] ?? [] as $event => $channels) {
                $events[$event] = array_replace($events[$event], $channels);
            }
            if (isset($input['locale'])) {
                $locked->forceFill(['locale' => $input['locale']])->save();
            }
            DB::table('notification_preferences')->updateOrInsert(['user_id' => $locked->id], [
                'email_enabled' => $input['email_enabled'] ?? $current['email_enabled'],
                'preferences' => json_encode($events, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now(),
            ]);
            app(AuditLogger::class)->record('notifications.preferences_changed', category: AuditCategory::Identity, resource: $locked, actorUserId: $locked->id,
                metadata: ['events' => array_keys($input['preferences'] ?? []), 'locale_changed' => isset($input['locale'])]);

            return $this->current($locked);
        });
    }
}
