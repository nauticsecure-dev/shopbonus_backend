<?php

namespace App\Enums;

enum VerificationMethod: string
{
    case DnsTxt = 'dns_txt';
    case WebsiteFile = 'website_file';
    case DomainEmail = 'domain_email';
    case ManualReview = 'manual_review';

    public function isAutomated(): bool
    {
        return $this !== self::ManualReview;
    }
}
