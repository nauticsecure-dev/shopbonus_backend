<?php

namespace App\Integrations\Connectors;

use App\Enums\ConnectionMethod;
use App\Enums\EcommercePlatform;
use App\Exceptions\ApiException;
use App\Integrations\Contracts\OrderNotification;
use App\Integrations\Contracts\PagedOrderReader;
use App\Integrations\Data\NormalizedOrder;
use App\Integrations\Data\PaymentStatus;
use App\Integrations\Data\VerifiedEvent;
use App\Integrations\Exceptions\InvalidWebhook;
use App\Integrations\Http\PinnedJsonReader;
use App\Integrations\ProviderOrderId;
use App\Integrations\WixAppTokens;
use App\Models\WebshopConnection;
use App\Services\Onboarding\DomainName;
use App\Support\ExactJson;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

final class WixConnector extends WebhookConnector implements OrderNotification, PagedOrderReader
{
    public function platform(): EcommercePlatform
    {
        return EcommercePlatform::Wix;
    }

    public function method(): ConnectionMethod
    {
        return ConnectionMethod::Webhook;
    }

    public function connectRules(): array
    {
        return ['instance_id' => ['required', 'uuid'], 'site_id' => ['required', 'uuid'], 'access_token' => WixAppTokens::enabled() ? ['prohibited'] : ['required', 'string', 'min:16', 'max:4096', 'regex:/\A[A-Za-z0-9_.-]+\z/D'], 'public_key' => ['required', 'string', 'max:8192', function ($attribute, $value, $fail) {
            $key = openssl_pkey_get_public($value);
            $info = $key === false ? null : openssl_pkey_get_details($key);
            if (! is_array($info) || $info['type'] !== OPENSSL_KEYTYPE_RSA || $info['bits'] < 2048) {
                $fail('Use the app webhook RSA public key (2048 bits or more).');
            }
        },
        ]];
    }

    public function connect(WebshopConnection $c, array $input): array
    {
        $c->external_shop_ref = strtolower($input['instance_id']);

        $base = ['site_id' => strtolower($input['site_id']), 'public_key' => $input['public_key']];

        // App mode binds the connection to the configured app; tokens are minted per instance on demand.
        return WixAppTokens::enabled() ? $base + ['wix_app_id' => (string) config('integrations.wix_app.app_id')] : $base + ['access_token' => $input['access_token']];
    }

    public function setupInstructions(WebshopConnection $c, string $url): array
    {
        return ['format' => 'wix-rs256-jwt', 'webhook_url' => $url, 'instance_id' => $c->external_shop_ref, 'steps' => ['Use a merchant-authorized Wix app token with Read Orders, Read Order Transactions and Manage Your App for Get App Instance. Senior developer configures token renewal and the app webhook RSA public key.', 'Subscribe to wix.ecom.v1.order_created and order updated events in the released Wix app. Raw signed JWT notifications trigger authenticated current-order reads only.', 'App instance, site ID and verified site URL must match. Gift-card, delayed capture, missing/ambiguous payment records and chargebacks stay unqualified; no paid date is invented.'], 'live_verified' => false];
    }

    private function base64url(string $v): string
    {
        if (! preg_match('/\A[A-Za-z0-9_-]+\z/D', $v)) {
            throw new InvalidWebhook('invalid_signature');
        }
        $raw = base64_decode(strtr($v, '-_', '+/').str_repeat('=', (4 - strlen($v) % 4) % 4), true);
        if ($raw === false) {
            throw new InvalidWebhook('invalid_signature');
        }

        return $raw;
    }

