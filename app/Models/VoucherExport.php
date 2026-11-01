<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A large voucher CSV export built by a queued job (Trello #17). Private
 * file, downloadable only by the requester until it expires.
 *
 * @property int $id
 * @property string $public_id
 * @property int $webshop_id
 * @property int $requested_by
 * @property array<string, mixed> $filters
 * @property string $status
 * @property int|null $row_count
 * @property int|null $size_bytes
 * @property string|null $path
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable $created_at
 */
class VoucherExport extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected $hidden = ['id', 'webshop_id', 'requested_by', 'path'];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'completed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /** @return array<string, mixed> */
    public function present(): array
    {
        return ['id' => $this->public_id, 'status' => $this->status, 'rows' => $this->row_count, 'size_bytes' => $this->size_bytes,
            'created_at' => $this->created_at->toIso8601String(), 'completed_at' => $this->completed_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String()];
    }
}
