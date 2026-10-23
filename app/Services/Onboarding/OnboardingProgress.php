<?php

namespace App\Services\Onboarding;

use App\Enums\VerificationStatus;
use App\Enums\WebshopStatus;
use App\Models\Webshop;

/**
 * Onboarding steps derived entirely from server-side state (Trello #16
 * "Users must never skip verification, agreement or approval by changing
 * URLs or API requests"). Nothing here can be set by a client.
 */
class OnboardingProgress
{
    public const STEPS = ['business_details', 'ownership', 'agreement', 'activation'];

    /**
     * @return array{steps: list<array{key: string, status: string}>, next_step: string|null, webshop_status: string}
     */
    public function for(Webshop $webshop): array
    {
        $profile = $webshop->businessProfile;
        $pendingChallenge = $webshop->verifications()->where('status', VerificationStatus::Pending)->exists();

        $statuses = [
            'business_details' => match (true) {
                $profile?->confirmed_at !== null => 'complete',
                $profile !== null => 'in_progress',
                default => 'not_started',
            },
            'ownership' => match (true) {
                $webshop->isOwnershipVerified() => 'complete',
                $webshop->status === WebshopStatus::PendingReview => 'in_review',
                $webshop->status === WebshopStatus::VerificationFailed => 'failed',
                $pendingChallenge => 'in_progress',
                default => 'not_started',
            },
            // Filled in by the agreement workflow (B03d).
            'agreement' => $this->agreementStatus($webshop),
            'activation' => match ($webshop->status) {
                WebshopStatus::Approved => 'complete',
                WebshopStatus::Rejected => 'rejected',
                WebshopStatus::Suspended => 'suspended',
                default => 'not_started',
            },
        ];

        $steps = array_map(fn (string $key) => ['key' => $key, 'status' => $statuses[$key]], self::STEPS);
        $next = collect($steps)->first(fn ($s) => ! in_array($s['status'], ['complete', 'in_review'], true));

        return [
            'steps' => $steps,
            'next_step' => $next['key'] ?? null,
            'webshop_status' => $webshop->status->value,
        ];
    }

    protected function agreementStatus(Webshop $webshop): string
    {
        return 'not_started';
    }
}
