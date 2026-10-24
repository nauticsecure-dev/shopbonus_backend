<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * An uploaded banner image, served by opaque ID.
 *
 * @property int $id
 * @property string $public_id
 * @property int $webshop_id
 * @property string $path
 * @property string $mime
 * @property int $width
 * @property int $height
 * @property int $uploaded_by
 */
class BannerAsset extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected $hidden = ['id', 'webshop_id', 'path', 'uploaded_by'];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }
}
