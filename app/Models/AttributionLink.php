<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Primary evidence joining a paid order to a click, voucher or experiment view
 * (Trello #24 §2). One row per order; refunds adjust order figures, not this link.
 *
 * @property int $id
 * @property string $public_id
 * @property int $webshop_id
 * @property int $order_id
 * @property string $method
 * @property string|null $click_id
 * @property int|null $voucher_id
 * @property int|null $banner_assignment_id
 * @property int|null $referring_webshop_id
 * @property int $lookback_days
 * @property CarbonImmutable $linked_at
 */
class AttributionLink extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['id', 'webshop_id', 'order_id', 'voucher_id', 'banner_assignment_id', 'referring_webshop_id'];

    protected function casts(): array
    {
        return [
            'lookback_days' => 'integer',
            'linked_at' => 'immutable_datetime',
        ];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /** @return BelongsTo<WebshopOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(WebshopOrder::class, 'order_id');
    }

    /** @return BelongsTo<Webshop, $this> */
    public function webshop(): BelongsTo
    {
        return $this->belongsTo(Webshop::class);
    }
}
