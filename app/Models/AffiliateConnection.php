<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Platform-level account at an affiliate network (Trello #19). Credentials
 * are encrypted and never returned by the API.
 *
 * @property int $id
 * @property string $public_id
 * @property string $network
 * @property string $name
 * @property string $status
 * @property array<string, string> $credentials
 * @property string $postback_token_hash
 * @property CarbonImmutable|null $last_synced_at
 * @property string|null $last_error
 */
class AffiliateConnection extends Model
{
    use HasUlids;

    protected $table = 'affiliate_network_connections';

    protected $guarded = ['id'];

    protected $hidden = ['id', 'credentials', 'postback_token_hash'];

    protected function casts(): array
    {
        return ['credentials' => 'encrypted:array', 'last_synced_at' => 'immutable_datetime'];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public static function hashToken(string $token): string
    {
        return hash_hmac('sha256', 'affiliate-postback|'.$token, (string) config('app.key'));
    }
}
