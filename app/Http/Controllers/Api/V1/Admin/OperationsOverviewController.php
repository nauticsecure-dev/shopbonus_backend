<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Reliability\Operations;
use Illuminate\Http\JsonResponse;

class OperationsOverviewController extends Controller
{
    public function __invoke(Operations $operations): JsonResponse
    {
        return response()->json(['data' => $operations->report()], 200, ['Cache-Control' => 'no-store']);
    }
}
