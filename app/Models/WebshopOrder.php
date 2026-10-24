<?php

namespace App\Models;

use App\Integrations\Data\PaymentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A normalised order from a verified provider event. Customer details are
 * encrypted; `customer_email_hash` supports exact search.
 *
 * @property int $id
 * @property string $public_id
 * @property int $webshop_id
 * @property int $connection_id
 * @property string $platform
 * @property string $external_order_id
 * @property string|null $reference
 * @property string|null $customer_first_name
 * @property string|null $customer_email
 * @property string|null $customer_email_hash
 * @property string|null $customer_phone
 * @property bool $has_phone
 * @property bool|null $contact_consent
 * @property string|null $customer_key
 * @property list<string>|null $eligibility_reasons
 * @property int|null $amount_minor
 * @property string|null $currency
 * @property int $refunded_minor
 * @property PaymentStatus $payment_status
 * @property string|null $provider_status
 * @property string $ingestion_status
 * @property list<string>|null $missing_fields
 * @property string $eligibility_status
 * @property string $contact_status
 * @property CarbonImmutable|null $placed_at
 * @property CarbonImmutable|null $paid_at
 * @property CarbonImmutable|null $cancelled_at
 * @property CarbonImmutable|null $refunded_at
 * @property string|null $attribution_click_id
 * @property CarbonImmutable|null $provider_updated_at
 * @property int|null $last_webhook_event_id
 * @property CarbonImmutable $created_at
 */
class WebshopOrder extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected $hidden = ['id', 'webshop_id', 'connection_id', 'customer_email_hash', 'customer_key', 'last_webhook_event_id'];

    protected $attributes = ['refunded_minor' => 0, 'eligibility_status' => 'not_evaluated', 'contact_status' => 'not_evaluated', 'attribution_click_id' => null];

    protected function casts(): array
    {
        return [
            'customer_first_name' => 'encrypted',
            'customer_email' => 'encrypted',
            'customer_phone' => 'encrypted',
            'has_phone' => 'boolean',
            'contact_consent' => 'boolean',
            'eligibility_reasons' => 'array',
            'amount_minor' => 'integer',
            'refunded_minor' => 'integer',
            'payment_status' => PaymentStatus::class,
            'missing_fields' => 'array',
            'placed_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'refunded_at' => 'immutable_datetime',
            'provider_updated_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /**
     * Identifies "the same customer" within one webshop (grouping, recency).
     */
    public static function customerKey(int $webshopId, ?string $email, ?string $phone): ?string
    {
        $subject = $email !== null && trim($email) !== '' ? 'e:'.mb_strtolower(trim($email)) : ($phone !== null ? 'p:'.$phone : null);

        return $subject === null ? null : hash_hmac('sha256', "customer|{$webshopId}|{$subject}", (string) config('app.key'));
    }

    public static function emailHash(?string $email): ?string
    {
        return $email === null || trim($email) === '' ? null
            : hash_hmac('sha256', 'order-email|'.mb_strtolower(trim($email)), (string) config('app.key'));
    }

    /** @return HasMany<OrderEvent, $this> */
    public function history(): HasMany
    {
        return $this->hasMany(OrderEvent::class, 'order_id');
    }

    /** @return BelongsTo<WebshopConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(WebshopConnection::class, 'connection_id');
    }
}
