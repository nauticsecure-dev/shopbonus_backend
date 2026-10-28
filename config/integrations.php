<?php

use App\Integrations\Connectors\GenericSignedWebhookConnector;
use App\Integrations\Connectors\ShopifyConnector;
use App\Integrations\Connectors\WooCommerceConnector;

/*
|--------------------------------------------------------------------------
| Ecommerce platform capability matrix (Trello #1 "Supported platforms")
|--------------------------------------------------------------------------
|
| `connector` null = no adapter yet: the platform is listed but cannot be
| connected (never advertised as available). `verification` records what
| has actually been tested against a real store or sandbox: every entry is
| `unverified` until someone with provider access runs the checks in
| docs/integrations/VERIFICATION.md and updates this file with evidence.
|
*/

return [

    'webhook_retention_days' => (int) env('WEBHOOK_PAYLOAD_RETENTION_DAYS', 14),

    'platforms' => [
        'woocommerce' => [
            'profile' => [
                'api_version' => 'wc/v3', 'reviewed_at' => '2026-10-28', 'last_live_verified_at' => null,
                'native_read_api_implemented' => true, 'auth_method' => 'HTTPS HTTP Basic consumer key/secret; read-only merchant key',
                'scopes' => ['read'], 'webhook_topics' => ['order.created', 'order.updated'],
                'order_api' => 'GET /wp-json/wc/v3/orders/{id}', 'refund_api' => 'GET /wp-json/wc/v3/orders/{id}/refunds',
                'product_api' => 'GET /wp-json/wc/v3/products', 'coupon_api' => 'GET /wp-json/wc/v3/coupons',
                'banner_method' => 'manual; native WordPress publication/module not implemented',
                'rate_limit_policy' => 'No redirects/retries;8second reads,1MiB body,100rows/page,5order pages;429cooldown/3failure circuit.',
                'sources' => ['https://developer.woocommerce.com/docs/apis/rest-api/', 'https://developer.woocommerce.com/docs/apis/rest-api/v3/orders/',
                    'https://developer.woocommerce.com/docs/apis/rest-api/v3/order-refunds/', 'https://developer.woocommerce.com/docs/best-practices/urls-and-routing/webhooks/'],
            ],
            'name' => 'WooCommerce', 'priority' => 'production',
            'connector' => WooCommerceConnector::class,
            'method' => 'webhook', 'auth' => 'shared secret (HMAC-SHA256, base64)',
            'order_source' => 'webhook payload (REST v3 order)', 'refunds' => 'order.updated status/refunds',
            'reliability' => 'high', 'limitations' => [], 'verification' => 'unverified',
        ],
        'shopify' => [
            'profile' => [
                'api_version' => '2026-10', 'reviewed_at' => '2026-10-28', 'last_live_verified_at' => null,
                'native_read_api_implemented' => false, 'auth_method' => 'Signing secret only in current implementation; OAuth/GraphQL not yet implemented',
                'scopes' => [], 'webhook_topics' => ['orders/create', 'orders/updated', 'orders/paid', 'orders/cancelled', 'refunds/create'],
                'banner_method' => 'Theme App Extension requires separate implementation and merchant activation',
                'sources' => ['https://shopify.dev/docs/api/admin-graphql/2026-10', 'https://shopify.dev/docs/apps/build/webhooks'],
            ],
            'name' => 'Shopify', 'priority' => 'production',
            'connector' => ShopifyConnector::class,
            'method' => 'webhook', 'auth' => 'webhook signing secret (HMAC-SHA256, base64)',
            'order_source' => 'webhook payload', 'refunds' => 'refunds/create, orders/updated',
            'reliability' => 'high', 'limitations' => ['customer fields may be redacted without protected customer data access'],
            'verification' => 'unverified',
        ],
        'wix' => [
            'name' => 'Wix', 'priority' => 'production', 'connector' => null,
            'method' => 'automation HTTP POST', 'auth' => 'to be determined (verify whether automations can sign or authenticate requests)',
            'reliability' => 'unknown', 'limitations' => [], 'verification' => 'unverified',
            'blocked_reason' => 'Request authentication for Wix automations not yet verified.',
        ],
        'bigcommerce' => [
            'name' => 'BigCommerce', 'priority' => 'production', 'connector' => null,
            'method' => 'webhook + API lookup', 'auth' => 'restricted API token', 'reliability' => 'unknown',
            'limitations' => [], 'verification' => 'unverified',
            'blocked_reason' => 'Requires API credentials and order lookup (webhooks carry only IDs).',
        ],
        'ecwid' => [
            'name' => 'Ecwid', 'priority' => 'production', 'connector' => null,
            'method' => 'webhook/polling + API', 'auth' => 'store API token', 'reliability' => 'unknown',
            'limitations' => [], 'verification' => 'unverified', 'blocked_reason' => 'Requires store API token and app registration.',
        ],
        'lightspeed_c' => [
            'name' => 'Lightspeed C-Series', 'priority' => 'production', 'connector' => null,
            'method' => 'confirmation tracking where supported', 'auth' => 'to be determined', 'reliability' => 'lower',
            'limitations' => ['lower reliability'], 'verification' => 'unverified', 'blocked_reason' => 'Supported mechanism not yet verified.',
        ],
        'prestashop' => [
            'name' => 'PrestaShop', 'priority' => 'additional',
            'connector' => GenericSignedWebhookConnector::class,
            'method' => 'private_module', 'auth' => 'Shopbonus signed webhook v1', 'order_source' => 'module payload',
            'reliability' => 'high (module not yet built)', 'limitations' => ['private ZIP module must be installed'],
            'verification' => 'unverified',
        ],
        'opencart' => [
            'name' => 'OpenCart', 'priority' => 'additional',
            'connector' => GenericSignedWebhookConnector::class,
            'method' => 'private_module', 'auth' => 'Shopbonus signed webhook v1', 'order_source' => 'module payload',
            'reliability' => 'high (module not yet built)', 'limitations' => ['private OCMOD extension must be installed'],
            'verification' => 'unverified',
        ],
        'shopware6' => [
            'name' => 'Shopware 6', 'priority' => 'additional',
            'connector' => GenericSignedWebhookConnector::class,
            'method' => 'private_module', 'auth' => 'Shopbonus signed webhook v1', 'order_source' => 'extension payload',
            'reliability' => 'high (extension not yet built)', 'limitations' => ['private extension must be installed'],
            'verification' => 'unverified',
        ],
        'magento' => [
            'name' => 'Magento / Adobe Commerce', 'priority' => 'additional',
            'connector' => GenericSignedWebhookConnector::class,
            'method' => 'private_module', 'auth' => 'Shopbonus signed webhook v1', 'order_source' => 'module payload',
            'reliability' => 'high (module not yet built)', 'limitations' => ['private module must be installed'],
            'verification' => 'unverified',
        ],
        'lightspeed_e' => [
            'name' => 'Lightspeed E-Series', 'priority' => 'additional', 'connector' => null,
            'method' => 'API/confirmation (to be determined)', 'auth' => 'to be determined', 'reliability' => 'unknown',
            'limitations' => [], 'verification' => 'unverified', 'blocked_reason' => 'Supported API method not yet verified.',
        ],
        'squarespace' => [
            'name' => 'Squarespace', 'priority' => 'additional', 'connector' => null,
            'method' => 'polling (read-only Orders API)', 'auth' => 'API key', 'reliability' => 'polling delay',
            'limitations' => ['polling'], 'verification' => 'unverified', 'blocked_reason' => 'Polling adapter not built; requires API key.',
        ],
        'mijnwebwinkel' => [
            'name' => 'Mijnwebwinkel', 'priority' => 'additional', 'connector' => null,
            'method' => 'partner API', 'auth' => 'partner authorisation', 'reliability' => 'unknown',
            'limitations' => [], 'verification' => 'unverified', 'blocked_reason' => 'Partner/API authorisation pending.',
        ],
        'ccv' => [
            'name' => 'CCV Shop', 'priority' => 'additional', 'connector' => null,
            'method' => 'to be investigated', 'auth' => 'to be determined', 'reliability' => 'unknown',
            'limitations' => [], 'verification' => 'unverified', 'blocked_reason' => 'Merchant-accessible API options not yet investigated.',
        ],
        'other' => [
            'name' => 'Other / custom shop', 'priority' => 'additional',
            'connector' => GenericSignedWebhookConnector::class,
            'method' => 'webhook', 'auth' => 'Shopbonus signed webhook v1', 'order_source' => 'custom integration',
            'reliability' => 'depends on the custom integration', 'limitations' => [], 'verification' => 'unverified',
        ],
    ],

];
