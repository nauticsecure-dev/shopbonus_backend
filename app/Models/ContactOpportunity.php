<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One Answer24 contact moment for one customer of one webshop, grouping the
 * customer's eligible orders placed within a rolling one-hour window.
 *
 * Status: pending (window open) → ready (queued for Answer24) → dispatched →
 * accepted / rejected / attempted / completed / failed; or cancelled.
 * cancellation_requested: cancelled after dispatch, suppression sent.
 *
 * @property int $id
 * @property string $public_id
 * @property int $webshop_id
 * @property int|null $offer_id
 * @property string $customer_key
 * @property string $status
 * @property string $lawful_basis
 * @property CarbonImmutable $window_ends_at
 * @property string $idempotency_key
 * @property string|null $remote_reference
 * @property CarbonImmutable|null $ready_at
 * @property CarbonImmutable|null $dispatched_at
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $cancel_reason
 * @property CarbonImmutable|null $last_status_at
 * @property CarbonImmutable $created_at
 */
class ContactOpportunity extends Model
{
    use HasUlids;

    public const OPEN = ['pending', 'ready'];

    public const DISPATCHED = ['dispatched', 'accepted', 'attempted', 'completed'];

    protected $guarded = ['id'];

    protected $hidden = ['id', 'webshop_id', 'offer_id', 'customer_key'];

    protected function casts(): array
    {
        return [
            'window_ends_at' => 'immutable_datetime',
            'ready_at' => 'immutable_datetime',
            'dispatched_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'last_status_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /** @return HasMany<ContactOpportunityOrder, $this> */
    public function links(): HasMany
    {
        return $this->hasMany(ContactOpportunityOrder::class, 'opportunity_id');
    }
}
