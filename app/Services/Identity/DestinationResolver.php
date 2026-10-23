<?php

namespace App\Services\Identity;

use App\Enums\Destination;
use App\Enums\OnboardingIntent;
use App\Models\User;

/**
 * Chooses the post-login destination. B03 extends this with workspace and
 * admin destinations once memberships exist; the onboarding intent alone
 * never grants access, it only selects the setup screen.
 */
class DestinationResolver
{
    public function for(User $user): Destination
    {
        if ($user->email_verified_at === null) {
            return Destination::VerifyEmail;
        }

        return $user->onboarding_intent === OnboardingIntent::Merchant
            ? Destination::WebshopSetup
            : Destination::ConsumerDashboard;
    }
}
