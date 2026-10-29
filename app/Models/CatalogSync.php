<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One read-only catalog or coupon pass over a connection. `completed` means
 * every provider page was read, so items not seen are marked removed;
 * `partial` stopped at the page cap and removes nothing.
 *
 * @property int $id
 * @property string $public_id
 * @property int $connection_id
 * @property string $kind
 * @property string $status
 * @property int $credential_generation
 * @property int $pages
 * @property int $items
 * @property int $removed
 * @property string|null $error_code
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $finished_at
 */
class CatalogSync extends Model
{
    use HasUlids;

    public const KINDS = ['products', 'coupons'];

    protected $guarded = ['id'];

    protected $hidden = ['id', 'connection_id', 'credential_generation'];

    protected function casts(): array
    {
        return ['pages' => 'integer', 'items' => 'integer', 'removed' => 'integer', 'credential_generation' => 'integer',
            'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime'];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }
}
