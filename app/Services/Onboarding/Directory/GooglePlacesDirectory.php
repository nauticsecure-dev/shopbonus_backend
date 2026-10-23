<?php

namespace App\Services\Onboarding\Directory;

use App\Exceptions\ApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Google Places API (New): Autocomplete for search, Place Details for the
 * selected business. Field masks request only the fields card #16 lists.
 *
 * NOT VERIFIED against the live API (no key in this environment); the
 * request/response shapes follow Google's published Places API (New)
 * reference and are covered by faked HTTP tests.
 */
class GooglePlacesDirectory implements BusinessDirectory
{
    private const BASE = 'https://places.googleapis.com/v1';

    private const DETAILS_FIELDS = 'id,displayName,formattedAddress,addressComponents,websiteUri,internationalPhoneNumber,primaryType';

    public function __construct(private readonly int $cacheMinutes = 10) {}

    /** Read at call time so key rotation needs no container rebuild. */
    private function apiKey(): ?string
    {
        $key = config('shopbonus.onboarding.google_places_api_key');

        return is_string($key) && $key !== '' ? $key : null;
    }

    public function search(string $query, string $countryCode): array
    {
        $key = 'places:search:'.sha1(mb_strtolower(trim($query)).'|'.$countryCode);

        return Cache::remember($key, now()->addMinutes($this->cacheMinutes), function () use ($query, $countryCode) {
            $response = $this->send(fn (PendingRequest $http) => $http
                ->withHeaders(['X-Goog-FieldMask' => 'suggestions.placePrediction.placeId,suggestions.placePrediction.structuredFormat'])
                ->post(self::BASE.'/places:autocomplete', [
                    'input' => $query,
                    'includedRegionCodes' => [strtolower($countryCode)],
                    'languageCode' => 'nl',
                ]));

            return collect($this->arrayAt($response, 'suggestions'))
                ->map(fn (array $s) => $s['placePrediction'] ?? null)
                ->filter()
                ->map(fn (array $p) => [
                    'place_id' => (string) ($p['placeId'] ?? ''),
                    'name' => (string) Arr::get($p, 'structuredFormat.mainText.text', ''),
                    'description' => (string) Arr::get($p, 'structuredFormat.secondaryText.text', ''),
                ])
                ->filter(fn (array $p) => $p['place_id'] !== '')
                ->take(5)->values()->all();
        });
    }

    public function details(string $placeId): array
    {
        if (preg_match('/^[A-Za-z0-9_-]{10,300}$/', $placeId) !== 1) {
            throw new ApiException('Unknown place.', 404, 'not_found');
        }

        return Cache::remember('places:details:'.$placeId, now()->addMinutes($this->cacheMinutes), function () use ($placeId) {
            $response = $this->send(fn (PendingRequest $http) => $http
                ->withHeaders(['X-Goog-FieldMask' => self::DETAILS_FIELDS])
                ->get(self::BASE.'/places/'.$placeId, ['languageCode' => 'nl']));

            $components = collect($this->arrayAt($response, 'addressComponents'));
            $part = fn (string $type, string $form = 'longText') => $components
                ->first(fn (array $c) => in_array($type, $c['types'] ?? [], true))[$form] ?? null;

            $street = trim(($part('route') ?? '').' '.($part('street_number') ?? ''));

            return [
                'place_id' => (string) $response->json('id', $placeId),
                'name' => $response->json('displayName.text'),
                'formatted_address' => $response->json('formattedAddress'),
                'address_line' => $street !== '' ? $street : null,
                'postal_code' => $part('postal_code'),
                'city' => $part('locality') ?? $part('postal_town'),
                'country_code' => $part('country', 'shortText'),
                'website_url' => $response->json('websiteUri'),
                'phone' => $response->json('internationalPhoneNumber'),
                'category' => $response->json('primaryType'),
            ];
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function arrayAt(Response $response, string $key): array
    {
        $value = $response->json($key);

        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     */
    private function send(callable $call): Response
    {
        $apiKey = $this->apiKey();

        if ($apiKey === null) {
            throw new ApiException('Business search is not configured. Enter the details manually.', 503, 'provider_not_configured');
        }

        try {
            $response = $call(Http::withHeaders(['X-Goog-Api-Key' => $apiKey])->timeout(5)->connectTimeout(3));
        } catch (ConnectionException $e) {
            Log::warning('Google Places unreachable', ['exception_class' => $e::class]);

            throw new ApiException('Business search is temporarily unavailable. Enter the details manually.', 502, 'provider_error');
        }

        if ($response->status() === 404) {
            throw new ApiException('Unknown place.', 404, 'not_found');
        }

        if (! $response->successful()) {
            Log::warning('Google Places error', ['status' => $response->status()]);

            throw new ApiException('Business search is temporarily unavailable. Enter the details manually.', 502, 'provider_error');
        }

        return $response;
    }
}
