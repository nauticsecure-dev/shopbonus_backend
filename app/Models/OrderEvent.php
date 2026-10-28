<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only order history (no PII).
 *
 * @property list<array<string,mixed>>|null $line_items_snapshot
 * @property int $id
 * @property int $order_id
 * @property int $webshop_id
 * @property int|null $webhook_event_id
 * @property string $type
 * @property string|null $from_payment_status
 * @property string|null $to_payment_status
 * @property array<string, mixed>|null $summary
 * @property CarbonImmutable|null $provider_updated_at
 * @property CarbonImmutable $created_at
 */
class OrderEvent extends Model
{
    use AppendOnly;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'line_items_snapshot' => 'encrypted:array',
            'provider_updated_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
