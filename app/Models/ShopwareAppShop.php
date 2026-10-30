<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A Shopware shop that ran the app registration handshake. `shop_secret` signs the shop's requests
 * once confirmed; `pending_*` hold an unconfirmed (re-)registration and `previous_shop_secret` keeps
 * the rotated secret for the in-flight allowance.
 *
 * @property int $id
 * @property string $public_id
 * @property string $shop_id
 * @property string|null $shop_url
 * @property string|null $pending_shop_url
 * @property string|null $shop_secret
 * @property string|null $pending_shop_secret
 * @property string|null $previous_shop_secret
 * @property CarbonImmutable|null $secrets_rotated_at
 * @property array{api_key:string,secret_key:string}|null $api_credentials
 * @property CarbonImmutable|null $confirmed_at
 * @property int|null $connection_id
 */
class ShopwareAppShop extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected $hidden = ['id', 'shop_secret', 'pending_shop_secret', 'previous_shop_secret', 'api_credentials', 'connection_id'];

    protected function casts(): array
    {
        return [
            'shop_secret' => 'encrypted',
            'pending_shop_secret' => 'encrypted',
            'previous_shop_secret' => 'encrypted',
            'api_credentials' => 'encrypted:array',
            'secrets_rotated_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
        ];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }
}
