<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Single-use claim link for a reserved voucher (Trello #23 "Answer24 free
 * voucher"). Looked up by an HMAC of the token; the encrypted token is kept
 * only so a repeated handoff returns the same link.
 *
 * Status: open → claimed | expired | cancelled.
 *
 * @property int $id
 * @property string $public_id
 * @property int $webshop_id
 * @property int $voucher_id
 * @property int|null $opportunity_id
 * @property string $token_hash
 * @property string $token
 * @property string $status
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $claimed_at
 * @property int|null $claimed_by
 * @property CarbonImmutable $created_at
 * @property-read Voucher $voucher
 * @property-read Webshop $webshop
 * @property-read ContactOpportunity|null $opportunity
 */
class VoucherClaim extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected $hidden = ['id', 'webshop_id', 'voucher_id', 'opportunity_id', 'token_hash', 'token', 'claimed_by'];

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'expires_at' => 'immutable_datetime',
            'claimed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public static function hashToken(string $token): string
    {
        return hash_hmac('sha256', 'voucher-claim|'.$token, (string) config('app.key'));
    }

    /** @return BelongsTo<Voucher, $this> */
    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    /** @return BelongsTo<Webshop, $this> */
    public function webshop(): BelongsTo
    {
        return $this->belongsTo(Webshop::class);
    }

    /** @return BelongsTo<ContactOpportunity, $this> */
    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(ContactOpportunity::class);
    }
}
