<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Expected credit that is not spendable yet (Trello #19 "Pending").
 * Status: pending → confirmed (one credit posted) | cancelled.
 *
 * @property int $id
 * @property string $public_id
 * @property int $wallet_id
 * @property int $amount
 * @property string $status
 * @property string|null $source_type
 * @property string|null $source_ref
 * @property string|null $description
 * @property string $idempotency_key
 * @property int|null $credit_entry_id
 * @property CarbonImmutable|null $settled_at
 * @property CarbonImmutable $created_at
 */
class WalletPendingCredit extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected $hidden = ['id', 'wallet_id', 'idempotency_key', 'credit_entry_id'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'settled_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }
}
