<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Cross-domain editor handshake (Trello #25 §3): a single-use pairing token,
 * exchanged from the shop's origin for a short-lived editor token that can
 * only read and save banner drafts of that webshop. Only HMACs are stored.
 *
 * @property int $id
 * @property string $token_hash
 * @property int $webshop_id
 * @property int $user_id
 * @property string $origin
 * @property string $kind
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $used_at
 */
class BannerPairing extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'used_at' => 'immutable_datetime'];
    }

    public static function hashToken(string $token): string
    {
        return hash_hmac('sha256', 'banner-pairing|'.$token, (string) config('app.key'));
    }
}
