<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $block_id
 * @property string $public_id
 * @property int $number
 * @property string $locale
 * @property array<string,mixed> $node
 * @property int $created_by
 * @property Carbon $created_at
 * @property string $content_hash
 */
class CmsSharedBlockVersion extends Model
{
    use AppendOnly, HasUlids;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['node' => 'array', 'number' => 'integer'];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }
}
