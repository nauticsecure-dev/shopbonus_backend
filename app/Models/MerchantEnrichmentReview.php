<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $public_id
 * @property int $run_id
 * @property int $reviewed_by
 * @property string $decision
 * @property string $note
 * @property CarbonImmutable $created_at
 */
class MerchantEnrichmentReview extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $guarded = ['id'];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }
}
