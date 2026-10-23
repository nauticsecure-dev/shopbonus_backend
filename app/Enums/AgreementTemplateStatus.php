<?php

namespace App\Enums;

enum AgreementTemplateStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Retired = 'retired';
}
