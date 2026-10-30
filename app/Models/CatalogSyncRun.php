<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CatalogSyncRun extends Model
{
    protected $fillable = [
        'webshop_id', 'connection_id', 'resource', 'status', 'imported', 'cursor', 'error',
        'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'imported' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $m) {
            $m->public_id ??= (string) Str::ulid();
        });
    }
}
