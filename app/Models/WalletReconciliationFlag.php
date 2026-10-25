<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Something the ledger could not settle automatically (e.g. a reversal of
 * credit that was already spent). For controlled review (B16).
 *
 * @property string $public_id
 * @property int|null $resolved_by
 * @property CarbonImmutable|null $resolved_at
 * @property string|null $resolution_reason
 * @property int $id
 * @property int $wallet_id
 * @property string $reason
 * @property int $amount
 * @property array<string, mixed>|null $details
 * @property string $status
 */
class WalletReconciliationFlag extends Model
{
    use HasUlids;

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'details' => 'array', 'resolved_at' => 'immutable_datetime'];
    }
}
