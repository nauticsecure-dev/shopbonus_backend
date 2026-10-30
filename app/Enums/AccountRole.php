<?php

namespace App\Enums;

/**
 * Primary account role for routing and UI (Trello #4).
 *
 * - `consumer` / `merchant` are chosen at public registration (`account_type`).
 * - `super_admin` is never grantable via signup; only console / admin revoke flows.
 */
enum AccountRole: string
{
    case Consumer = 'consumer';
    case Merchant = 'merchant';
    case SuperAdmin = 'super_admin';
}
