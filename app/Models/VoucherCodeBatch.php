<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $public_id
 * @property int $webshop_id
 * @property int $offer_id
 * @property string $source
 * @property string $status
 * @property array<string, int> $totals
 * @property list<array<string, mixed>>|null $rejections
 * @property list<string>|null $payload
 * @property CarbonImmutable $expires_at
 * @property int $created_by
 * @property int|null $committed_by
 * @property CarbonImmutable|null $committed_at
 * @property CarbonImmutable $created_at
 */
class VoucherCodeBatch extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected $hidden = ['id', 'webshop_id', 'offer_id', 'payload', 'created_by', 'committed_by'];

    protected function casts(): array
    {
        return [
            'totals' => 'array',
            'rejections' => 'array',
            'payload' => 'encrypted:array',
            'expires_at' => 'immutable_datetime',
            'committed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }
}
