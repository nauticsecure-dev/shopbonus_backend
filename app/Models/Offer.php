<?php

namespace App\Models;

use App\Enums\OfferAcquisition;
use App\Enums\OfferStatus;
use App\Enums\VoucherCodeStatus;
use App\Enums\VoucherStatus;
use App\Services\Discovery\DiscoveryCache;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $public_id
 * @property string|null $slug
 * @property int $webshop_id
 * @property OfferAcquisition $acquisition
 * @property OfferStatus $status
 * @property int|null $current_version_id
 * @property CarbonImmutable|null $activated_at
 * @property CarbonImmutable|null $paused_at
 * @property CarbonImmutable|null $ended_at
 * @property CarbonImmutable|null $archived_at
 * @property int $created_by
 * @property CarbonImmutable $created_at
 * @property-read OfferVersion|null $currentVersion
 */
class Offer extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected $hidden = ['id', 'webshop_id', 'current_version_id', 'created_by'];

    protected static function booted(): void
    {
        static::saved(fn () => DiscoveryCache::flush());
    }

    /** Readable, stable public slug: name plus a short random suffix (Trello #18). */
    public static function makeSlug(string $name): string
    {
        return (Str::limit(Str::slug($name), 60, '') ?: 'offer').'-'.Str::lower(Str::random(6));
    }

    protected function casts(): array
    {
        return [
            'acquisition' => OfferAcquisition::class,
            'status' => OfferStatus::class,
            'activated_at' => 'immutable_datetime',
            'paused_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'archived_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /** @return BelongsTo<OfferVersion, $this> */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(OfferVersion::class, 'current_version_id');
    }

    /** @return HasMany<OfferVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(OfferVersion::class);
    }

    /** @return BelongsTo<Webshop, $this> */
    public function webshop(): BelongsTo
    {
        return $this->belongsTo(Webshop::class);
    }

    /** @return HasMany<VoucherCode, $this> */
    public function codes(): HasMany
    {
        return $this->hasMany(VoucherCode::class);
    }

    /** @return HasMany<Voucher, $this> */
    public function vouchers(): HasMany
    {
        return $this->hasMany(Voucher::class);
    }

    /** @param  Builder<Offer>  $query */
    public function scopeWithInventoryCounts(Builder $query): void
    {
        $query->withCount(self::inventoryCountRelations());
    }

    /**
     * Display order: archived, expired, scheduled, sold_out, active, paused, draft.
     */
    public function lifecycleStatus(): string
    {
        $version = $this->currentVersion;
        $endedByDate = $version?->ends_at !== null && $version->ends_at->isPast();

        return match (true) {
            $this->status === OfferStatus::Archived => 'archived',
            $this->status === OfferStatus::Ended, $endedByDate => 'expired',
            $this->status === OfferStatus::Scheduled => 'scheduled',
            $this->status === OfferStatus::Draft => 'draft',
            $this->status === OfferStatus::Paused => 'paused',
            $this->status === OfferStatus::Active && $version?->starts_at !== null && $version->starts_at->isFuture() => 'scheduled',
            $this->status === OfferStatus::Active && $this->isSoldOut() => 'sold_out',
            default => $this->status->publicValue(),
        };
    }

    /** @return list<string> */
    public function allowedActions(): array
    {
        $actions = [];
        if (! in_array($this->status, [OfferStatus::Ended, OfferStatus::Archived], true)) {
            $actions[] = 'edit';
            $actions[] = 'import_codes';
        }
        if ($this->status === OfferStatus::Draft) {
            $actions[] = 'publish';
        }
        if ($this->status->canBecome(OfferStatus::Active)) {
            $actions[] = $this->status === OfferStatus::Paused ? 'resume' : 'activate';
        }
        if ($this->status->canBecome(OfferStatus::Paused)) {
            $actions[] = 'pause';
        }
        if ($this->status->canBecome(OfferStatus::Ended)) {
            $actions[] = 'end';
        }
        if ($this->status->canBecome(OfferStatus::Archived)) {
            $actions[] = 'archive';
        }
        $actions[] = 'duplicate';

        return $actions;
    }

    /** @return array{total: int, available: int, reserved: int, issued: int, redeemed: int} */
    public function inventoryCounts(): array
    {
        $this->ensureInventoryCounts();

        return [
            'total' => (int) ($this->optionalCount('codes_count') ?? 0),
            'available' => (int) ($this->optionalCount('available_codes_count') ?? 0),
            'reserved' => (int) ($this->optionalCount('reserved_codes_count') ?? 0),
            'issued' => (int) ($this->optionalCount('issued_codes_count') ?? 0),
            'redeemed' => (int) ($this->optionalCount('redeemed_codes_count') ?? 0),
        ];
    }

    /**
     * @param  Builder<Offer>  $query
     */
    public function scopeLifecycle(Builder $query, string $lifecycle): void
    {
        $now = now();
        $notEnded = function (Builder $v) use ($now): void {
            $v->where(fn (Builder $w) => $w->whereNull('ends_at')->orWhere('ends_at', '>', $now));
        };
        $started = function (Builder $v) use ($now): void {
            $v->where(fn (Builder $w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', $now));
        };
        $inWindow = function (Builder $v) use ($notEnded, $started): void {
            $started($v);
            $notEnded($v);
        };

        match ($lifecycle) {
            'archived' => $query->where('status', OfferStatus::Archived),
            'draft' => $query->where('status', OfferStatus::Draft),
            'paused' => $query->where('status', OfferStatus::Paused)->whereHas('currentVersion', $notEnded),
            'expired' => $query->where(function (Builder $q) use ($now) {
                $q->where('status', OfferStatus::Ended)
                    ->orWhere(fn (Builder $q) => $q->whereIn('status', [OfferStatus::Active->value, OfferStatus::Paused->value, OfferStatus::Scheduled->value])
                        ->whereHas('currentVersion', fn (Builder $v) => $v->whereNotNull('ends_at')->where('ends_at', '<=', $now)));
            }),
            'scheduled' => $query->where(function (Builder $q) use ($now, $notEnded) {
                $q->where(fn (Builder $q) => $q->where('status', OfferStatus::Scheduled)->whereHas('currentVersion', $notEnded))
                    ->orWhere(fn (Builder $q) => $q->where('status', OfferStatus::Active)->whereHas('currentVersion', function (Builder $v) use ($now, $notEnded) {
                        $notEnded($v);
                        $v->where('starts_at', '>', $now);
                    }));
            }),
            'sold_out' => $query->where('status', OfferStatus::Active)->whereHas('currentVersion', $inWindow)
                ->whereDoesntHave('codes', fn (Builder $c) => $c->where('status', VoucherCodeStatus::Available)),
            'active' => $query->where('status', OfferStatus::Active)->whereHas('currentVersion', $inWindow)
                ->whereHas('codes', fn (Builder $c) => $c->where('status', VoucherCodeStatus::Available)),
            default => $query,
        };
    }

    /** @return array<int|string, mixed> */
    private static function inventoryCountRelations(): array
    {
        return [
            'codes',
            'codes as available_codes_count' => fn (Builder $q) => $q->where('status', VoucherCodeStatus::Available),
            'codes as reserved_codes_count' => fn (Builder $q) => $q->where('status', VoucherCodeStatus::Reserved),
            'codes as issued_codes_count' => fn (Builder $q) => $q->where('status', VoucherCodeStatus::Issued),
            'codes as redeemed_codes_count' => fn (Builder $q) => $q->where('status', VoucherCodeStatus::Redeemed),
            'vouchers as allocated_vouchers_count' => fn (Builder $q) => $q->where('status', '!=', VoucherStatus::Cancelled),
        ];
    }

    private function ensureInventoryCounts(): void
    {
        if ($this->optionalCount('codes_count') !== null && $this->optionalCount('available_codes_count') !== null) {
            return;
        }

        $this->loadCount(self::inventoryCountRelations());
    }

    private function optionalCount(string $key): ?int
    {
        if (! array_key_exists($key, $this->getAttributes())) {
            return null;
        }

        return (int) $this->getAttribute($key);
    }

    private function isSoldOut(): bool
    {
        $version = $this->currentVersion;
        if ($version?->allocation_limit !== null) {
            $used = $this->optionalCount('allocated_vouchers_count');
            if ($used === null) {
                $used = $this->vouchers()->where('status', '!=', VoucherStatus::Cancelled->value)->count();
            }
            if ($used >= $version->allocation_limit) {
                return true;
            }
        }

        $available = $this->optionalCount('available_codes_count');
        if ($available === null) {
            $available = $this->codes()->where('status', VoucherCodeStatus::Available)->count();
        }

        return $available === 0;
    }
}
