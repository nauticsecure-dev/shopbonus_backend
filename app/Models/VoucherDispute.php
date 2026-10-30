<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A consumer's voucher problem (Trello #20 "Cancellations, refunds &
 * disputes"). Status: open → under_review ↔ waiting_for_merchant →
 * resolved | rejected. Compensation is applied at most once.
 *
 * @property int $id
 * @property string $public_id
 * @property int $user_id
 * @property int $webshop_id
 * @property int|null $voucher_id
 * @property int|null $checkout_id
 * @property string $reason
 * @property string $description
 * @property list<array{id: string, name: string, mime: string, size: int}>|null $evidence
 * @property string $status
 * @property string|null $compensation
 * @property int|null $compensation_amount_minor
 * @property string|null $compensation_ref
 * @property CarbonImmutable|null $compensated_at
 * @property string|null $resolution_note
 * @property int|null $resolved_by
 * @property CarbonImmutable|null $resolved_at
 * @property string|null $open_key
 * @property CarbonImmutable $created_at
 */
class VoucherDispute extends Model
{
    use HasUlids;

    public const REASONS = ['code_invalid', 'code_already_used', 'refused_by_webshop', 'terms_differ', 'missing_after_payment', 'other'];

    public const CLOSED = ['resolved', 'rejected'];

    protected $guarded = ['id'];

    protected $hidden = ['id', 'user_id', 'webshop_id', 'voucher_id', 'checkout_id', 'resolved_by', 'open_key'];

    protected function casts(): array
    {
        return ['evidence' => 'array', 'compensation_amount_minor' => 'integer', 'compensated_at' => 'immutable_datetime', 'resolved_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }
}
