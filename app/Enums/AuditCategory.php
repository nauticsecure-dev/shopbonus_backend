<?php

namespace App\Enums;

/**
 * Coarse grouping for audit filtering. Extend as domains are added; never
 * rename a value already stored.
 */
enum AuditCategory: string
{
    case Security = 'security';
    case Identity = 'identity';
    case Workspace = 'workspace';
    case Integration = 'integration';
    case Order = 'order';
    case Financial = 'financial';
    case Voucher = 'voucher';
    case Admin = 'admin';
}
