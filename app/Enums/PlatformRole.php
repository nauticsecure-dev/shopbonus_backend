<?php

namespace App\Enums;

/**
 * Platform-wide roles, provisioned privately (console), never through
 * signup or merchant interfaces.
 */
enum PlatformRole: string
{
    case SuperAdmin = 'super_admin';
}
