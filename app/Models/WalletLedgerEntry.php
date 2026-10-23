<?php

namespace App\Models;

use App\Enums\LedgerEntryType;
use App\Models\Concerns\AppendOnly;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A posted ledger entry. Append-only here and by database triggers;
 * corrections are compensating entries.
 *
 * @property int $id
 * @property string $public_id
 * @property int $wallet_id
 * @property LedgerEntryType $type
 * @property int $amount
 * @property int $available_delta
 * @property int $reserved_delta
 * @property int $available_after
 * @property int $reserved_after
 * @property string|null $source_type
 * @property string|null $source_ref
 * @property string|null $reason
 * @property string $origin
 * @property int|null $actor_user_id
 * @property string $idempotency_key
 * @property int|null $related_entry_id
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable $created_at
 */
class WalletLedgerEntry extends Model
{
    use AppendOnly, HasUlids;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $hidden = ['id', 'wallet_id', 'actor_user_id', 'related_entry_id', 'idempotency_key'];

    protected function casts(): array
    {
        return [
            'type' => LedgerEntryType::class,
            'amount' => 'integer', 'available_delta' => 'integer', 'reserved_delta' => 'integer',
            'available_after' => 'integer', 'reserved_after' => 'integer',
            'expires_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }
}
