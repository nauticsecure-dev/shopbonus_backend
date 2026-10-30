<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CatalogCoupon extends Model
{
    protected $fillable = [
        'webshop_id', 'connection_id', 'platform', 'external_coupon_id', 'code', 'discount_type',
        'discount_value', 'minimum_spend', 'excluded_categories', 'start_at', 'end_at',
        'usage_limits', 'stacking_rules', 'status', 'eligible_public', 'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'excluded_categories' => 'array',
            'usage_limits' => 'array',
            'stacking_rules' => 'array',
            'eligible_public' => 'boolean',
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $m) {
            $m->public_id ??= (string) Str::ulid();
        });
    }
}
