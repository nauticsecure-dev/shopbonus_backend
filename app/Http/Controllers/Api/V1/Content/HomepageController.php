<?php

namespace App\Http\Controllers\Api\V1\Content;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Services\Cms\CmsService;
use App\Services\Cms\HomepageCmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Published consumer homepage (structured landing). */
class HomepageController extends Controller
{
    public function __construct(private readonly HomepageCmsService $homepage) {}

    public function show(Request $request): JsonResponse
    {
        $locale = $request->validate(['locale' => ['sometimes', Rule::in(CmsService::LOCALES)]])['locale'] ?? 'nl';
        $data = $this->homepage->published($locale);

        return response()->json(
            ['data' => $data ?? throw new ApiException('Homepage not found.', 404, 'not_found')],
            200,
            ['Cache-Control' => 'public, max-age=60'],
        );
    }
}
