<?php

namespace App\Http\Resources;

use App\Models\WebshopOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An Order Sheet row. Customer details are visible to members with
 * orders.view of the order's own webshop only.
 *
 * @mixin WebshopOrder
 */
class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var WebshopOrder $o */
        $o = $this->resource;

        return [
            'id' => $o->public_id,
            'reference' => $o->reference,
            'external_order_id' => $o->external_order_id,
            'platform' => $o->platform,
            'customer' => ['first_name' => $o->customer_first_name, 'email' => $o->customer_email, 'phone' => $o->customer_phone],
            'amount_minor' => $o->amount_minor,
            'refunded_minor' => $o->refunded_minor,
            'currency' => $o->currency,
            'payment_status' => $o->payment_status->value,
            'provider_status' => $o->provider_status,
            'ingestion_status' => $o->ingestion_status,
            'missing_fields' => $o->missing_fields ?? [],
            'callable' => $o->has_phone,
            'eligibility_status' => $o->eligibility_status,
            'eligibility_reasons' => $o->eligibility_reasons ?? [],
            'contact_status' => $o->contact_status,
            'placed_at' => $o->placed_at?->toIso8601String(),
            'paid_at' => $o->paid_at?->toIso8601String(),
            'cancelled_at' => $o->cancelled_at?->toIso8601String(),
            'refunded_at' => $o->refunded_at?->toIso8601String(),
            'updated_at' => $o->updated_at?->toIso8601String(),
        ];
    }
}
