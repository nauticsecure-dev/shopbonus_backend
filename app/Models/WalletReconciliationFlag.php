<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Something the ledger could not settle automatically (e.g. a reversal of
 * credit that was already spent). For controlled review (B16).
 *
 * @property int $id
 * @property int $wallet_id
 * @property string $reason
 * @property int $amount
 * @property array<string, mixed>|null $details
 * @property string $status
 */
class WalletReconciliationFlag extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'details' => 'array'];
    }
}
