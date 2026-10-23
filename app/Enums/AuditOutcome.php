<?php

namespace App\Enums;

enum AuditOutcome: string
{
    case Success = 'success';
    case Denied = 'denied';
    case Failure = 'failure';
}
