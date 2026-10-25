<?php

namespace App\Affiliate\Adapters;

use App\Affiliate\NetworkFailure;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Support\Facades\Http;
use SoapClient;
use Throwable;

/** WSDL serialization, but HTTP stays in Laravel (TLS, timeout, tests, isolated cookies). */
class TradeTrackerSoapClient extends SoapClient
{
    public const ENDPOINT = 'https://ws.tradetracker.com/soap/affiliate';

    private CookieJar $cookies;

    public function __construct()
    {
        $this->cookies = new CookieJar;
        parent::__construct(resource_path('integrations/tradetracker.wsdl'), [
            'location' => self::ENDPOINT,
            'exceptions' => true,
            'trace' => false,
            'cache_wsdl' => WSDL_CACHE_NONE,
            'features' => SOAP_SINGLE_ELEMENT_ARRAYS,
            // Native SOAP otherwise turns xsd:decimal commission amounts into floats.
            'typemap' => [[
                'type_ns' => 'http://www.w3.org/2001/XMLSchema', 'type_name' => 'decimal',
                'from_xml' => static fn (string $xml): string => html_entity_decode(trim(strip_tags($xml)), ENT_QUOTES | ENT_XML1, 'UTF-8'),
            ]],
        ]);
    }

    public function __doRequest(string $request, string $location, string $action, int $version, bool $oneWay = false): ?string
    {
        try {
            $response = Http::withOptions(['cookies' => $this->cookies, 'allow_redirects' => false])
                ->connectTimeout(5)->timeout(20)
                ->withHeaders(['SOAPAction' => '"'.$action.'"'])
                ->withBody($request, 'text/xml; charset=utf-8')->post(self::ENDPOINT);
        } catch (Throwable) {
            throw new NetworkFailure('connection_failed');
        }
        if (in_array($response->status(), [401, 403], true)) {
            throw new NetworkFailure('credentials_rejected', 'reauthentication_required');
        }
        // SOAP faults legitimately use HTTP 500; parse them without persisting fault text.
        if (! $response->successful() && $response->status() !== 500) {
            throw new NetworkFailure('http_'.$response->status());
        }
        $body = $response->body();
        if (strlen($body) > 4 * 1024 * 1024 || preg_match('/<!DOCTYPE|<!ENTITY/i', $body)) {
            throw new NetworkFailure('invalid_provider_response');
        }

        return $body;
    }
}
