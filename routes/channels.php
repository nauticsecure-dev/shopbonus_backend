<?php

use App\Enums\MembershipStatus;
use App\Enums\WorkspacePermission;
use App\Models\User;
use App\Models\Webshop;
use App\Models\WebshopMembership;
use Illuminate\Support\Facades\Broadcast;

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
