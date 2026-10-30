<?php

namespace App\Http\Controllers\Api\V1\Discovery;

use App\Http\Controllers\Controller;
use App\Services\Discovery\PublicDiscountCodes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicDiscountCodesController extends Controller
{
    public function __construct(private readonly PublicDiscountCodes $codes) {}

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        return response()->json([
            'data' => $this->codes->list((int) ($data['limit'] ?? 10), $data['search'] ?? null),
        ], 200, ['Cache-Control' => 'public, max-age=60']);
    }
}
