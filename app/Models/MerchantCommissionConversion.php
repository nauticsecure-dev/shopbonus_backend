<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Verified direct-merchant conversion that produced (or attempted) a wallet credit.
 *
 * @property int $id
 * @property string $public_id
 * @property int $webshop_id
 * @property int $user_id
 * @property int $agreement_id
 * @property string $external_id
 * @property string $status
 * @property int $commission_minor
 * @property int $consumer_credit_minor
 * @property int $shopbonus_revenue_minor
 */
class MerchantCommissionConversion extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected $hidden = ['id', 'webshop_id', 'user_id', 'agreement_id', 'wallet_entry_id'];

    protected function casts(): array
    {
        return [
            'order_amount_minor' => 'integer',
            'commission_minor' => 'integer',
            'consumer_credit_minor' => 'integer',
            'shopbonus_revenue_minor' => 'integer',
            'agreement_snapshot' => 'array',
            'converted_at' => 'immutable_datetime',
        ];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /** @return BelongsTo<Webshop, $this> */
    public function webshop(): BelongsTo
    {
        return $this->belongsTo(Webshop::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<MerchantCommissionAgreement, $this> */
    public function agreement(): BelongsTo
    {
        return $this->belongsTo(MerchantCommissionAgreement::class, 'agreement_id');
    }
}
