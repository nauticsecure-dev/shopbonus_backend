<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Frozen encrypted intent; codes are never exposed through command output or audit metadata.
 *
 * @property int $id
 * @property string $public_id
 * @property int $offer_id
 * @property int $connection_id
 * @property int $offer_version_id
 * @property int $credential_generation
 * @property string $verified_domain
 * @property string $code
 * @property array<string,mixed> $input
 * @property string $state
 * @property string|null $remote_id
 * @property int|null $voucher_code_id
 */
class ShopifyCouponOperation extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected $hidden = ['code', 'input'];

    protected static function booted(): void
    {
        static::updating(function (self $operation): void {
            if ($operation->isDirty(['public_id', 'offer_id', 'connection_id', 'offer_version_id', 'credential_generation', 'verified_domain', 'request_key', 'code', 'input', 'created_by'])
                || ($operation->getOriginal('remote_id') !== null && $operation->isDirty('remote_id'))
                || ($operation->getOriginal('voucher_code_id') !== null && $operation->isDirty('voucher_code_id'))) {
                throw new \LogicException('Shopify coupon intent snapshots and bound references are immutable.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Shopify coupon operation history cannot be deleted.'));
    }

    protected function casts(): array
    {
        return ['code' => 'encrypted', 'input' => 'encrypted:array', 'credential_generation' => 'integer'];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }
}
