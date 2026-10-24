<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * The webshop's ShopBonus embed: one public key and the origins allowed to
 * use it. Not a secret; the origin allowlist is the control.
 *
 * @property int $id
 * @property int $webshop_id
 * @property string $public_key
 * @property list<string> $allowed_origins
 * @property string|null $last_seen_origin
 * @property CarbonImmutable|null $last_seen_at
 */
class BannerInstallation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['allowed_origins' => 'array', 'last_seen_at' => 'immutable_datetime'];
    }

    public function allows(?string $origin): bool
    {
        return $origin !== null && in_array(rtrim(strtolower($origin), '/'), $this->allowed_origins, true);
    }
}
