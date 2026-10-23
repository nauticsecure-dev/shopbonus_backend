<?php

namespace App\Enums;

/**
 * What a user said they came for at signup. Used only to choose where to
 * send them next; it never grants a role or workspace membership.
 */
enum OnboardingIntent: string
{
    case Consumer = 'consumer';
    case Merchant = 'merchant';
}
