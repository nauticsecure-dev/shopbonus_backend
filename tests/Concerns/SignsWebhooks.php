<?php

namespace Tests\Concerns;

/**
 * Builds provider-format webhook requests for tests.
 */
trait SignsWebhooks
{
    /** @return array<string, mixed> */
    protected function wooOrder(array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => 4711, 'number' => '4711', 'status' => 'processing', 'currency' => 'EUR', 'total' => '59.95',
            'date_created_gmt' => '2026-10-23T10:00:00', 'date_modified_gmt' => '2026-10-23T10:01:00', 'date_paid_gmt' => '2026-10-23T10:01:00',
            'billing' => ['first_name' => 'Anna', 'last_name' => 'Bakker', 'email' => 'anna@example.nl', 'phone' => '+31612345678'],
            'refunds' => [],
        ], $overrides);
    }

    /** @return array<string, string> */
    protected function wooHeaders(string $body, string $secret, string $delivery = 'd-1', ?string $topic = 'order.created'): array
    {
        return array_filter([
            'X-WC-Webhook-Signature' => base64_encode(hash_hmac('sha256', $body, $secret, true)),
            'X-WC-Webhook-Topic' => $topic,
            'X-WC-Webhook-Delivery-ID' => $delivery,
            'X-WC-Webhook-Source' => 'https://fietsen-utrecht.nl/',
            'Content-Type' => 'application/json',
        ]);
    }

    /** @return array<string, mixed> */
    protected function shopifyOrder(array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => 820982911946154508, 'name' => '#1001', 'email' => 'anna@example.nl', 'phone' => null,
            'total_price' => '59.95', 'currency' => 'EUR', 'financial_status' => 'paid', 'cancelled_at' => null,
            'created_at' => '2026-10-23T12:00:00+02:00', 'processed_at' => '2026-10-23T12:00:05+02:00', 'updated_at' => '2026-10-23T12:00:05+02:00',
            'customer' => ['first_name' => 'Anna', 'email' => 'anna@example.nl', 'phone' => '+31612345678'],
        ], $overrides);
    }

    /** @return array<string, string> */
    protected function shopifyHeaders(string $body, string $secret, string $id = 'w-1', string $topic = 'orders/paid', string $shop = 'fietsen.myshopify.com'): array
    {
        return [
            'X-Shopify-Hmac-Sha256' => base64_encode(hash_hmac('sha256', $body, $secret, true)),
            'X-Shopify-Topic' => $topic, 'X-Shopify-Webhook-Id' => $id, 'X-Shopify-Shop-Domain' => $shop,
            'Content-Type' => 'application/json',
        ];
    }

    /** @return array<string, mixed> */
    protected function genericOrder(array $overrides = []): array
    {
        return array_replace_recursive(['order' => [
            'id' => 'PS-1001', 'reference' => 'XKBKNABJK', 'currency' => 'EUR', 'total_minor' => 5995,
            'customer' => ['first_name' => 'Anna', 'email' => 'anna@example.nl', 'phone' => '+31612345678'],
            'payment_status' => 'paid', 'status' => 'payment_accepted',
            'created_at' => '2026-10-23T10:00:00Z', 'paid_at' => '2026-10-23T10:01:00Z',
        ]], $overrides);
    }

    /** @return array<string, string> */
    protected function genericHeaders(string $body, string $secret, string $eventId = 'e-1', string $topic = 'order.paid', ?int $t = null): array
    {
        $t ??= time();

        return [
            'X-Shopbonus-Signature' => "t={$t},v1=".hash_hmac('sha256', $t.'.'.$body, $secret),
            'X-Shopbonus-Event-Id' => $eventId, 'X-Shopbonus-Topic' => $topic, 'Content-Type' => 'application/json',
        ];
    }
}
