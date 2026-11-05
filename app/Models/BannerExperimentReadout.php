<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $public_id
 * @property int $experiment_id
 * @property int $protocol_id
 * @property array<string,mixed> $result
 * @property array<string,mixed> $source_snapshot
 * @property CarbonImmutable $created_at
 */
class BannerExperimentReadout extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['result' => 'encrypted:array', 'source_snapshot' => 'encrypted:array', 'created_at' => 'immutable_datetime'];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Finalized readouts are immutable.'));
        static::deleting(fn () => throw new \LogicException('Finalized readout history must be retained.'));
    }
}
