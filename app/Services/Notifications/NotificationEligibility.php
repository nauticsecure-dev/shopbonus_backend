<?php

namespace App\Services\Notifications;

use App\Models\Offer;
use App\Models\User;
use App\Services\Discovery\PublicCatalog;
use Illuminate\Support\Facades\DB;

final class NotificationEligibility
{
    public function favorite(User $user, Offer $offer): bool
    {
        if ($offer->status->value !== 'active' || $offer->slug === null
            || ! DB::table('consumer_favorite_webshops')->where('user_id', $user->id)->where('webshop_id', $offer->webshop_id)->exists()) {
            return false;
        }
        $public = app(PublicCatalog::class)->offer($offer->slug);

        return $public !== null && ($public['availability'] ?? null) === 'available';
    }
}
