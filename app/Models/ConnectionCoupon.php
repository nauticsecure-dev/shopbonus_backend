<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-only snapshot of one native provider coupon (Trello #1 §4). A coupon
 * is a merchant discount, never ShopBonus wallet value; the code is
 * encrypted at rest.
 *
 * @property int $id
 * @property string $code
 * @property string|null $status
 * @property int|null $usage_limit
 * @property int $usage_count
 * @property array<string, list<string>>|null $scope
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $provider_updated_at
 * @property CarbonImmutable|null $removed_at
 */
class ConnectionCoupon extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['code'];

    protected function casts(): array
    {
        return ['code' => 'encrypted', 'scope' => 'array', 'amount_minor' => 'integer', 'percent_bps' => 'integer', 'minimum_spend_minor' => 'integer',
            'maximum_spend_minor' => 'integer', 'usage_limit' => 'integer', 'usage_limit_per_customer' => 'integer', 'usage_count' => 'integer',
            'stackable' => 'boolean', 'exclude_sale_items' => 'boolean', 'free_shipping' => 'boolean', 'customer_restricted' => 'boolean',
            'expires_at' => 'immutable_datetime', 'provider_updated_at' => 'immutable_datetime', 'removed_at' => 'immutable_datetime'];
    }

    /** Usable at the provider as far as the snapshot shows: published/active, not expired, uses left. */
    public function isActive(?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();

        return in_array($this->status, [null, 'publish', 'active'], true) && $this->removed_at === null
            && ($this->expires_at === null || $this->expires_at->isAfter($now))
            && ($this->usage_limit === null || $this->usage_count < $this->usage_limit);
    }
}
