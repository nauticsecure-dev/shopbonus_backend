<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lawful Shop A banner → verified Shop B paid order (Trello #24 §5).
 * Settlement is recorded for agreed settlement only — never a silent Wallet debit on Shop B.
 *
 * @property int $id
 * @property string $public_id
 * @property int $referring_webshop_id
 * @property int $receiving_webshop_id
 * @property int $order_id
 * @property string|null $click_id
 * @property string|null $category
 * @property int $order_amount_minor
 * @property string $currency
 * @property string $settlement_status
 * @property CarbonImmutable $attributed_at
 */
class NetworkJourney extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected $hidden = ['id', 'referring_webshop_id', 'receiving_webshop_id', 'order_id'];

    protected function casts(): array
    {
        return [
            'order_amount_minor' => 'integer',
            'attributed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function referringWebshop(): BelongsTo
    {
        return $this->belongsTo(Webshop::class, 'referring_webshop_id');
    }

    public function receivingWebshop(): BelongsTo
    {
        return $this->belongsTo(Webshop::class, 'receiving_webshop_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(WebshopOrder::class, 'order_id');
    }
}
