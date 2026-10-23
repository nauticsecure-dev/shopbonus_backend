<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only history of vouchers and codes. Never contains code values.
 *
 * @property int $id
 * @property int $webshop_id
 * @property int|null $voucher_id
 * @property int|null $voucher_code_id
 * @property string $type
 * @property string|null $from_status
 * @property string|null $to_status
 * @property int|null $actor_user_id
 * @property array<string, mixed>|null $summary
 * @property CarbonImmutable $created_at
 */
class VoucherEvent extends Model
{
    use AppendOnly;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['summary' => 'array', 'created_at' => 'immutable_datetime'];
    }
}
