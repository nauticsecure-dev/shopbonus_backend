<?php

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\Login;

/**
 * Keeps `users.last_login_at` current (cockpit "active consumers", member
 * last activity). Fires for password logins and completed 2FA challenges;
 * support-session tokens never log in, so they do not count.
 */
class RecordLastLogin
{
    public function handle(Login $event): void
    {
        if ($event->user instanceof User) {
            User::whereKey($event->user->getAuthIdentifier())->update(['last_login_at' => now()]);
        }
    }
}
