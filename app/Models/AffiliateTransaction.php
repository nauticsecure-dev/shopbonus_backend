<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A normalised network conversion (Trello #19 "Commission reconciliation").
 * Network status (what the network says) and reward status (what the
 * consumer's wallet got) are kept apart (card #23).
 *
 * @property int $id
 * @property string $public_id
 * @property string $network
 * @property int $connection_id
 * @property string $external_id
 * @property int|null $program_id
 * @property int|null $click_id
 * @property int|null $user_id
 * @property int|null $webshop_id
 * @property string|null $order_reference_hash
 * @property int $order_amount_minor
 * @property string $currency
 * @property int $commission_minor
 * @property string $network_status
 * @property string $status
 * @property string|null $review_reason
 * @property int|null $duplicate_of_id
 * @property string $reward_status
 * @property int|null $reward_amount
 * @property string|null $reward_unit
 * @property int|null $reward_rule_version
 * @property int|null $wallet_pending_id
 * @property int|null $wallet_entry_id
 * @property CarbonImmutable $transaction_at
 * @property CarbonImmutable|null $network_updated_at
 * @property CarbonImmutable|null $last_synced_at
 */
class AffiliateTransaction extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected $hidden = ['id', 'connection_id', 'program_id', 'click_id', 'user_id', 'webshop_id', 'order_reference_hash', 'duplicate_of_id', 'wallet_pending_id', 'wallet_entry_id'];

    protected function casts(): array
    {
        return ['order_amount_minor' => 'integer', 'commission_minor' => 'integer', 'reward_amount' => 'integer',
            'transaction_at' => 'immutable_datetime', 'network_updated_at' => 'immutable_datetime', 'last_synced_at' => 'immutable_datetime'];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }
}
