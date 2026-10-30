<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CatalogProduct extends Model
{
    protected $fillable = [
        'webshop_id', 'connection_id', 'platform', 'external_product_id', 'sku', 'name', 'description',
        'product_url', 'image_url', 'category', 'price_minor', 'currency', 'availability',
        'provider_updated_at', 'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'price_minor' => 'integer',
            'provider_updated_at' => 'datetime',
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
