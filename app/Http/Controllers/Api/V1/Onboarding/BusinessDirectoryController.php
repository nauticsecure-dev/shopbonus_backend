<?php

namespace App\Http\Controllers\Api\V1\Onboarding;

use App\Http\Controllers\Controller;
use App\Services\Onboarding\Directory\BusinessDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BusinessDirectoryController extends Controller
{
    public function __construct(private readonly BusinessDirectory $directory) {}

    public function search(Request $request): JsonResponse
    {
        $data = $request->validate([
            'query' => ['required', 'string', 'min:2', 'max:100'],
            'country' => ['sometimes', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],
        ]);

        return response()->json(['data' => $this->directory->search($data['query'], $data['country'] ?? 'NL')]);
    }

    public function show(string $placeId): JsonResponse
    {
        return response()->json(['data' => $this->directory->details($placeId)]);
    }
}
