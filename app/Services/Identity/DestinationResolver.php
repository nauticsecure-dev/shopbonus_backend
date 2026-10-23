<?php

namespace App\Services\Identity;

use App\Enums\Destination;
use App\Enums\MembershipStatus;
use App\Enums\OnboardingIntent;
use App\Models\User;

/**
 * Chooses the post-login destination from verified, server-owned state.
 * The onboarding intent alone never grants access; it only selects the
 * setup screen for users without a workspace.
 */
class DestinationResolver
{
    public function for(User $user): Destination
    {
        return match (true) {
            $user->email_verified_at === null => Destination::VerifyEmail,
            $user->isSuperAdmin() => Destination::Admin,
            $user->memberships()->where('status', MembershipStatus::Active)->exists() => Destination::Workspace,
            $user->onboarding_intent === OnboardingIntent::Merchant => Destination::WebshopSetup,
            default => Destination::ConsumerDashboard,
        };
    }
}
