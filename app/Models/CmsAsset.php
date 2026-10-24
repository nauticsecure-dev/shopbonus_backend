<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $public_id
 * @property string $path
 * @property string $mime
 * @property int $width
 * @property int $height
 * @property int $uploaded_by
 */
class CmsAsset extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected $hidden = ['id', 'path', 'uploaded_by'];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }
}
