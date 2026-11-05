<?php

namespace App\Services\Funding;

use App\Exceptions\ApiException;
use App\Support\ExactJson;
use Illuminate\Support\Facades\Http;
use Throwable;

/** Fixed vendor origin, bounded responses, no redirects/retries or invented outgoing idempotency contract. */
class MoneybirdClient
{
    /** @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function request(string $admin, string $method, ?string $id = null, array $payload = []): array
    {
        if (! preg_match('/\A[1-9][0-9]{0,31}\z/D', $admin) || ($id !== null && ! preg_match('/\A[1-9][0-9]{0,31}\z/D', $id))
            || ! in_array($method, ['GET', 'POST', 'PATCH'], true) || ($method !== 'POST' && $id === null)) {
            throw new \InvalidArgumentException('Invalid Moneybird operation.');
        }
        if (! config('moneybird.enabled') || ! is_string(config('moneybird.token')) || config('moneybird.token') === '') {
            throw ApiException::conflict('Moneybird is not configured.', 'moneybird_unavailable');
        }
        if ($method === 'PATCH' && ! config('moneybird.allow_delivery')) {
            throw ApiException::conflict('Moneybird delivery is disabled.', 'moneybird_delivery_disabled');
        }
        $url = 'https://moneybird.com/api/v2/'.$admin.'/sales_invoices'.($id === null ? '' : '/'.$id).($method === 'PATCH' ? '/send_invoice' : '').'.json';
        try {
            $response = Http::acceptJson()->withToken(config('moneybird.token'))->withOptions([
                'allow_redirects' => false, 'proxy' => '', 'verify' => true, 'stream' => true, 'connect_timeout' => 3, 'timeout' => 15,
            ])->send($method, $url, $method === 'GET' ? [] : ['json' => $payload]);
            if ($response->status() !== ($method === 'POST' ? 201 : 200)) {
                throw new \RuntimeException('Unconfirmed provider response.');
            }
            $stream = $response->toPsrResponse()->getBody();
            $body = '';
            while (! $stream->eof() && strlen($body) <= 1048576) {
                $part = $stream->read(min(65536, 1048577 - strlen($body)));
                if ($part === '') {
                    break;
                }
                $body .= $part;
            }
            if (strlen($body) > 1048576 || ! $stream->eof()) {
                throw new \RuntimeException('Oversized provider response.');
            }
            $data = ExactJson::decode($body);
            if (! is_array($data) || array_is_list($data)) {
                throw new \RuntimeException('Invalid provider response.');
            }

            return $data;
        } catch (Throwable) {
            throw new ApiException('Moneybird did not confirm the operation. Reconcile before retrying any external write.', 502, 'moneybird_unconfirmed');
        }
    }
}
