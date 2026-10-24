<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A merchant program at a network, optionally matched to a webshop.
 *
 * @property int $id
 * @property string $public_id
 * @property int $connection_id
 * @property string $external_id
 * @property string $name
 * @property string|null $domain
 * @property int|null $webshop_id
 * @property bool $active
 * @property string $commission_type
 * @property int $commission_value
 * @property string $currency
 * @property bool $category_restricted
 * @property bool $cashback_allowed
 * @property string $tracking_url_template
 * @property CarbonImmutable|null $last_seen_at
 * @property-read AffiliateConnection $connection
 */
class AffiliateProgram extends Model
{
    use HasUlids;

    protected $table = 'affiliate_merchant_programs';

    protected $guarded = ['id'];

    protected $hidden = ['id', 'connection_id', 'webshop_id'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'category_restricted' => 'boolean', 'cashback_allowed' => 'boolean', 'commission_value' => 'integer', 'last_seen_at' => 'immutable_datetime'];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /** @return BelongsTo<AffiliateConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(AffiliateConnection::class, 'connection_id');
    }
}
