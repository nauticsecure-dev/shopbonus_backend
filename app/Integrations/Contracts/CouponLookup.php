<?php

namespace App\Integrations\Contracts;

use App\Models\WebshopConnection;

/** Live read only. A native configuration observation never proves checkout acceptance or redemption. */
interface CouponLookup
{
    /** @return array{coupon:array<string,mixed>|null,currency:string} */
    public function lookupCoupon(WebshopConnection $connection, string $code): array;
}
