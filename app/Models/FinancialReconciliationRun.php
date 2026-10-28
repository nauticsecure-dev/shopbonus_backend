<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $public_id
 * @property CarbonImmutable|null $completed_at
 * @property string $status
 * @property int $wallet_watermark
 * @property int $wallet_cursor
 * @property int $checked
 * @property int $scope_version
 * @property array<string, array{max:int,cursor:int,checked:int,done:bool}>|null $source_state
 * @property int $unknown_checks
 * @property int $discrepancies
 */
class FinancialReconciliationRun extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['source_state' => 'array', 'scope_version' => 'integer', 'unknown_checks' => 'integer', 'wallet_watermark' => 'integer', 'wallet_cursor' => 'integer', 'checked' => 'integer', 'discrepancies' => 'integer', 'completed_at' => 'immutable_datetime'];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }
}
