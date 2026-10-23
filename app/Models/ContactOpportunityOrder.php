<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $opportunity_id
 * @property int $order_id
 * @property string $status
 * @property string|null $removed_reason
 * @property CarbonImmutable|null $removed_at
 * @property-read WebshopOrder $order
 * @property-read ContactOpportunity $opportunity
 */
class ContactOpportunityOrder extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['removed_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<WebshopOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(WebshopOrder::class, 'order_id');
    }

    /** @return BelongsTo<ContactOpportunity, $this> */
    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(ContactOpportunity::class, 'opportunity_id');
    }
}
