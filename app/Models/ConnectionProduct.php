<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-only snapshot of one provider product (Trello #1 §4).
 *
 * @property int $id
 * @property int $connection_id
 * @property string $external_id
 * @property list<array{id: string, name: string}>|null $categories
 * @property CarbonImmutable|null $provider_updated_at
 * @property CarbonImmutable|null $removed_at
 */
class ConnectionProduct extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['categories' => 'array', 'price_minor' => 'integer', 'regular_price_minor' => 'integer', 'sale_price_minor' => 'integer',
            'stock_quantity' => 'integer', 'provider_updated_at' => 'immutable_datetime', 'removed_at' => 'immutable_datetime'];
    }
}
