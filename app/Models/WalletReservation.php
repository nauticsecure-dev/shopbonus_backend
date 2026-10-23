<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Wallet value held for a checkout (active), then captured (debited) or
 * released. Status: active → captured | released.
 *
 * @property int $id
 * @property string $public_id
 * @property int $wallet_id
 * @property int $amount
 * @property string $status
 * @property string|null $source_type
 * @property string|null $source_ref
 * @property string $idempotency_key
 * @property list<array{lot_id: int, amount: int}> $allocations
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $settled_at
 */
class WalletReservation extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected $hidden = ['id', 'wallet_id', 'allocations', 'idempotency_key'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'allocations' => 'array', 'expires_at' => 'immutable_datetime', 'settled_at' => 'immutable_datetime'];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }
}
