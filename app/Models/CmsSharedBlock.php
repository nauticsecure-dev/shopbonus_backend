<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $public_id
 * @property string $key
 * @property int $revision
 */
class CmsSharedBlock extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['revision' => 'integer'];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }
}
