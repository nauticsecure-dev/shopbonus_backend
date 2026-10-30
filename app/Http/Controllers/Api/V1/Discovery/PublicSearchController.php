<?php

namespace App\Http\Controllers\Api\V1\Discovery;

use App\Http\Controllers\Controller;
use App\Services\Cms\CmsService;
use App\Services\Discovery\PublicSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PublicSearchController extends Controller
{
    public function __construct(private readonly PublicSearch $search) {}

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:80'],
            'locale' => ['sometimes', Rule::in(CmsService::LOCALES)],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:20'],
        ]);

        return response()->json([
            'data' => $this->search->suggest(
                (string) ($data['q'] ?? ''),
                $data['locale'] ?? 'nl',
                (int) ($data['limit'] ?? 8),
            ),
        ], 200, ['Cache-Control' => 'public, max-age=30']);
    }
}
