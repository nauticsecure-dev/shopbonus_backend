<?php

namespace App\Models;

use App\Enums\ConnectionMethod;
use App\Enums\ConnectionStatus;
use App\Enums\EcommercePlatform;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $credential_generation
 * @property int $id
 * @property string $public_id
 * @property int $webshop_id
 * @property EcommercePlatform $platform
 * @property ConnectionMethod $method
 * @property ConnectionStatus $status
 * @property string $webhook_token
 * @property array<string, string>|null $credentials
 * @property string|null $external_shop_ref
 * @property list<string>|null $limitations
 * @property CarbonImmutable|null $last_event_at
 * @property CarbonImmutable|null $last_verified_order_at
 * @property CarbonImmutable|null $connected_at
 * @property CarbonImmutable|null $disconnected_at
 * @property string|null $last_error_code
 * @property CarbonImmutable|null $last_error_at
 * @property int $created_by
 * @property CarbonImmutable $created_at
 * @property-read Webshop $webshop
 */
class WebshopConnection extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected $hidden = ['credential_generation', 'id', 'webshop_id', 'credentials', 'webhook_token', 'created_by'];

    protected $attributes = ['credential_generation' => 1, 'limitations' => null, 'last_error_code' => null];

    protected function casts(): array
    {
        return [
            'credential_generation' => 'integer',
            'platform' => EcommercePlatform::class,
            'method' => ConnectionMethod::class,
            'status' => ConnectionStatus::class,
            'credentials' => 'encrypted:array',
            'limitations' => 'array',
            'last_event_at' => 'immutable_datetime',
            'last_verified_order_at' => 'immutable_datetime',
            'connected_at' => 'immutable_datetime',
            'disconnected_at' => 'immutable_datetime',
            'last_error_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function credential(string $key): ?string
    {
        $value = $this->credentials[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return BelongsTo<Webshop, $this> */
    public function webshop(): BelongsTo
    {
        return $this->belongsTo(Webshop::class);
    }

    /** @return HasMany<WebhookEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(WebhookEvent::class, 'connection_id');
    }
}
