<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $public_id
 * @property int $campaign_id
 * @property int|null $active_campaign_id
 * @property int $created_by
 * @property array<string,mixed> $protocol
 * @property list<array<string,mixed>> $measurement
 * @property string $protocol_hash
 * @property CarbonImmutable $created_at
 */
class BannerExperimentProtocol extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['protocol' => 'encrypted:array', 'measurement' => 'encrypted:array', 'created_at' => 'immutable_datetime'];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $p) {
            if ($p->isDirty(['campaign_id', 'public_id', 'created_by', 'protocol', 'measurement', 'protocol_hash', 'created_at'])) {
                throw new \LogicException('Registered protocol evidence is immutable.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Protocol history must be retained.'));
    }
}
