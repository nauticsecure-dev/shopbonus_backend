<?php

namespace App\Enums;

enum VerificationStatus: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Failed = 'failed';
    case Superseded = 'superseded';
    // Manual review submitted, awaiting Super Admin decision.
    case Submitted = 'submitted';
}
