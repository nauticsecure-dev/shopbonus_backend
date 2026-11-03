<?php
final class ShopbonusOpenCartOrder
{
    public static function payload(array $order, string $timezone): array
    {
        $amount = null;
        // OpenCart stores base totals and a currency conversion multiplier. Never relabel a converted amount.
        if (preg_match('/\A1(?:\.0+)?\z/D', (string) ($order['currency_value'] ?? ''))
            && in_array($order['currency_code'] ?? null, ['EUR', 'USD', 'GBP'], true)
            && preg_match('/\A([0-9]{1,12})\.([0-9]{2})(?:0*)\z/D', (string) ($order['total'] ?? ''), $matches)) {
            $amount = (int) $matches[1] * 100 + (int) $matches[2];
        }

        return ['order' => ['id' => (string) $order['order_id'], 'reference' => (string) $order['order_id'],
            'customer' => ['first_name' => $order['firstname'] ?? null, 'email' => $order['email'] ?? null, 'phone' => $order['telephone'] ?? null],
            'currency' => $amount === null ? null : $order['currency_code'], 'total_minor' => $amount,
            'status' => (string) ($order['order_status_id'] ?? ''), 'payment_status' => 'unknown',
            'created_at' => self::date($order['date_added'] ?? '', $timezone), 'updated_at' => self::date($order['date_modified'] ?? '', $timezone)]];
    }

    private static function date(string $value, string $timezone): ?string
    {
        if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone($timezone));
        if (!$date || $date->format('Y-m-d H:i:s') !== $value) {
            return null;
        }
        $wall = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'))->getTimestamp();
        $changes = (new DateTimeZone($timezone))->getTransitions($date->getTimestamp() - 86400, $date->getTimestamp() + 86400);
        for ($index = 1; $index < count($changes); $index++) {
            if ($changes[$index]['offset'] < $changes[$index - 1]['offset'] && $wall >= $changes[$index]['ts'] + $changes[$index]['offset'] && $wall < $changes[$index]['ts'] + $changes[$index - 1]['offset']) {
                return null;
            }
        }

        return $date->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
    }
}
