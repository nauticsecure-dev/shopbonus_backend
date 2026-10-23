<?php

namespace App\Enums;

/**
 * Workspace lifecycle (Trello #16). Only Approved webshops are commercially
 * active. Transitions are enforced by WebshopLifecycle (B03d).
 */
enum WebshopStatus: string
{
    case Draft = 'draft';
    case AwaitingVerification = 'awaiting_verification';
    case VerificationFailed = 'verification_failed';
    case PendingReview = 'pending_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Suspended = 'suspended';

    public function isCommerciallyActive(): bool
    {
        return $this === self::Approved;
    }

    /** Members may still open the workspace (e.g. to finish onboarding or read status). */
    public function allowsMemberAccess(): bool
    {
        return $this !== self::Suspended;
    }
}
