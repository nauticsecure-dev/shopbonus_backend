<?php

namespace App\Services\Checkout;

use App\Exceptions\ApiException;
use App\Support\Money;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Minimal Mollie Payments API v2 client (create, get, refund). Amounts are
 * exact decimal strings ("10.00"), never floats. Test keys only unless
 * MOLLIE_ALLOW_LIVE=true (OPEN-FINANCIAL-LEGAL). Mollie webhooks carry only
 * the payment ID, so status always comes from get().
 */
class MollieClient
{
    public function available(): bool
    {
        $key = (string) config('shopbonus.payments.mollie.key');

        return str_starts_with($key, 'test_') || (str_starts_with($key, 'live_') && config('shopbonus.payments.mollie.allow_live'));
    }

    public function testMode(): bool
    {
        return str_starts_with((string) config('shopbonus.payments.mollie.key'), 'test_');
    }

    /**
     * @param  array<string, string>  $metadata
     * @return array{id: string, status: string, checkout_url: string|null}
     */
    public function createPayment(int $amountMinor, string $currency, string $description, string $redirectUrl, string $webhookUrl, array $metadata): array
    {
        $response = $this->send(fn (PendingRequest $http) => $http->post('/payments', [
            'amount' => ['currency' => $currency, 'value' => self::decimal($amountMinor)],
            'description' => mb_substr($description, 0, 255), 'redirectUrl' => $redirectUrl, 'webhookUrl' => $webhookUrl, 'metadata' => $metadata,
        ]));

        return ['id' => (string) $response['id'], 'status' => (string) $response['status'], 'checkout_url' => $response['_links']['checkout']['href'] ?? null];
    }

    /** @return array{id: string, status: string, amount_minor: int, currency: string, metadata: array<string, mixed>} */
    public function getPayment(string $id): array
    {
        $r = $this->send(fn (PendingRequest $http) => $http->get('/payments/'.rawurlencode($id)));

        return ['id' => (string) $r['id'], 'status' => (string) $r['status'], 'amount_minor' => self::minor((string) $r['amount']['value']),
            'currency' => (string) $r['amount']['currency'], 'metadata' => (array) ($r['metadata'] ?? [])];
    }

    /** @return array{id: string, status: string} */
    public function refund(string $paymentId, int $amountMinor, string $currency, string $description): array
    {
        $r = $this->send(fn (PendingRequest $http) => $http->post('/payments/'.rawurlencode($paymentId).'/refunds', [
            'amount' => ['currency' => $currency, 'value' => self::decimal($amountMinor)], 'description' => mb_substr($description, 0, 255),
        ]));

        return ['id' => (string) $r['id'], 'status' => (string) $r['status']];
    }

    public static function decimal(int $minor): string
    {
        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function minor(string $decimal): int
    {
        return Money::toMinor($decimal, 'EUR');
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     * @return array<string, mixed>
     */
    private function send(callable $call): array
    {
        if (! $this->available()) {
            throw ApiException::conflict('Online payment is not available right now.', 'payments_unavailable');
        }

        try {
            $response = $call(Http::baseUrl((string) config('shopbonus.payments.mollie.base_url'))
                ->withToken((string) config('shopbonus.payments.mollie.key'))->acceptJson()->timeout(15)->connectTimeout(5));
        } catch (ConnectionException) {
            throw new ApiException('The payment provider could not be reached. Please try again.', 502, 'payment_provider_unavailable');
        }

        if (! $response->successful()) {
            throw new ApiException('The payment provider refused the request.', 502, 'payment_provider_error', ['provider_status' => $response->status()]);
        }

        return (array) $response->json();
    }
}