    /** @return array<string,mixed> */
    private function signedData(WebshopConnection $c, string $raw, bool $checkTime = true): array
    {
        $parts = explode('.', trim($raw));
        if (count($parts) !== 3 || strlen($raw) > self::MAX_BODY_BYTES) {
            throw new InvalidWebhook('invalid_signature');
        }
        try {
            $header = ExactJson::decode($this->base64url($parts[0]));
            $body = ExactJson::decode($this->base64url($parts[1]));
        } catch (\JsonException) {
            throw new InvalidWebhook('invalid_json');
        }
        if (! is_array($header) || ($header['alg'] ?? null) !== 'RS256' || isset($header['crit']) || isset($header['jku']) || isset($header['jwk']) || isset($header['b64']) || ! is_array($body)) {
            throw new InvalidWebhook('invalid_signature');
        }
        $key = openssl_pkey_get_public($this->requireSecret($c, 'public_key'));
        $info = $key === false ? null : openssl_pkey_get_details($key);
        if (! is_array($info) || $info['type'] !== OPENSSL_KEYTYPE_RSA || $info['bits'] < 2048 || openssl_verify($parts[0].'.'.$parts[1], $this->base64url($parts[2]), $key, OPENSSL_ALGO_SHA256) !== 1) {
            throw new InvalidWebhook('invalid_signature');
        }
        foreach (['exp', 'nbf'] as $claim) {
            if (isset($body[$claim]) && (! is_int($body[$claim]) || ($checkTime && ($claim === 'exp' ? $body[$claim] < now()->getTimestamp() - 60 : $body[$claim] > now()->getTimestamp() + 60)))) {
                throw new InvalidWebhook('invalid_timestamp');
            }
        }
        $data = $body['data'] ?? null;
        if (! is_array($data) || ($data['instanceId'] ?? null) !== $c->external_shop_ref || ! is_string($data['data'] ?? null)) {
            throw new InvalidWebhook('store_mismatch');
        }
        try {
            $event = ExactJson::decode($data['data']);
        } catch (\JsonException) {
            throw new InvalidWebhook('invalid_json');
        }
        if (! is_array($event) || ! is_string($event['id'] ?? null) || ! preg_match('/\A[A-Za-z0-9_-]{8,128}\z/D', $event['id'])) {
            throw new InvalidWebhook('invalid_event');
        }

        return ['topic' => $data['eventType'] ?? null, 'event' => $event];
    }

    public function verifyIncomingEvent(WebshopConnection $c, Request $r): VerifiedEvent
    {
        $p = $this->signedData($c, $this->rawBody($r));
        $topic = $p['topic'];
        $e = $p['event'];
        $lookup = ($e['entityFqdn'] ?? null) === 'wix.ecom.v1.order' && in_array($e['slug'] ?? null, ['created', 'updated'], true) && $topic === 'wix.ecom.v1.order_'.$e['slug'];
        if ($lookup) {
            try {
                ProviderOrderId::validate($this->platform(), (string) ($e['entityId'] ?? ''));
            } catch (\InvalidArgumentException) {
                throw new InvalidWebhook('invalid_order_id');
            }
        }

        return new VerifiedEvent($e['id'], is_string($topic) ? $topic : null, $lookup ? VerifiedEvent::KIND_ORDER_LOOKUP : VerifiedEvent::KIND_OTHER);
    }

    public function notificationOrderId(WebshopConnection $c, string $raw): ?string
    {
        try {
            $p = $this->signedData($c, $raw, false);

            return ProviderOrderId::validate($this->platform(), (string) ($p['event']['entityId'] ?? ''));
        } catch (InvalidWebhook|\InvalidArgumentException) {
            return null;
        }
    }

