<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * The still-spendable remainder of one credit, with its expiry. Spending
 * takes the oldest-expiring lot first (Trello #23).
 *
 * @property int $id
 * @property int $wallet_id
 * @property int $entry_id
 * @property int $amount
 * @property int $remaining
 * @property CarbonImmutable|null $expires_at
 */
class WalletCreditLot extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'remaining' => 'integer', 'expires_at' => 'immutable_datetime'];
    }
}
