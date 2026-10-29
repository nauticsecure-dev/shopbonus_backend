<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A signed Shopify privacy request (customers/data_request, customers/redact, shop/redact).
 * Recording it never deletes anything; an operator resolves it within the due date (D-099).
 *
 * @property int $id
 * @property string $public_id
 * @property string $webhook_id
 * @property string|null $event_id
 * @property string $topic
 * @property string|null $shop_domain
 * @property int|null $shop_id
 * @property bool $payload_valid
 * @property string $payload
 * @property string $payload_hash
 * @property string $status
 * @property int $delivery_count
 * @property CarbonImmutable $received_at
 * @property CarbonImmutable $due_at
 * @property CarbonImmutable|null $resolved_at
 */
class ShopifyComplianceRequest extends Model
{
    use HasUlids;

    public const TOPICS = ['customers/data_request', 'customers/redact', 'shop/redact'];

    /** Operator outcomes; `retained` means a legal retention duty prevented erasure. */
    public const OUTCOMES = ['completed', 'retained'];

    protected $guarded = ['id'];

    protected $hidden = ['id', 'payload'];

    protected function casts(): array
    {
        return [
            'payload' => 'encrypted',
            'payload_valid' => 'boolean',
            'received_at' => 'immutable_datetime',
            'due_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }
}