    /** @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    private function read(WebshopConnection $c, string $path, string $method = 'GET', array $body = []): array
    {
        if ($c->credential('site_id') === null) {
            throw ApiException::conflict('Configure read-only provider credentials before testing the API.', 'provider_credentials_missing');
        }

        return app(PinnedJsonReader::class)->read($c, 'https://www.wixapis.com'.$path, ['headers' => ['Authorization' => app(WixAppTokens::class)->accessToken($c), 'wix-site-id' => $c->credential('site_id')]], $method, $body)['data'];
    }

    private function identity(WebshopConnection $c): void
    {
        $p = $this->read($c, '/apps/v1/instance');
        if (($p['instance']['instanceId'] ?? null) !== $c->external_shop_ref || ($p['site']['siteId'] ?? null) !== $c->credential('site_id')) {
            throw ApiException::conflict('The provider store identity does not match this connection.', 'provider_store_mismatch');
        }
        if (! $c->webshop->isOwnershipVerified() || ! is_string($p['site']['url'] ?? null) || DomainName::fromInput($p['site']['url']) !== strtolower((string) $c->webshop->verified_domain)) {
            throw ApiException::conflict('Verify ownership of the exact store domain before using its API.', 'store_identity_unverified');
        }
    }

    public function testConnection(WebshopConnection $c): array
    {
        $this->identity($c);

        return ['ok' => true, 'code' => 'api_shop_identity_observed'];
    }

    public function fetchOrder(WebshopConnection $c, string $id): array
    {
        $id = ProviderOrderId::validate($this->platform(), $id);
        $this->identity($c);
        $p = $this->read($c, '/ecom/v1/orders/'.$id);
        $t = $this->read($c, '/ecom/v1/payments/orders/'.$id);
        if (($p['order']['id'] ?? null) !== $id || ($t['orderTransactions']['orderId'] ?? null) !== $id) {
            throw new ApiException('The provider returned an unexpected order identity or currency.', 502, 'provider_order_invalid');
        }

        return ['order' => $p['order'], 'orderTransactions' => $t['orderTransactions']];
    }

    /** @return array{ids:list<string>,next:?string} */
    public function discoverOrders(WebshopConnection $c, CarbonImmutable $since, CarbonImmutable $until, ?string $cursor): array
    {
        if ($cursor !== null && ($cursor === '' || strlen($cursor) > 2048)) {
            throw new \InvalidArgumentException('Invalid native cursor.');
        }
        $this->identity($c);
        $statuses = ['APPROVED', 'CANCELED', 'PENDING', 'REJECTED'];
        // Wix rejects cursor with filter/sort; the cursor carries the first page's query. Rows are still window/status checked.
        $search = $cursor !== null ? ['cursorPaging' => ['limit' => 25, 'cursor' => $cursor]] : [
            'filter' => ['updatedDate' => ['$gte' => $since->utc()->toIso8601String(), '$lte' => $until->utc()->toIso8601String()], 'status' => ['$in' => $statuses]],
            'sort' => [['fieldName' => 'updatedDate', 'order' => 'ASC']], 'cursorPaging' => ['limit' => 25],
        ];
        $result = $this->read($c, '/ecom/v1/orders/search', 'POST', ['search' => $search]);
        $rows = $result['orders'] ?? null;
        $meta = $result['metadata'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) > 25 || ! is_array($meta) || ($meta['count'] ?? null) !== count($rows)
            || (isset($meta['cursors']) && ! is_array($meta['cursors']))) {
            throw new ApiException('The provider pagination metadata is invalid.', 502, 'provider_pagination_invalid');
        }
        $next = $meta['cursors']['next'] ?? null;
        if ($next !== null && (! is_string($next) || $next === '' || strlen($next) > 2048 || $next === $cursor || $rows === [])) {
            throw new ApiException('The provider pagination metadata is invalid.', 502, 'provider_pagination_invalid');
        }
        $ids = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! in_array($row['status'] ?? null, $statuses, true) || ! is_string($row['updatedDate'] ?? null)
                || ! preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9:.]+(?:Z|[+-][0-9]{2}:[0-9]{2})\z/D', $row['updatedDate'])) {
                throw new ApiException('The provider order data is incomplete.', 502, 'provider_order_invalid');
            }
            $at = $this->date($row['updatedDate']);
            if ($at === null || $at->lessThan($since) || $at->greaterThan($until)) {
                throw new ApiException('The provider returned an order outside the requested update window.', 502, 'provider_order_invalid');
            }
            try {
                $ids[] = ProviderOrderId::validate($this->platform(), (string) ($row['id'] ?? ''));
            } catch (\InvalidArgumentException) {
                throw new ApiException('The provider order data is incomplete.', 502, 'provider_order_invalid');
            }
        }

        return ['ids' => $ids, 'next' => $next];
    }

    public function normalizeOrder(array $payload): NormalizedOrder
    {
        $p = $payload['order'] ?? [];
        $t = $payload['orderTransactions'] ?? [];
        $currency = Money::isCurrency($p['currency'] ?? null) ? $p['currency'] : null;
        $amount = $refund = null;
        $paidAt = $refundAt = null;
        $captured = 0;
        try {
            if ($currency === null) {
                throw new \InvalidArgumentException;
            }
            $amount = Money::toMinor($p['priceSummary']['total']['amount'] ?? '', $currency);
            $refund = 0;
            $seen = [];
            if (! is_array($t['payments'] ?? null) || count($t['payments']) > 100 || ! is_array($t['refunds'] ?? null) || count($t['refunds']) > 100) {
                throw new \InvalidArgumentException;
            }
            foreach ($t['payments'] as $tx) {
                $id = $tx['id'] ?? '';
                if (! is_string($id) || $id === '' || isset($seen[$id])) {
                    throw new \InvalidArgumentException;
                }
                $seen[$id] = true;
                $d = $tx['regularPaymentDetails'] ?? [];
                $status = $tx['status'] ?? $d['status'] ?? null;
                // Delayed-capture and chargeback paths need additional capture/chargeback evidence.
                if (! in_array($status, ['APPROVED', 'PARTIALLY_REFUNDED', 'REFUNDED'], true) || ! is_array($d) || $d === [] || isset($d['authorizationDetails']) || ! empty($d['chargebacks'])) {
                    continue;
                }
                $minor = Money::toMinor($tx['amount']['amount'] ?? '', $currency);
                $at = $this->date($tx['createdDate'] ?? null);
                if ($minor < 0 || $at === null) {
                    throw new \InvalidArgumentException;
                }
                $captured += $minor;
                if ($paidAt === null || $at->greaterThan($paidAt)) {
                    $paidAt = $at;
                }
            }
            foreach ($t['refunds'] as $r) {
                foreach ($r['transactions'] ?? [] as $tx) {
                    if (($tx['refundStatus'] ?? null) !== 'SUCCEEDED') {
                        continue;
                    }
                    if (! isset($seen[$tx['paymentId'] ?? ''])) {
                        throw new \InvalidArgumentException;
                    }
                    $minor = Money::toMinor($tx['amount']['amount'] ?? '', $currency);
                    if ($minor < 0) {
                        throw new \InvalidArgumentException;
                    }
                    $refund += $minor;
                    $at = $this->date($r['createdDate'] ?? null);
                    if ($at !== null && ($refundAt === null || $at->greaterThan($refundAt))) {
                        $refundAt = $at;
                    }
                }
            }
            if ($amount < 0 || $refund > $amount) {
                throw new \InvalidArgumentException;
            }
        } catch (\InvalidArgumentException|\TypeError) {
            $amount = $refund = null;
            $paidAt = $refundAt = null;
        }
        $payment = match (true) {
            $amount === null => PaymentStatus::Unknown,($p['status'] ?? null) === 'CANCELED' => PaymentStatus::Cancelled,$paidAt !== null && $captured >= $amount && $refund === $amount && $amount > 0 => PaymentStatus::Refunded,$paidAt !== null && $captured >= $amount && $refund > 0 => PaymentStatus::PartiallyRefunded,$paidAt !== null && $captured >= $amount && ($p['paymentStatus'] ?? null) === 'PAID' => PaymentStatus::Paid,default => PaymentStatus::Pending
        };

        // Payment/refund record creation is not a documented settlement timestamp.
        // Keep the authoritative current status/amount, but never promote this to timed payment proof.
        $paidAt = $refundAt = null;

        return new NormalizedOrder(externalId: $this->str($p['id'] ?? null), reference: $this->str($p['number'] ?? null), firstName: $this->str($p['billingInfo']['contactDetails']['firstName'] ?? null), email: $this->str($p['buyerInfo']['email'] ?? null), phone: $this->str($p['billingInfo']['contactDetails']['phone'] ?? null), amountMinor: $amount, currency: $currency, paymentStatus: $payment, orderStatus: $this->str($p['status'] ?? null), placedAt: $this->date($p['createdDate'] ?? null), paidAt: $paidAt, cancelledAt: null, refundedAt: $refundAt, refundedMinor: $refund, providerUpdatedAt: $this->date($p['updatedDate'] ?? null));
    }
}
