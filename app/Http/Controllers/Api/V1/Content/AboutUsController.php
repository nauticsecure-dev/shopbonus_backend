<?php

namespace App\Http\Controllers\Api\V1\Content;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Services\Cms\AboutUsCmsService;
use App\Services\Cms\CmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Published About Us landing (structured CMS). */
class AboutUsController extends Controller
{
    public function __construct(private readonly AboutUsCmsService $aboutUs) {}

    public function show(Request $request): JsonResponse
    {
        $locale = $request->validate(['locale' => ['sometimes', Rule::in(CmsService::LOCALES)]])['locale'] ?? 'nl';
        $data = $this->aboutUs->published($locale);

        return response()->json(
            ['data' => $data ?? throw new ApiException('About Us page not found.', 404, 'not_found')],
            200,
            ['Cache-Control' => 'public, max-age=60'],
        );
    }
}
