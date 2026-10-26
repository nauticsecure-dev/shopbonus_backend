<?php

use App\Enums\MembershipStatus;
use App\Enums\WorkspacePermission;
use App\Models\User;
use App\Models\Webshop;
use App\Models\WebshopMembership;
use App\Services\Admin\SupportSessions;
use App\Services\Support\SupportChat;
use Illuminate\Support\Facades\Broadcast;
use Laravel\Sanctum\PersonalAccessToken;

/*
| Private channel authorisation. Revalidated on every subscription: a
| removed member or suspended webshop cannot (re)subscribe.
*/

Broadcast::channel('webshops.{webshopPublicId}.orders', function (User $user, string $webshopPublicId) {
    $webshop = Webshop::where('public_id', $webshopPublicId)->first();

    if ($webshop === null || ! $user->isActive() || ! $webshop->status->allowsMemberAccess()) {
        return false;
    }

    $membership = WebshopMembership::where('webshop_id', $webshop->id)->where('user_id', $user->id)
        ->where('status', MembershipStatus::Active)->first();

    return $membership?->allows(WorkspacePermission::OrdersView) ?? false;
});

Broadcast::channel('shopbonus.support.conversation.{conversationId}', function (User $user, string $conversationId) {
    $token = $user->currentAccessToken();
    if ($token instanceof PersonalAccessToken && app(SupportSessions::class)->forToken($token) !== null) {
        return false;
    }
    try {
        app(SupportChat::class)->conversation($user, $conversationId);

        return true;
    } catch (Throwable) {
        return false;
    }
});

Broadcast::channel('shopbonus.user.{userId}.notifications', function (User $user, string $userId) {
    $token = $user->currentAccessToken();

    return $user->isActive() && $user->email_verified_at !== null && $user->public_id === $userId
        && ! ($token instanceof PersonalAccessToken && ($token->name === SupportSessions::TOKEN_NAME || app(SupportSessions::class)->forToken($token) !== null));
});
