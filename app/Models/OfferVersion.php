<?php

namespace App\Models;

use App\Services\Discovery\DiscoveryCache;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One immutable-once-locked set of offer terms.
 *
 * @property int $id
 * @property int $offer_id
 * @property int $version
 * @property string $name
 * @property string|null $public_description
 * @property string $discount_type
 * @property int $discount_value
 * @property string $currency
 * @property int|null $min_order_minor
 * @property int|null $max_discount_minor
 * @property int|null $price_points
 * @property int|null $price_minor
 * @property CarbonImmutable|null $starts_at
 * @property CarbonImmutable|null $ends_at
 * @property int|null $claim_validity_days
 * @property int|null $redemption_validity_days
 * @property list<string>|null $categories
 * @property list<string>|null $products
 * @property bool $combinable
 * @property string $code_mode
 * @property int|null $allocation_limit
 * @property CarbonImmutable|null $locked_at
 * @property int $created_by
 */
class OfferVersion extends Model
{
    public const TERM_FIELDS = [
        'name', 'public_description', 'discount_type', 'discount_value', 'currency', 'min_order_minor', 'max_discount_minor',
        'price_minor', 'price_points', 'starts_at', 'ends_at', 'claim_validity_days', 'redemption_validity_days', 'categories', 'products',
        'combinable', 'code_mode', 'allocation_limit',
    ];

    protected $guarded = ['id'];

    protected $hidden = ['id', 'offer_id', 'created_by'];

    protected function casts(): array
    {
        return [
            'discount_value' => 'integer',
            'min_order_minor' => 'integer',
            'max_discount_minor' => 'integer',
            'price_minor' => 'integer', 'price_points' => 'integer',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'claim_validity_days' => 'integer',
            'redemption_validity_days' => 'integer',
            'categories' => 'array',
            'products' => 'array',
            'combinable' => 'boolean',
            'allocation_limit' => 'integer',
            'locked_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => DiscoveryCache::flush());
        // Terms that vouchers were issued under can never change.
        static::updating(function (self $version) {
            if ($version->getOriginal('locked_at') !== null && $version->isDirty(self::TERM_FIELDS)) {
                throw new LogicException('Locked offer versions are immutable; create a new version.');
            }
        });
        static::deleting(fn () => throw new LogicException('Offer versions cannot be deleted.'));
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    /**
     * Human summary, e.g. "10% from €100.00, maximum €10.00".
     */
    public function summary(): string
    {
        $money = fn (int $minor) => '€'.number_format($minor / 100, 2, '.', '');
        if ($this->discount_type === 'free_shipping') {
            return 'Free shipping'.($this->min_order_minor ? ' from '.$money($this->min_order_minor) : '');
        }
        $value = $this->discount_type === 'percentage'
            ? rtrim(rtrim(number_format($this->discount_value / 100, 2, '.', ''), '0'), '.').'%'
            : $money($this->discount_value);

        return $value
            .($this->min_order_minor ? ' from '.$money($this->min_order_minor) : '')
            .($this->max_discount_minor ? ', maximum '.$money($this->max_discount_minor) : '');
    }

    /** @return BelongsTo<Offer, $this> */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }
}
