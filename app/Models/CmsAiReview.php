<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;

/** @property int $reviewed_by
 * @property string $proposal_hash
 * @property string $source_hash
 * @property string $locale
 * @property bool $accepted
 */
class CmsAiReview extends Model
{
    use AppendOnly;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['accepted' => 'boolean'];
    }
}
