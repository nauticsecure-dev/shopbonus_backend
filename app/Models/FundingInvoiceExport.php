<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Frozen provider request plus conservative external-effect state, not a wallet posting.
 *
 * @property int $id
 * @property string $public_id
 * @property int $invoice_id
 * @property int $created_by
 * @property string $administration_id
 * @property string $mapping_reference
 * @property string $phase
 * @property string $request_hash
 * @property array<string,mixed> $request_payload
 * @property array<int,array<string,mixed>> $source_snapshot
 * @property int $expected_total_minor
 * @property string|null $remote_id
 * @property string|null $remote_updated_at
 * @property string|null $remote_observation_hash
 * @property string|null $remote_state
 * @property int|null $unpaid_minor
 * @property string|null $last_error_code
 * @property CarbonImmutable|null $delivery_acknowledged_at
 */
class FundingInvoiceExport extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected $hidden = ['id', 'invoice_id', 'request_payload', 'source_snapshot'];

    protected function casts(): array
    {
        return ['request_payload' => 'encrypted:array', 'source_snapshot' => 'encrypted:array', 'expected_total_minor' => 'integer', 'unpaid_minor' => 'integer',
            'attempted_at' => 'immutable_datetime', 'delivery_acknowledged_at' => 'immutable_datetime', 'reconciled_at' => 'immutable_datetime'];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $export) {
            if ($export->isDirty(['public_id', 'invoice_id', 'created_by', 'administration_id', 'mapping_reference', 'request_hash', 'request_payload', 'source_snapshot', 'expected_total_minor'])) {
                throw new \LogicException('Moneybird export evidence is immutable.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Moneybird export history must be retained.'));
    }
}
