<?php

namespace App\Enums;

enum ErrorSource: string
{
    case Http = 'http';
    case Queue = 'queue';
    case Console = 'console';
}
