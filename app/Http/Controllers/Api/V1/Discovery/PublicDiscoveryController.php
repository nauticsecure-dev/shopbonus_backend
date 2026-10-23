<?php

namespace App\Http\Controllers\Api\V1\Discovery;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Services\Discovery\PublicCatalog;
use App\Services\Discovery\PublicProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public discovery (Trello #18): anonymous, read-only, published data only.
 */
class PublicDiscoveryController extends Controller
{
    public function __construct(private readonly PublicCatalog $catalog) {}

    public function categories(): JsonResponse
    {
        $keys = array_keys(config()->array('shopbonus.discovery.categories'));
        $data = array_map(fn (string $key) => ['key' => $key, 'label' => PublicCatalog::categoryLabels($key)], $keys);

        return response()->json(['data' => $data]);
    }

    public function webshops(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'category' => ['sometimes', 'nullable', Rule::in(array_keys(config('shopbonus.discovery.categories')))],
            'has_offers' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', Rule::in(['relevance', 'name', 'newest'])],
            'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.config('shopbonus.discovery.max_per_page')],
        ]);

        return response()->json($this->catalog->webshops($this->typed($filters, ['has_offers'])));
    }

    public function webshop(string $slug): JsonResponse
    {
        return response()->json(['data' => $this->catalog->webshop($slug) ?? throw new ApiException('This webshop is not available.', 404, 'webshop_not_found')]);
    }

    public function logo(string $slug): Response
    {
        $profile = $this->catalog->logo($slug) ?? throw new ApiException('Logo not found.', 404, 'not_found');

        return Storage::disk(PublicProfileService::DISK)->response($profile->logo_path, 'logo', [
            'Content-Type' => (string) $profile->logo_mime,
            'Cache-Control' => 'public, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
        ], 'inline');
    }

    public function offers(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'category' => ['sometimes', 'nullable', Rule::in(array_keys(config('shopbonus.discovery.categories')))],
            'webshop' => ['sometimes', 'nullable', 'string', 'max:80'],
            'available' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', Rule::in(['newest', 'ending_soon'])],
            'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.config('shopbonus.discovery.max_per_page')],
        ]);

        return response()->json($this->catalog->offers($this->typed($filters, ['available'])));
    }

    public function offer(string $slug): JsonResponse
    {
        return response()->json(['data' => $this->catalog->offer($slug) ?? throw new ApiException('This offer is not available.', 404, 'offer_not_found')]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  list<string>  $booleans
     * @return array<string, mixed>
     */
    private function typed(array $filters, array $booleans): array
    {
        foreach ($booleans as $key) {
            if (isset($filters[$key])) {
                $filters[$key] = filter_var($filters[$key], FILTER_VALIDATE_BOOLEAN);
            }
        }
        foreach (['page', 'per_page'] as $key) {
            if (isset($filters[$key])) {
                $filters[$key] = (int) $filters[$key];
            }
        }

        return $filters;
    }
}
