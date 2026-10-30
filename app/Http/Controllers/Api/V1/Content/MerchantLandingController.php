<?php

namespace App\Http\Controllers\Api\V1\Content;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Services\Cms\CmsService;
use App\Services\Cms\MerchantLandingCmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Published webshop-owner landing (structured CMS). */
class MerchantLandingController extends Controller
{
    public function __construct(private readonly MerchantLandingCmsService $landing) {}

    public function show(Request $request): JsonResponse
    {
        $locale = $request->validate(['locale' => ['sometimes', Rule::in(CmsService::LOCALES)]])['locale'] ?? 'nl';
        $data = $this->landing->published($locale);

        return response()->json(
            ['data' => $data ?? throw new ApiException('Merchant landing not found.', 404, 'not_found')],
            200,
            ['Cache-Control' => 'public, max-age=60'],
        );
    }
}
