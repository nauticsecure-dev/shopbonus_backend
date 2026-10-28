<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $public_id
 * @property string $status
 * @property int $wallet_watermark
 * @property int $wallet_cursor
 * @property int $checked
 * @property int $discrepancies
 */
class FinancialReconciliationRun extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['wallet_watermark' => 'integer', 'wallet_cursor' => 'integer', 'checked' => 'integer', 'discrepancies' => 'integer', 'completed_at' => 'immutable_datetime'];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }
}
