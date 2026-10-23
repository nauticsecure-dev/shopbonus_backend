<?php

namespace App\Models;

use App\Enums\WalletKind;
use App\Enums\WalletUnit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One wallet per (owner, kind, unit). The balances are a cache of the
 * ledger, written only by App\Services\Wallet\WalletService inside the
 * same transaction as the ledger entry; `shopbonus:wallet-reconcile`
 * proves they match.
 *
 * @property int $id
 * @property string $public_id
 * @property string $owner_type
 * @property int $owner_id
 * @property WalletKind $kind
 * @property WalletUnit $unit
 * @property int $available
 * @property int $reserved
 * @property int $pending
 * @property int $total_earned
 * @property int $total_spent
 * @property int $total_expired
 * @property CarbonImmutable $created_at
 */
class Wallet extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected $hidden = ['id', 'owner_type', 'owner_id'];

    protected $attributes = ['available' => 0, 'reserved' => 0, 'pending' => 0, 'total_earned' => 0, 'total_spent' => 0, 'total_expired' => 0];

    protected function casts(): array
    {
        return [
            'kind' => WalletKind::class,
            'unit' => WalletUnit::class,
            'available' => 'integer', 'reserved' => 'integer', 'pending' => 'integer',
            'total_earned' => 'integer', 'total_spent' => 'integer', 'total_expired' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /** @return HasMany<WalletLedgerEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(WalletLedgerEntry::class);
    }
}
