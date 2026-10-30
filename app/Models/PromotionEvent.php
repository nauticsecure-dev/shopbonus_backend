<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Backend-verified funnel events (Trello #24 §1): claim/account/voucher/order.
 * Browser events stay in banner_events; this table never stores PII or payment secrets.
 *
 * @property int $id
 * @property string $event_id
 * @property int $webshop_id
 * @property string $type
 * @property string $source
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property array<string, mixed>|null $context
 * @property CarbonImmutable $occurred_at
 */
class PromotionEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['id', 'webshop_id'];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function webshop(): BelongsTo
    {
        return $this->belongsTo(Webshop::class);
    }
}
