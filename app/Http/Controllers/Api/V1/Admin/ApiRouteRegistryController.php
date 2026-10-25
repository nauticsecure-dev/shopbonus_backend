<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\ApiRouteRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ApiRouteRegistryController extends Controller
{
    public function index(Request $request, ApiRouteRegistry $registry): JsonResponse
    {
        $data = $request->validate(['access' => ['sometimes', Rule::in(['public', 'guest', 'authenticated', 'verified', 'admin', 'integration'])],
            'method' => ['sometimes', Rule::in(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1']]);
        $rows = collect($registry->entries())->when($data['access'] ?? null, fn ($c, $v) => $c->where('access', $v))
            ->when($data['method'] ?? null, fn ($c, $v) => $c->where('method', $v))->values();
        $page = (int) ($data['page'] ?? 1);
        $size = (int) ($data['per_page'] ?? 25);

        return response()->json(['data' => $rows->forPage($page, $size)->values()->all(), 'meta' => ['current_page' => $page, 'total' => $rows->count(),
            'per_page' => $size, 'last_page' => max(1, (int) ceil($rows->count() / $size))]]);
    }
}
