<?php

namespace App\Models;

use App\Enums\WebhookEventStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A verified inbound provider event. Unverified requests are never stored.
 *
 * @property int|null $credential_generation
 * @property int $id
 * @property string $public_id
 * @property int $connection_id
 * @property int $webshop_id
 * @property string $dedupe_key
 * @property string|null $topic
 * @property WebhookEventStatus $status
 * @property int $delivery_count
 * @property string|null $payload
 * @property string $payload_hash
 * @property string|null $error_code
 * @property array<string, mixed>|null $result
 * @property CarbonImmutable $received_at
 * @property CarbonImmutable|null $processed_at
 * @property CarbonImmutable|null $payload_pruned_at
 * @property-read WebshopConnection $connection
 */
class WebhookEvent extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected $hidden = ['credential_generation', 'id', 'connection_id', 'webshop_id', 'payload'];

    protected function casts(): array
    {
        return [
            'credential_generation' => 'integer',
            'status' => WebhookEventStatus::class,
            'payload' => 'encrypted',
            'result' => 'array',
            'received_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
            'payload_pruned_at' => 'immutable_datetime',
        ];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /** @return BelongsTo<WebshopConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(WebshopConnection::class, 'connection_id');
    }
}
