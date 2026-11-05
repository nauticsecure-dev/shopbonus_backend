<?php

namespace Shopbonus\AsyncBridge\Model;

/** The only outbound data is the exact native store scope and order ID. */
class Notification
{
    public static function payload(string $store, string $code, string $order): array
    {
        foreach ([$store, $order] as $id) {
            if (! preg_match('/\A[1-9][0-9]{0,18}\z/D', $id)) {
                throw new \InvalidArgumentException('Invalid native ID.');
            }
        }
        if (! preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $code)) {
            throw new \InvalidArgumentException('Invalid native store code.');
        }

        return ['shopbonus_magento_lookup_v1' => true, 'store_id' => $store, 'store_code' => $code, 'order_id' => $order];
    }

    public static function delay(int $attempt): int
    {
        return min(3600, 30 * (2 ** min(7, max(0, $attempt))));
    }
}
