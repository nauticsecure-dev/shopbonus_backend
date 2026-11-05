<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** @property int $id
 * @property string $public_id
 * @property int $asset_id
 * @property int $version
 * @property int $created_by
 * @property string $original_hash
 * @property array<string,mixed> $recipe
 */
class CmsMediaRecipe extends Model
{
    use AppendOnly, HasUlids;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    protected function casts(): array
    {
        return ['recipe' => 'array', 'version' => 'integer'];
    }
}
