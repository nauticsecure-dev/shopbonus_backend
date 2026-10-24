<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $public_id
 * @property string $key
 * @property array{nl: string, en: string} $names
 * @property int $position
 */
class FaqCategory extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected $hidden = ['id'];

    protected $attributes = ['position' => 0];

    protected function casts(): array
    {
        return ['names' => 'array', 'position' => 'integer'];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }
}
