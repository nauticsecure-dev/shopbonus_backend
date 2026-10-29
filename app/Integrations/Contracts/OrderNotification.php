<?php

namespace App\Integrations\Contracts;

use App\Models\WebshopConnection;

interface OrderNotification
{
    /** Extract only an ID from the already authenticated raw notification. */
    public function notificationOrderId(WebshopConnection $connection, string $raw): ?string;
}
