<?php

namespace App\Services\Workspaces;

use App\Models\User;
use App\Services\Admin\SupportSessions;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

/**
 * Immediate realtime revocation (Trello #16). Channel auth is rechecked on
 * every subscription, but an open socket keeps its subscriptions; so when
 * access is withdrawn, every connection of that user is terminated
 * (Pusher/Reverb `terminate_connections`). The client reconnects and is
 * refused the channels it lost. Requires the client to sign in as a user
 * (`/api/broadcasting/user-auth`) so the server knows whose sockets they are.
 */
class RealtimeAccess
{
    /**
     * Identity for Pusher user authentication: the public ID, never the
     * internal key. Support (impersonation) sessions do not sign in.
     *
     * @return array{id: string}|null
     */
    public static function signInIdentity(Request $request): ?array
    {
        $user = $request->user();
        if (! $user instanceof User || ! $user->isActive()) {
            return null;
        }
        $token = $user->currentAccessToken();
        if ($token instanceof PersonalAccessToken && ($token->name === SupportSessions::TOKEN_NAME || app(SupportSessions::class)->forToken($token) !== null)) {
            return null;
        }

        return ['id' => $user->public_id];
    }

    /** After the surrounding transaction commits; a broadcaster failure never undoes the revocation itself. */
    public function terminate(int $userId): void
    {
        DB::afterCommit(function () use ($userId) {
            $publicId = User::whereKey($userId)->value('public_id');
            $broadcaster = Broadcast::connection();
            if ($publicId === null || ! $broadcaster instanceof PusherBroadcaster) {
                return; // log/null drivers hold no sockets
            }
            try {
                $broadcaster->getPusher()->terminateUserConnections((string) $publicId);
            } catch (Throwable $e) {
                report($e);
            }
        });
    }
}
