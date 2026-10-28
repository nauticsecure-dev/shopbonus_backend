<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Reliability\ApiMonitoring;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MonitoringController extends Controller
{
    public function __invoke(Request $request, ApiMonitoring $monitoring): JsonResponse
    {
        $data = $request->validate(['minutes' => ['sometimes', 'integer', 'min:1', 'max:60']]);

        return response()->json(['data' => $monitoring->report((int) ($data['minutes'] ?? 5))], 200, ['Cache-Control' => 'no-store']);
    }
}
