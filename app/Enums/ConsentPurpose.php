<?php

namespace App\Enums;

/**
 * Optional, consent-based purposes. Mandatory account and security messages
 * are not listed: they do not depend on consent.
 */
enum ConsentPurpose: string
{
    case MarketingEmail = 'marketing_email';
}
